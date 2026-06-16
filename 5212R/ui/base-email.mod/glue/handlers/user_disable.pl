#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: user_disable.pl
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
  use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Email;
use Sauce::Service;
use Sauce::Util;

&debug_msg("Startup");

my $Access = $Email::ACCESS;
my $postfix_suspended_users = '/etc/postfix/suspended_users';

my $cce = new CCE;
$cce->connectfd();

my $oid = $cce->event_oid();

# Get system object:
my ($sysoid) = $cce->find('System');
my ($ok, $System) = $cce->get($sysoid);
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

# Default MTA:
my $MTA = 'postfix';
if ($System->{'MTA'} ne '') {
    $MTA = lc($System->{'MTA'});
}

my ($ok, $System_Email) = $cce->get($sysoid, 'Email');
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

my ($ok, $user) = $cce->get($oid);
my ($ok, $email) = $cce->get($oid, "Email");
my ($ok, $user_disk) = $cce->get($oid, "Disk");

my $group = $user->{'site'};
my $username = $user->{'name'};

my @oid = $cce->find('Vsite', { 'name' => $group });
my ($ok, $domain) = $cce->get($oid[0]);
my ($ok, $domain_disk) = $cce->get($oid[0], "Disk");

&debug_msg("Processing User $username - group: $group - domain_disk-Status: " . ($domain_disk->{'quota'} || 0) . " Over-Quota-Status: " . $user_disk->{over_quota} . "\n");

my $vsite_overquota = 0;
if ($group ne "" && defined $domain_disk->{'used'} && defined $domain_disk->{'quota'} && $domain_disk->{'used'} >= $domain_disk->{'quota'}) {
  $vsite_overquota = 1;
}

my $virtualsite = $domain->{'fqdn'} || '';

my @user_aliases = $cce->scalar_to_array($email->{'aliases'});
push(@user_aliases, $username);

my @server_aliases = $cce->scalar_to_array($domain->{'mailAliases'});
push(@server_aliases, $virtualsite);

my @emailList;
if (($user->{'emailDisabled'} eq "1") || ($user_disk->{over_quota} eq "1") || ($vsite_overquota eq "1")) {
    my %seen_emails; # Track unique emails
    foreach my $server (@server_aliases) {
        foreach my $user_name (@user_aliases) {
            if ($user_name && $server) {
                my $email = $user_name . '@' . $server;
                unless ($seen_emails{$email}) {
                    push(@emailList, $email);
                    $seen_emails{$email} = 1;
                }
            }
        }
    }
}

@emailList = nonDuplicatedArray(@emailList);
&debug_msg("Email list for $username: " . join(", ", @emailList));

# Build access_list for both files
my $access_list = '';
my $suspended_list = '';

if (@emailList) {
    foreach my $entry (@emailList) {
        if (($user_disk->{over_quota} eq "1") || ($vsite_overquota eq "1")) {
            &debug_msg("User is over quota! (user_over_quota: $user_disk->{over_quota}|vsite_over_quota: $vsite_overquota)");
            $access_list .= "$entry\t\tERROR:5.2.2:550 User is over quota\n";
            $suspended_list .= "$entry\t\t550 5.2.2 User is over quota\n";
        }
        else {
            &debug_msg("User is unknown!");
            $access_list .= "$entry\t\tERROR:5.1.1:550 User unknown\n";
            $suspended_list .= "$entry\t\tREJECT\n";
        }
    }
}

# Update /etc/mail/access
if (!edit_block($Access,
    "### Start Block Email for User: $username on Virtual Site: $virtualsite ###",
    $access_list,
    "### END Block Email for User: $username on Virtual Site: $virtualsite ###")) {
    $cce->warn('[[base-email.cantEditFile]]', { 'file' => $Access });
    $cce->bye('FAIL');
    exit(1);
}
&debug_msg("Editing $Access done!");

# Update /etc/postfix/suspended_users if it exists
if (-f $postfix_suspended_users) {
    &debug_msg("File $postfix_suspended_users exists.");
    if (!edit_block($postfix_suspended_users,
        "### Start Block Email for User: $username on Virtual Site: $virtualsite ###",
        $suspended_list,
        "### END Block Email for User: $username on Virtual Site: $virtualsite ###")) {
        $cce->warn('[[base-email.cantEditFile]]', { 'file' => $postfix_suspended_users });
        $cce->bye('FAIL');
        exit(1);
    }
    &debug_msg("Editing $postfix_suspended_users done!");
    system("postmap /etc/postfix/suspended_users");

    if (($System_Email->{enableSMTP}) || ($System_Email->{enableSMTPS})) {
        &debug_msg("Restarting MTA.");
        Sauce::Service::service_run_init($MTA, 'restart');
    }    
}

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

sub nonDuplicatedArray {
    my @Duplicated = @_;
    my %seen = ();
    my (@NonDuplicatedArray, @Unique);
    @Unique = grep { ! $seen{$_}++ } @Duplicated;
    @NonDuplicatedArray = sort(@Unique);
    return @NonDuplicatedArray;
}

sub edit_block {
    my ($file, $start_delim, $content, $end_delim) = @_;
    
    # Read the file
    my @lines = ();
    if (-e $file) {
        open(my $fh, '<', $file) or return 0;
        @lines = <$fh>;
        close($fh);
    }

    # Remove trailing newlines from input lines
    chomp(@lines);

    # Find and replace the block
    my @new_lines = ();
    my $in_block = 0;
    my $block_found = 0;

    foreach my $line (@lines) {
        if ($line eq $start_delim) {
            $in_block = 1;
            $block_found = 1;
            if ($content ne '') {
                push @new_lines, $start_delim;
                push @new_lines, split(/\n/, $content);
            }
            next;
        }
        if ($line eq $end_delim && $in_block) {
            if ($content ne '') {
                push @new_lines, $end_delim;
            }
            $in_block = 0;
            next;
        }
        if (!$in_block) {
            push @new_lines, $line;
        }
    }

    # If block wasn't found and content is non-empty, append new block
    if (!$block_found && $content ne '') {
        push @new_lines, $start_delim;
        push @new_lines, split(/\n/, $content);
        push @new_lines, $end_delim;
    }

    # Write back to file
    open(my $fh, '>', $file) or return 0;
    print $fh "$_\n" for @new_lines;
    close($fh);
    return 1;
}

# Debug:
sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2016-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2016-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright 2006, Brian Smith, NuOnce Networks, Inc.
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#    notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#    notice, this list of conditions and the following disclaimer in 
#    the documentation and/or other materials provided with the 
#    distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#    contributors may be used to endorse or promote products derived 
#    from this software without specific prior written permission.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
# POSSIBILITY OF SUCH DAMAGE.
# 
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
#