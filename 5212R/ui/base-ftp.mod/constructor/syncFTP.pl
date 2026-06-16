#!/usr/bin/perl -I. -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/ftp
# $Id: syncFTP.pl

use Sauce::Util;
use Sauce::Config;
use Sauce::Service;
use ftp;
use CCE;

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $obj) = $cce->get($oids[0], 'Ftp');
unless ($ok and $obj) {
    $cce->bye('FAIL');
    exit 1;
}

$need_condrestart = '0';

# Check if /etc/proftpd.conf has the BlueOnyx provisions:
$check_config = `cat /etc/proftpd.conf |grep '### BlueOnyx section:'|wc -l`;
chomp($check_config);
$check_config_PassivePorts = `cat /etc/proftpd.conf |grep 'PassivePorts'|wc -l`;
chomp($check_config_PassivePorts);
if ((($check_config eq "0") || ($check_config_PassivePorts lt '2')) && (-f "/usr/sausalito/configs/proftpd/proftpd.conf")) {
    system("cp /usr/sausalito/configs/proftpd/proftpd.conf /etc/proftpd.conf");
    $need_condrestart++;
}

# Check if /etc/pam.d/proftpd has the BlueOnyx provisions:
$check_pam = `cat /etc/pam.d/proftpd |grep pam_shells.so|wc -l`;
chomp($check_pam);
if (($check_pam eq "1") && (-f "/usr/sausalito/configs/proftpd/proftpd")) {
    system("cp /usr/sausalito/configs/proftpd/proftpd /etc/pam.d/proftpd");
    $need_condrestart++;
}

# One or more configs were replaced above. Conditionally restart Proftpd:
if ($need_condrestart gt '0') {
    system("/usr/bin/systemctl condrestart proftpd");
}

$proftpd_enabled = Sauce::Service::service_get_init('proftpd', '3');

# Stop Proftpd if it's not enabled in the GUI:
if (($obj->{'enabled'} eq '0') && ($proftpd_enabled eq '1')) {
    # Disable Proftpd:
    Sauce::Service::service_toggle_init('proftpd', '0');
    Sauce::Service::service_run_init('proftpd', '0');
}

# Start Proftpd if it's enabled in the GUI, but not running:
if (($obj->{'enabled'} eq '1') && ($proftpd_enabled eq '0')) {
    # Enable Proftpd:
    Sauce::Service::service_toggle_init('proftpd', '1');
    Sauce::Service::service_run_init('proftpd', '1');    
}

$cce->bye('SUCCESS');
exit 0;

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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
