#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: virtual_server.pl
#
# handles email server virtualization for Vsites
# add hostname, ip address, and mail aliases to appropriate sendmail config
# files

use CCE;
use Email;

my $cce = new CCE;
$cce->connectfd();

my $vsite = $cce->event_object();
my $vsite_old = $cce->event_old();
my $vsite_new = $cce->event_new();

# Get the server's canonical FQDN and normalize:
my ($sysoid) = $cce->find('System');
my (undef, $sys) = $cce->get($sysoid);
my $server_fqdn = lc(join('.', grep { defined && length } ($sys->{hostname}, $sys->{domainname})));
$server_fqdn =~ s/\.$//;  # strip trailing dot just in case

# now handle local-host-names file
my %local_hosts;

# always remove "old" fqdn, it will get added back in below
# if necessary
if ($vsite_old->{fqdn}) {
    $local_hosts{$vsite_old->{fqdn}} = 0;
}

# Add the site hostname (unless it's the server's canonical FQDN)
if (!$vsite->{suspend} && $vsite->{fqdn}) {
    my $fqdn_norm = lc($vsite->{fqdn}); $fqdn_norm =~ s/\.$//;
    if ($fqdn_norm ne $server_fqdn) {
        $local_hosts{$vsite->{fqdn}} = 1;
    }
    else {
        # Make sure it gets removed if it was ever present
        $local_hosts{$vsite->{fqdn}} = 0;
    }
}

# mark all old aliases for removal first, aliases
# not being removed get added in again below
if ($vsite_old->{mailAliases}) {
    for my $alias ($cce->scalar_to_array($vsite_old->{mailAliases})) {
        $local_hosts{$alias} = 0;
    }
}

# Add new aliases, skipping the server's canonical FQDN
if (!$vsite->{suspend} && $vsite->{mailAliases}) {
    for my $alias ($cce->scalar_to_array($vsite->{mailAliases})) {
        my $alias_norm = lc($alias); $alias_norm =~ s/\.$//;
        next if $alias_norm eq $server_fqdn;
        $local_hosts{$alias} = 1;
    }
}

# Ensure canonical FQDN is always removed:
$local_hosts{$server_fqdn} = 0;

# edit the file
if (!Sauce::Util::editfile(&Email::SendmailCW, *edit_local_hosts, \%local_hosts)) {
    $cce->bye('FAIL', '[[base-email.cantEditLocalHosts]]');
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);


sub edit_access {
    my ($in, $out, $access_hash) = @_;

    my $begin = "# BEGIN VSite relays (do not edit anything between BEGIN and END)";
    my $end = "# END VSite relays (do not edit anything between BEGIN and END)";

    my $found = 0;
    
    while(<$in>) {
        if (/^# BEGIN VSite relays/) {
            # in our section
            $found = 1;
            print $out $begin, "\n";

            while(<$in>) {
                if (/^# END VSite relays/) {
                    last;
                }
                
                /^([^\s]+)/;
                my $relay = $1;
                if ($access_hash->{$relay} || !exists($access_hash->{$relay})) { 
                    # either supposed to be there or not ours
                    print $out $_;
                    delete($access_hash->{$relay});
                }
            } # end of while loop for the owned section

            # print anything left that's not there
            for my $relay (keys(%$access_hash)) {
                if ($access_hash->{$relay}) {
                    print $out "$relay\tRELAY\n";
                }
            }
            
            print $out $end, "\n";
        }
        else {
            # not in my section
            print $out $_;

            # make sure no duplicates get added
            /^([^\s]+)/;
            delete($access_hash->{$1});
        }
    }  # end of while loop to process the whole file

    # make sure we printed our section
    if (!$found) {
        print $out $begin, "\n";
        for my $relay (keys(%$access_hash)) {
            if ($access_hash->{$relay}) {
                print $out "$relay\tRELAY\n";
            }
        }
        print $out $end, "\n";
    }

    return 1;
}

sub edit_local_hosts {
    my ($in, $out, $local_hosts) = @_;

    my $found = 0;

    my $begin = "# BEGIN VSite Hosts (don't edit between BEGIN and END)";
    my $end = "# END VSite Hosts (don't edit between BEGIN and END)";
    while (<$in>) {
        if (/^# BEGIN VSite Hosts/) {
            $found = 1;
            print $out $begin, "\n";
            while (<$in>) {
                if (/^# END VSite Hosts/) {
                    last;
                }
                
                /^(.+)$/;
                my $fqdn = $1;
                if ($local_hosts->{$fqdn} || !exists($local_hosts->{$fqdn})) {
                    print $out $_;
                    delete($local_hosts->{$fqdn});
                }
            }

            # print anything that wasn't printed yet
            for my $thing (keys(%$local_hosts)) {
                if ($thing && $local_hosts->{$thing}) {
                    print $out $thing, "\n";
                }
            }

            print $out $end, "\n";
        }
        else {
            print $out $_;
        }
    }

    if (!$found) {
        print $out $begin, "\n";
        for my $thing (keys(%$local_hosts)) {
            if ($thing && $local_hosts->{$thing}) {
                print $out $thing, "\n";
            }
        }

        print $out $end, "\n";
    }

    return 1;
}

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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