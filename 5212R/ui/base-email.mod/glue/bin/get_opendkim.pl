#!/usr/bin/perl
# get_opendkim.pl

my $login = (getpwuid $>);
die "must run as root" if $login ne 'root';

use lib qw(/usr/sausalito/perl);
use utf8;
use CCE;
use Getopt::Long;
use JSON;
use JSON::XS;
use Data::Dumper;

GetOptions( 'domain=s' => \$group,
            'plain' => \$plain,
            'help' => \$help, 
            );

if (!$group) {
    print "\nUsage: get_opendkim.pl [ --domain ] [ --help ]\n";
    print " --domain     Name of the domain (i.e.: 'company.com' or 'all' for all DKIM records)\n";
    print " --plain      Output in text format. Output is in JSON format if no --plain specified\n";
    print " -h|--help    This help text\n\n";
    exit;
}

my $cce = new CCE;
$cce->connectuds();

$keydir = '/etc/opendkim/keys';
$SignTable = '/etc/opendkim/SigningTable';

if (! -f $SignTable) {
    print "Did not find a usable SignTable!\n";
    exit 1;    
}

if ($group eq 'all') {
    # Fetch DKIM info for all domains:
    $opendkim_out = {};
    $haveDomains = `ls -k1 $keydir|awk 'NF'`;
    chomp($haveDomains);
    @domains = split /\n/, $haveDomains;
    foreach my $dom (@domains) {
        if (-f "/etc/opendkim/keys/$dom/default.txt") {
            $fetch_TXT = `cat /etc/opendkim/keys/$dom/default.txt |tr '\n' ' ' | tr -d '"' |tr -s '[:blank:]' ' '|awk '{ print \$5 " "  \$6 " " \$7 }'`;
            chomp($fetch_TXT);
            $opendkim_out->{$dom}->{'default._domainkey'} = $fetch_TXT;

            $fetch_ST = `cat /etc/opendkim/SigningTable|grep -v ^# |awk 'NF'|grep "default._domainkey.$dom"|awk '{ print \$1 }'`;
            chomp($fetch_ST);
            @domain_lines = split /\n/, $fetch_ST;
            $opendkim_out->{$dom}->{'domains'} = $cce->array_to_scalar(@domain_lines);
        }
        else {
            print "Did not find a usable /etc/opendkim/keys/$group/default.txt!\n";
            exit 1;
        }
    }

    if ($plain eq '1') {
        # Generate plain-text output:
        foreach my $key ( keys %{$opendkim_out} ) {
            print $key . ":\n";
            @domain_lines = $cce->scalar_to_array($opendkim_out->{$key}->{'domains'});
            foreach my $x (@domain_lines) {
                print $x . "\n";
            }
            print "TXT:\t" . $opendkim_out->{$key}->{'default._domainkey'} . "\n\n";
        }
        exit(0);
    }
    else {
        # Generate JSON output:
        $coder = JSON::XS->new->utf8->allow_nonref;
        $out = $coder->encode ($opendkim_out);
        print $out . "\n";
        exit(0);
    }
}
else {
    # Fetch DKIM info for a single domain:
    if (! -d "$keydir/$group") {
        print "No OpenDKIM key directory of that name found!\n";
        exit 1;
    }
    else {
        $opendkim_out = {};
        if (-f "/etc/opendkim/keys/$group/default.txt") {
            $fetch_TXT = `cat /etc/opendkim/keys/$group/default.txt |tr '\n' ' ' | tr -d '"' |tr -s '[:blank:]' ' '|awk '{ print \$5 " "  \$6 " " \$7 }'`;
            chomp($fetch_TXT);
            $opendkim_out->{$group}->{'default._domainkey'} = $fetch_TXT;

            $fetch_ST = `cat /etc/opendkim/SigningTable|grep -v ^# |awk 'NF'|grep "default._domainkey.$group"|awk '{ print \$1 }'`;
            chomp($fetch_ST);
            @domain_lines = split /\n/, $fetch_ST;
            $opendkim_out->{$group}->{'domains'} = $cce->array_to_scalar(@domain_lines);

            if ($plain eq '1') {
                # Generate plain-text output:
                print $group . ":\n";
                foreach my $x (@domain_lines) {
                    print $x . "\n";
                }
                print "TXT:\t" . $opendkim_out->{$group}->{'default._domainkey'} . "\n\n";
                exit(0);
            }
            else {
                # Generate JSON output:
                $coder = JSON::XS->new->utf8->allow_nonref;
                $out = $coder->encode ($opendkim_out);
                print $out . "\n";
                exit(0);
            }
        }
        else {
            print "Did not find a usable /etc/opendkim/keys/$group/default.txt!\n";
            exit 1;
        }
    }
}

# 
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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