#!/usr/bin/perl -I/usr/sausalito/perl

use CCE;
$cce = new CCE;
$cce->connectuds();

# Root check:
$id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!\n";
    $cce->bye('FAIL');
    exit(1);
}

# Find all Vsites:
@vhosts = ();
(@vhosts) = $cce->findx('Vsite');

# Start sane:
$found = "0";

# Initialize arrays to store formatted data:
my @rows;

# Walk through all Vsites:
for $vsite (@vhosts) {
    # Start sane:
    $custom_php_ini = "";

    ($ok, $my_vsite) = $cce->get($vsite);
    ($ok, $xvsite_php) = $cce->get($vsite, 'PHP');

    $used_PHP = './.';

    if ($xvsite_php->{'enabled'} eq '1') {

        $php_implementation = '';
        if (($xvsite_php->{'enabled'} eq '1') && ($xvsite_php->{'suPHP_enabled'} eq '0') && ($xvsite_php->{'mod_ruid_enabled'} eq '0') && ($xvsite_php->{'fpm_enabled'} eq '0')) {
            $php_implementation = ' (DSO)';
        }
        elsif ($xvsite_php->{'suPHP_enabled'} eq '1') {
            $php_implementation = ' (suPHP)';
        }
        elsif ($xvsite_php->{'mod_ruid_enabled'} eq '1') {
            $php_implementation = ' (mod_ruid2)';
        }
        elsif ($xvsite_php->{'fpm_enabled'} eq '1') {
            $php_implementation = ' (FPM)';
        }

        if ($xvsite_php->{'version'} ne '') {
            $used_PHP = $xvsite_php->{'version'} . $php_implementation;
        }
    }

    push @rows, {
        name   => $my_vsite->{'name'},
        fqdn   => $my_vsite->{'fqdn'},
        ipaddr => $my_vsite->{'ipaddr'},
        php    => $used_PHP,
    };
}

# Sort rows by name with natural sorting
@rows = sort {
    my ($a_num) = $a->{name} =~ /(\d+)/;
    my ($b_num) = $b->{name} =~ /(\d+)/;
    # If both have numbers, compare them numerically
    if (defined $a_num && defined $b_num) {
        return $a_num <=> $b_num;
    }
    # Fallback to string comparison if no numbers or only one has numbers
    return $a->{name} cmp $b->{name};
} @rows;

# Calculate column widths:
my $name_width   = 5;
my $fqdn_width   = 5;
my $ipaddr_width = 5;

foreach my $row (@rows) {
    $name_width   = length($row->{name})   if length($row->{name}) > $name_width;
    $fqdn_width   = length($row->{fqdn})   if length($row->{fqdn}) > $fqdn_width;
    $ipaddr_width = length($row->{ipaddr}) if length($row->{ipaddr}) > $ipaddr_width;
}

# Print formatted rows:
foreach my $row (@rows) {
    printf "%-*s     %-*s     %-*s     %s\n",
        $name_width, $row->{name},
        $fqdn_width, $row->{fqdn},
        $ipaddr_width, $row->{ipaddr},
        $row->{php};
}

# tell cce everything is okay
$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2025 Team BlueOnyx, BLUEONYX.IT
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
