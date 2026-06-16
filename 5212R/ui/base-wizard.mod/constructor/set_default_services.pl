#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: set_default_services.pl
#
# Script to set certain services to default state when the system is not yet configured.

use CCE;
my $cce = new CCE;

$cce->connectuds();

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

$firstboot = "0";
my ($ok, $obj) = $cce->get($oids[0]);
if ($obj->{'isLicenseAccepted'} == "0") {
    $firstboot = "1";
}

if ($firstboot == "1") {
    ($ok) = $cce->update($oids[0], 'Email',{
            "queueTime" => "immediate",
            "masqAddress" => "",
            "enableSubmissionPort" => "0",
            "enableImap" => "1",
            "deniedUsers" => "",
            "smartRelay" => "",
            "acceptFor" => "",
            "enableSMTPS" => "0",
            "enableImaps" => "0",
            "enablePops" => "0",
            "relayFor" => "",
            "enableSMTP" => "1",
            "popRelay" => "0",
            "maxMessageSize" => "",
            "enablePop" => "1",
            "enableSMTPAuth" => "1",
            "deniedHosts" => ""
    });
    # YUM updater:
    ($ok) = $cce->update($oids[0], 'yum',{
            "yumUpdateTime" => "6:00",
            "y_force_update" => "2146835920",
            "yumguiEMAIL" => "1",
            "yumUpdateMO" => "1",
            "yumUpdateTH" => "1",
            "autoupdate" => "On",
            "yumUpdateTU" => "1",
            "yumUpdateWE" => "1",
            "yumUpdateSU" => "1",
            "yumguiEMAILADDY" => "admin",
            "yumUpdateFR" => "1",
            "yumUpdateSA" => "1"
    });
    # NTP:
    ($ok) = $cce->update($oids[0], 'Time',{
            "ntpAddress" => "pool.ntp.org",
            "deferCommit" => "0",
            "timeZone" => "US/Eastern",
            "ntpEnabled" => "1",
            "epochOffset" => "0"
    });
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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