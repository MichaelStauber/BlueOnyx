#!/usr/bin/perl
# get_web_stats.pl

my $login = (getpwuid $>);
die "must run as root" if $login ne 'root';

use lib qw(/usr/sausalito/perl);
use utf8;
use CCE;
use Getopt::Long;
use JSON;
use JSON::XS;
use Data::Dumper;

GetOptions( 'group=s' => \$group, 
            'help' => \$help, 
            );

if (!$group) {
    print "\nUsage: get_web_stats.pl [ --group ] [ --help ]\n";
    print " --group\       Group of Vsite (i.e.: 'site1' or 'server')\n";
    print " -h|--help    This help text\n\n";
    exit;
}

my $cce = new CCE;
$cce->connectuds();

if ($group eq 'server') {
    $logdir = '/home/.sites/server/logs/';
}
else {
    (@oids) = $cce->find('Vsite', { 'name' => $group });
    if ($#oids == -1) {
        print "No Vsite(s) found which have the group name " . $group . "\n\n";
        exit(1);
    }
    else {
        ($ok, $vsite) = $cce->get($oids[0]);
        $logdir = $vsite->{basedir} . '/var/logs/';
    }
}

if (-d $logdir) {
    $data = `find $logdir -name web.json`;
    chomp($data);
    $data =~ s/$logdir//g;
    @web_logs = split /\n/, $data;
    @web_logs_split = ();
    $web_logs_out = {};
    foreach my $x (@web_logs) {
        if ($x eq 'web.json') {
            $web_logs_out->{'actual'} = $x;
        }
        else {
            @web_logs_split = split /\//, $x;
            if (($web_logs_split[0]) || ($web_logs_split[1]) || ($web_logs_split[2]) || ($web_logs_split[3])) {
                $web_logs_out->{$web_logs_split[0]}->{$web_logs_split[1]}->{$web_logs_split[2]} = $web_logs_split[3];
            }
        }
    }

    $coder = JSON::XS->new->utf8->allow_nonref;
    $out = $coder->encode ($web_logs_out);
    print $out . "\n";
    exit(0);
}
else {
    print "No Server or Vsite Log directory found!\n";
    exit 1;
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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