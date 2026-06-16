#!/usr/bin/perl
# $Id: pkg_prepare.pl
#
# author: asun@cobalt.com
#
# this prepares a package for installation. it untars it into a known
# location, installs the relevant package information, and then restarts
# the web server.

use lib '/usr/sausalito/perl';
use SWUpdate;
use CCE;
use Sauce::Service;

use vars qw($opt_f $opt_u $opt_i $opt_R);
use Getopt::Std;
getopts('iRf:u:');

my $download_dir = swupdate_tmpdir;
my $install_log = $download_dir . '/package.log';
my $ret = 0;

unless ($opt_f or $opt_u) {
    print <<EOF;
Usage: $0 [-i] [-R] -f <package_path>
       $0 [-i] [-R] -u <package_url>

Option      Description
-i      Install
-R      Do not reboot automatically

EOF

    exit -1;
}

# make sure the directory is there
`mkdir -p $download_dir` unless -d $download_dir;
`chmod -R 0700 $download_dir`;

# remove the log file if it's a symbolic link.
unlink($install_log) if -l $install_log;
open(LOG, ">$install_log") or die "Cannot open log file.\n";

# initiate a cce connection and tell it that we're preparing to 
# install the file
my $cce = new CCE;
$cce->connectuds;

my ($sysoid) = $cce->find('System');
my ($ok, $obj) = $cce->get($sysoid, 'SWUpdate');
my $sigp = $obj->{requireSignature};
$cce->set($sysoid, 'SWUpdate', { 'message' => '[[base-swupdate.initializing]]', 'progress' => '0' });
unless ($opt_f) {
    $opt_f = swupdate_tmpfile('prepare');
    print LOG "--- downloading ---\n";
    print LOG "$opt_u -> $opt_f:";
    $cce->set($sysoid, 'SWUpdate', { 'message' => "[[base-swupdate.dlPercent,file=$opt_u,percent=0]]", 'progress' => '0' });
    ($ret) = swupdate_download($opt_f, $opt_u, '', $cce);
    $cce->set($sysoid, 'SWUpdate', {'message' => "[[base-swupdate.cannotDownloadUrl]]", 'progress' => 100 }) if ($ret lt 0);
    print LOG " $ret\n";
}

my $packageOID;
if ($ret gt -1) {
    my $info;

    print LOG "--- unpacking ---\n";
    print LOG "$opt_f:";
    
    ($ret, $info) = swupdate_unpack($opt_f, $cce, $sysoid, $sigp);
    if ($ret gt 0) {
        Sauce::Service::service_run_init('admserv', 'reload', 'nobg');
        $cce->set($sysoid, 'SWUpdate', {'uiCMD' => "packageOID=$ret" });
        $packageOID = $ret if ($opt_i);
    }
    print LOG " $ret ($info)\n";
    $ret = $ret gt 0 ? 0 : -1;
}
$cce->bye('SUCCESS');
close(LOG);
unlink($opt_f);

if ($packageOID && $opt_R) {
    $ret = system("/usr/sausalito/sbin/pkg_install.pl $packageOID -R")
}
elsif ($packageOID) {
    $ret = system("/usr/sausalito/sbin/pkg_install.pl $packageOID")
} 

exit $ret;

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