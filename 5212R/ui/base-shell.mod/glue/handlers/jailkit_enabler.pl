#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: jailkit_enabler.pl
#
# This starts the Service 'jailkit' on an as needed basis.
# When enabled, it also enables AM monitoring of it.
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Sauce::Util;

my $cce = new CCE;
$cce->connectfd();

@SysOID = $cce->find("System");
($AMoid) = $cce->find('ActiveMonitor');

($ok, $System) = $cce->get($SysOID[0]);
($ok, $Jailkit) = $cce->get($SysOID[0], 'Jailkit');

my $do_something = '0';
my @vsites = $cce->find('Vsite');
foreach my $oid (@vsites) {
    ($ok, my $vsite_shell) = $cce->get($oid, 'Shell');
    &debug_msg("Vsite Shell: $vsite_shell->{enabled}\n");
    if ($vsite_shell->{enabled} gt '0') {
        &debug_msg("Jailkit should be running.\n");
        $do_something = '1';
    }
}

if ($do_something eq '1') {
    &debug_msg("Enabling Jailkit as we're using it. status: $status\n");
    Sauce::Service::service_set_init('jailkit', 'on');
    Sauce::Service::service_run_init('jailkit', 'restart');

    # Update AM:
    ($ok) = $cce->set($AMoid, 'Jailkit',{
        'enabled' => '1',
        'monitor' => '1'
    });

    # Update Systen . Jailkit:
    ($ok) = $cce->set($SysOID[0], 'Jailkit',{
        'enabled' => '1'
    });

}
else {
    &debug_msg("Disabling Jailkit as we're not using it.\n");
    Sauce::Service::service_set_init('jailkit', 'off');
    Sauce::Service::service_run_init('jailkit', 'stop');

    # Update AM:
    ($ok) = $cce->set($AMoid, 'Jailkit',{
        'enabled' => '0',
        'monitor' => '0'
    });

    # Update Systen . Jailkit:
    ($ok) = $cce->set($SysOID[0], 'Jailkit',{
        'enabled' => '0'
    });


}

$cce->bye('SUCCESS');
exit(0);

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
# Copyright (c) 2018-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2018-2022 Team BlueOnyx, BLUEONYX.IT
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