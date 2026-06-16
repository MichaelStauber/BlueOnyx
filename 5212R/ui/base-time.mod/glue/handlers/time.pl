#!/usr/bin/perl 

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for virtual_host.pl\n");
}

use lib '/usr/sausalito/perl';
use CCE;

my $cce = new CCE(Namespace => 'Time');
$cce->connectfd();

&debug_msg("Startup of time.pl\n");

my $localtime = '/etc/localtime';
my $clock = '/etc/sysconfig/clock';
my $oid = $cce->event_oid();
my $time_obj = $cce->event_object();
my $old = $cce->event_old();

# Find out Platform
$BUILD = `grep -E '5209R' /etc/build |wc -l`;
chomp($BUILD);

if ($BUILD eq "1") {
    #
    ### Older BlueOnyx (before 5210R) - so we use NTPd:
    #
    $service = 'ntpd';
}
else {
    #
    ### Newer BlueOnyx (5210R and later), so we use Chrony:
    #
    $service = 'chronyd';
}

&debug_msg("Inside unless deferCommit\n");

# set the timezone first
my $zone = $time_obj->{timeZone};

# Obnoxious glibc UTC sign swap
if ($zone =~ /GMT\+\d+/) {
    $zone =~ s/\+/\-/;
}
elsif ($zone =~ /GMT\-\d+/) {
    $zone =~ s/\-/\+/;
}

my $link = '../usr/share/zoneinfo/' . $zone;
if ($zone and (readlink($localtime) ne $link)) {
    unlink('/etc/localtime');
    symlink($link, '/etc/localtime');
}

# update /etc/sysconfig/clock
my $fn = sub {
    my ($fin, $fout) = (shift,shift);
    my ($text) = (shift);

    while (<$fin>) {
        if (m/^ZONE/) {
            # print out the CCE maintained section
            print $fout "ZONE=\"$text\"\n";
        }
        else {
            print $fout $_;
        }
    }
    return 1;
};

if (!Sauce::Util::editfile($clock, $fn, $zone)) {
    $cce->warn("[[base-time.errorWritingConfFile]]");
}

# set the time if necessary. 
my $time = $time_obj->{epochTime};

if($old->{epochTime} && $time_obj->{epochOffset}) {
    $time = $time + (time() - $time_obj->{epochOffset});
    $cce->set($oid, 'Time', {'epochOffset' => 0});
}

if (($time ne $old->{epochTime}) || ($old->{deferCommit})) {
    `/usr/sausalito/sbin/epochdate $time`;
    # resync the hwclock with the time
    system ("/sbin/hwclock --utc --systohc > /dev/null");
    unlink("/etc/adjtime"); # get rid of any clock skew
    system ("/sbin/hwclock --utc --systohc > /dev/null");
    &debug_msg("Synchronizing HW-Clock: Done\n");
}

# Make sure this isn't running as LXC container *and* has a time-server configured:
$LXC_CT = `cat /etc/mtab | grep /proc/cpuinfo | grep ^lxcfs | wc -l`;
chomp($LXC_CT);
if ($LXC_CT gt 0) {
    # Restart Rsyslog:
    Sauce::Service::service_run_init('rsyslog', 'restart');
    &debug_msg("Early exit. This is an LXC Container. No NTPd/Chrony possible!\n");
    $cce->bye('SUCCESS');
    exit 0;
}

# reload service, if it's running, after the hw clock set
if ($time_obj->{ntpAddress}) {
    &debug_msg("Reloading Service NTPd\n");
    Sauce::Service::service_run_init($service, 'restart');
}

# Restart Rsyslog:
Sauce::Service::service_run_init('rsyslog', 'restart');

&debug_msg("End of time.pl reached.\n");

$cce->bye("SUCCESS");
exit 0;

#
### Subroutines:
#

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