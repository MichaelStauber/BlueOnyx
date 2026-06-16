#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: ntp.pl
#
# Configure and start/stop ntpd.
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for virtual_host.pl\n");
}

use CCE;
use Sauce::Service;
use Sauce::Util;
use Sauce::Validators;

# defines that should probably go elsewhere
my $NTPconf = '/etc/ntp.conf';
my $ntpd = 'ntpd';
my $tickers = '/etc/ntp/step-tickers';

my $cce = new CCE(Namespace => "Time");
$cce->connectfd();

my $time_obj = $cce->event_object();

# Find out Platform
$BUILD = `grep -E '5209R' /etc/build |wc -l`;
chomp($BUILD);

&debug_msg("Startup of time.pl.\n");

# Make sure this isn't running as LXC container *and* has a time-server configured:
$LXC_CT = `cat /etc/mtab | grep /proc/cpuinfo | grep ^lxcfs | wc -l`;
chomp($LXC_CT);
if ($LXC_CT gt 0) {
    &debug_msg("Early exit. This is an LXC Container. No NTPd/Chrony possible!\n");
    $cce->bye('SUCCESS');
    exit 0;
}

if ($BUILD eq "1") {
    #
    ### Older BlueOnyx (before 5210R) - so we use NTPd:
    #

    # set the ntp Address and toggle the ntp server based upon its existence
    my $ntpAddress = $time_obj->{ntpAddress};
    if ($ntpAddress) {
        # ntp server defined
        if (!Sauce::Validators::netaddr($ntpAddress)) {
            &debug_msg("Error: base-time.ntpAddress_invalid\n");
            $cce->baddata(0, 'ntpAddress', "[[base-time.ntpAddress_invalid]]");
            $cce->bye('FAIL');
            exit(1);
        }
        else {
            # NTP address is good, update the conf file 
            &debug_msg("Editing $NTPconf\n");
            if (!Sauce::Util::editfile($NTPconf, *update_ntp_conf, $ntpAddress)) { 
                $cce->warn("[[base-time.errorWritingConfFile]]");
            }
            # set the time on system startup
            `echo '$ntpAddress' > $tickers`;
        }
    }
}
else {

    #
    ### Newer BlueOnyx (5210R and later), so we use Chrony:
    #

    $ntpd = 'chronyd';
    $NTPconf = '/etc/chrony.conf';

    # set the ntp Address and toggle the ntp server based upon its existence
    my $ntpAddress = $time_obj->{ntpAddress};
    if ($ntpAddress) {
        # ntp server defined
        if (!Sauce::Validators::netaddr($ntpAddress)) {
            &debug_msg("Error: base-time.ntpAddress_invalid\n");
            $cce->baddata(0, 'ntpAddress', "[[base-time.ntpAddress_invalid]]");
            $cce->bye('FAIL');
            exit(1);
        }
        else {
            # NTP address is good, update the conf file 
            &debug_msg("Editing $NTPconf\n");
            if (!Sauce::Util::editfile($NTPconf, *update_chrony_conf, $ntpAddress)) { 
                $cce->warn("[[base-time.errorWritingConfFile]]");
            }
        }
    }
}

if ($time_obj->{ntpEnabled} eq '0') {
    &debug_msg("Disabling $ntpd\n");
    Sauce::Service::service_toggle_init($ntpd, '0');

    &debug_msg("Stopping $ntpd\n");
    service_run_init($ntpd, 'stop');

}
else {
    &debug_msg("Enabling $ntpd\n");
    Sauce::Service::service_toggle_init($ntpd, '1');
    &debug_msg("Restarting $ntpd\n");
    service_run_init($ntpd, 'restart');

}

&debug_msg("End of ntpd.pl reached.\n");

$cce->bye('SUCCESS');
exit 0;


#
### Subroutines:
#

sub update_ntp_conf {
    my ($fin, $fout, $ntpAddress) = @_;

    my $begin_servers = '# begin Cobalt Section';
    my $end_servers = '# end Cobalt Section';
    my $mcast_help = '# Uncomment the following line to use ntpd as a multicast client';

    my $found_mcast_help = 0;
    
    while (<$fin>) {

        if (/^$begin_servers$/ .. /^$end_servers$/) {
            # skip the servers section and re-add below
            next;
        }
        elsif (/^server(.*)iburst$/) {
            # Discard the stock ntpd.conf ntpd servers.
            next;
        }
        elsif (/^server\s+(.*)$/) {
            # Discard any other ntpd servers that are still configured.
            next;
        }
        elsif (/^$mcast_help$/) {
            $found_mcast_help = 1;
        }
        elsif (/^#*\s*multicastclient/) {
            if ($found_mcast_help) {
                # if the help msg is there, leave it as is
                print $fout $mcast_help, "\n";
                print $fout $_;
            }
            else {
                # add multi cast help message
                print $fout $mcast_help, "\n";

                # comment out multicastclient if not already
                if (!/^#/) {
                    print $fout '# ', $_;
                }
            }
        }
        else {
            # some other line, leave it there
            print $fout $_;
        }
    }

    # add servers section
    print $fout $begin_servers, "\n";
    if ($ntpAddress) {
        print $fout "server $ntpAddress\n";
        print $fout "server 0.pool.ntp.org\n";
        print $fout "server 1.pool.ntp.org\n";
        print $fout "server 2.pool.ntp.org\n";
        print $fout "server 3.pool.ntp.org\n";
    }
    print $fout $end_servers, "\n";

    return 1;
}

sub update_chrony_conf {
    my ($fin, $fout, $ntpAddress) = @_;

    my $begin_servers = '# begin Cobalt Section';
    my $end_servers = '# end Cobalt Section';

    while (<$fin>) {
        if (/^$begin_servers$/ .. /^$end_servers$/) {
            # skip the servers section and re-add below
            next;
        } 
        elsif (/^pool(.*)iburst$/) {
            # Discard the stock chrony.conf ntpd servers.
            next;
        }
        else {
            # some other line, leave it there
            print $fout $_;
        }
    }

    # add servers section
    print $fout $begin_servers, "\n";
    if ($ntpAddress) {
        print $fout "pool $ntpAddress\n";
        #print $fout "server 0.pool.ntp.org\n";
        #print $fout "server 1.pool.ntp.org\n";
        #print $fout "server 2.pool.ntp.org\n";
        #print $fout "server 3.pool.ntp.org\n";
    }
    print $fout $end_servers, "\n";

    return 1;
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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