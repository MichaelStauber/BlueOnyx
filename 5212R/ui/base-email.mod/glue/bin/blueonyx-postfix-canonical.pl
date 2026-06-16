#!/usr/bin/perl -I. -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: blueonyx-postfix-canonical.pl

use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Data::Dumper;
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

#
### Start:
#

$canonical_file = '/etc/postfix/canonical';
$vsite_suspend_file = '/etc/postfix/suspended_vsites';

$canonical_content = '';
$vsite_susp_content = '';

# Find all Vsites:
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');

# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, my $my_vsite) = $cce->get($vsite);
    &debug_msg("Processing Site: $my_vsite->{fqdn} \n");

    $fqdn = $my_vsite->{'fqdn'};
    $hostname = $my_vsite->{'hostname'};
    $domain = $my_vsite->{'domain'};
    $emailDisabled = $my_vsite->{'emailDisabled'};
    $suspend = $my_vsite->{'suspend'};

    @webAliases = $cce->scalar_to_array($my_vsite->{webAliases});
    @mailAliases = $cce->scalar_to_array($my_vsite->{mailAliases});

    &debug_msg("Checking if domain $domain is within mailAliases. Using it as base. \n");

    $terminator = $fqdn;
    if (in_array(\@mailAliases, $domain)) {
        &debug_msg("Hostname $domain is within mailAliases. Using it as base. \n");
        $terminator = $domain;
    }

    $filler = $fqdn . ' ';

    # If Vsite is suspended or has email disabled (or both) add it to /etc/postfix/suspended_vsites:
    if (($suspend eq '1') || ($emailDisabled eq '1')) {
        $vsite_susp_content .= $fqdn . '        REJECT' . "\n";
    }

    foreach my $x (@mailAliases) {

        # If Vsite is suspended or has email disabled (or both) add it to /etc/postfix/suspended_vsites:
        if (($suspend eq '1') || ($emailDisabled eq '1')) {
            $vsite_susp_content .= $x . '       REJECT' . "\n";
        }

        if ($x eq $domain) { next; }
        $filler .= $x . ' ';
    }

    chomp($filler);
    $canonical_content .= $filler . ' ' . '@' . $terminator . "\n";

}

# Write out /etc/postfix/canonical:
open(my $fh, '>', $canonical_file);
print $fh "# This file is automatically regenerated. Do not edit!\n\n";
print $fh $canonical_content;
print $fh "\n\n";
close $fh;
system("chmod 644 $canonical_file");
system("chown root:root $canonical_file");

# Write out /etc/postfix/suspended_vsites:
open(my $fh, '>', $vsite_suspend_file);
print $fh "# This file is automatically regenerated. Do not edit!\n\n";
print $fh $vsite_susp_content;
print $fh "\n\n";
close $fh;
system("chmod 644 $vsite_suspend_file");
system("chown root:root $vsite_suspend_file");

if ($DEBUG) {
    print "New $canonical_file:\n";
    print "-----------------------------------\n";
    print $canonical_content;
    print "New $vsite_suspend_file:\n";
    print "-----------------------------------\n";
    print $vsite_susp_content;
}

#
### Fin:
#

$cce->bye("SUCCESS");
exit 0;

#
### Subs:
#

sub in_array {
     my ($arr,$search_for) = @_;
     my %items = map {$_ => 1} @$arr; # create a hash out of the array values
     return (exists($items{$search_for}))?1:0;
 }

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        if ($DEBUG eq '1') {
            setlogsock('unix');
            openlog($0,'','user');
            syslog('info', "$ARGV[0]: $msg");
            closelog;
        }
        else {
            print $msg;
        }
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#     notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#     notice, this list of conditions and the following disclaimer in 
#     the documentation and/or other materials provided with the 
#     distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#     contributors may be used to endorse or promote products derived 
#     from this software without specific prior written permission.
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