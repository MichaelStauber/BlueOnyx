#!/usr/bin/perl

# Dovecot mbox to maildir and maildir to mbox convert for all users

use Unix::PasswdFile;
use Getopt::Long;
use File::Find; 

# Debugging switch (0|1|2):
# 0 = off
# 1 = log to syslog
# 2 = log to screen
#
$DEBUG = "2";
if ($DEBUG) {
    if ($DEBUG eq "1") {
        use Sys::Syslog qw( :DEFAULT setlogsock);
    }
}

# Show header:
&header;

#
### Check if we are 'root':
#
&root_check;

GetOptions(
    'mbox'        => sub { Mbox() },
    'maildir'     => sub { Maildir() },
    'help|h|?'    => sub { help() },
) or &help();

&help;

exit(0);

#
### Subs:
#

sub Mbox {
    $pw = new Unix::PasswdFile "/etc/passwd";
    foreach $user ($pw->users) {
        $mbox = $pw->home($user) . '/mbox';
        $userdir = $pw->home($user);
        $maildir = $pw->home($user) . '/Maildir';
        $uid = $pw->uid($user);
        $gid = $pw->gid($user);
        if (-d $maildir) {
            &debug_msg("Converting Maildir of user $user to Mbox \n");
            if (-f $mbox) {
                &debug_msg("Making backup of original mbox file as $mbox.bak\n");
                system("mv $mbox $mbox.bak");
                system("chown $uid:$gid $mbox.bak");
                system("chmod 0600 $mbox.bak");
            }
            @files = ();
            find({wanted => \&findfiles,bydepth => 3},$maildir); 
            $cmd="formail";
            $i = '0';
            foreach $file (@files) {
                next unless -f $file; # skip non-regular files
                next unless -s $file; # skip empty files
                next unless -r $file; # skip unreadable files
                $file =~ s/'/'"'"'/;  # escape ' (single quote)
                $run = "cat '$file' | $cmd >>'$mbox'";
                $i++;
                system($run) == 0 or warn "cannot run \"$run\".";
            }
            if (-f $mbox) {
                system("chown $uid:$gid $mbox");
                system("chmod 0600 $mbox");
            }
            &debug_msg("Processed $i messages.\n");
        }
    }
    exit(0);
}

#
### Subs:
#

sub findfiles {
    if ((($File::Find::name =~ /cur/) || ($File::Find::name =~ /new/)) && ($File::Find::name !~ /tmp/)) {
        if (-f $File::Find::name) {
            push @files, $File::Find::name;
        }
    }
} 

sub Maildir {
    $pw = new Unix::PasswdFile "/etc/passwd";
    foreach $user ($pw->users) {
        $mbox = $pw->home($user) . '/mbox';
        $maildir = $pw->home($user) . '/Maildir';
        $uid = $pw->uid($user);
        $gid = $pw->gid($user);
        if ((-d $maildir) && (! -d "$maildir.bak")) {
            &debug_msg("Making backup of original Maildir file as $maildir.bak\n");
            system("mv $maildir $maildir.bak");
        }
        if ((-d $maildir) && (-d "$maildir.bak")) {
            system("rm -Rf $maildir");
        }
        if (-f $mbox) {
            &debug_msg("Converting Mbox of user $user to Maildir \n");
            system("/usr/sausalito/sbin/mb2md.pl -s $mbox -d $maildir/");
            system("chown -R $uid:$gid $maildir");
        }
    }
    exit(0);
}

sub header {
    print "┌──────────────────────────────────────────────────────────────┐\n";
    print "│ BlueOnyx Mbox/Maildir & Maildir/Mbox converter               │\n";
    print "└──────────────────────────────────────────────────────────────┘\n\n";
    $header_sent++;
}

sub help {
    $error = shift || "";
    if ($header_sent eq '0') {
        &header;
    }
    if ($error) {
        print "ERROR: $error\n\n";
    }
    print "usage:     $0 [OPTIONS]\n";
    print "\n";
    print "Example: $0 --mbox\n";
    print "Example: $0 --maildir\n";
    print "\n";
    print "       --mbox     Converts all mailboxes to mbox format\n";
    print "       --maildir  Converts all mailboxes to maildir format\n";
    print "       -h|--help  This help text\n\n";
    exit(1);
}

sub root_check {
    my $id = `id -u`;
    chomp($id);
    if ($id ne "0") {
        &help("$0 must be run by user 'root'!");
    }
}

sub debug_msg {
    if ($DEBUG eq "1") {
        $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
    if ($DEBUG eq "2") {
        my $msg = shift;
        print $msg;
    }
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#  notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#  notice, this list of conditions and the following disclaimer in 
#  the documentation and/or other materials provided with the 
#  distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#  contributors may be used to endorse or promote products derived 
#  from this software without specific prior written permission.
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