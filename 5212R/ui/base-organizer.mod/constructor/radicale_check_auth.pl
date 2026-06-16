#!/usr/bin/perl -I/usr/sausalito/perl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

#
#### No configureable options below!
#

use CCE;

my $cce = new CCE;
$cce->connectuds();

# Find System Object:
my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

# Get System object:
my ($ok, $System) = $cce->get($oids[0]);
if (!$ok) {
    $cce->bye('FAIL');
    exit(1);
}

# Version Check for correct Python path:
$have_htpasswd = '0';
if ($System->{'productBuild'} eq "5212R") {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.12/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.12/site-packages/radicale/auth/htpasswd.py';
}
elsif ($System->{'productBuild'} eq "5211R") {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.9/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.9/site-packages/radicale/auth/htpasswd.py';
}
else {
    $have_htpasswd = '1';
    $python_switcher = `cat /usr/lib/python3.8/site-packages/radicale/auth/htpasswd.py|grep "Iterate through htpasswd credential file"|wc -l`;
    chomp($python_switcher);
    $python_switch_file = '/usr/lib/python3.8/site-packages/radicale/auth/htpasswd.py';
}

#
### Python Switch of Auth module:
#

if (($have_htpasswd eq '1') && ($python_switcher eq '1')) {
    # Need to update Radicale CCE settings to write out correct configs:
    &debug_msg("Updating Radicale configs.\n");
    ($ok) = $cce->set($oids[0], 'Radicale', { 'force_update' => time() });
}
else {
    &debug_msg("Radicale configs are fine.\n");
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
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
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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
