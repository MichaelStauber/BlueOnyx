#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: generate_login_list.pl

# This handler polls a server for running processes and stores that info into CCE.

use CCE;
my $cce = new CCE;

$DEBUG = 0;

my $cce = new CCE('Domain' => 'base-console');

if ($DEBUG == 0) {
    $cce->connectfd();
}
else {
    $cce->connectuds(); # only for debugging
}

if ($DEBUG == 0) {
    ($sys_oidnode) = $cce->find('SOL_Console');
    ($ok, $MainNode) = $cce->get($sys_oidnode);
}

# Location of the temporary-dump:
$psdump = "/tmp/console.login-list";

# We are starting sane:
$bad_info_detected = '0';

# Data handling:
&main;

# If we detect bad info, we will try again. Up to three times.
# Then we give up as something is barfed up badly.
if ($bad_info_detected < '3') {
    &main;
}
else {
    exit 1;
}

$cce->bye('SUCCESS');
exit(0);

##
# Subs:
##

sub main {

    # Generate dump:
    system("/usr/bin/last > $psdump");

    open (F, $psdump) || die "Could not open $psdump: $!";

    $PROCS = "";
    while ($line = <F>) {
        chomp($line);
        $PROCS = $PROCS . "#DELI#" . $line;
    }

    &feedthemonster;
    close(F);
    system("/bin/rm -f $psdump");

}

# Subroutine that feeds the data into CCE:
sub feedthemonster {
    ($sys_oid) = $cce->find('SOL_Console');
    ($ok, $sys) = $cce->get($sys_oid);
        
    ($ok) = $cce->set($sys_oid, '',{
        'sol_logins' => $PROCS,
        'timestamp' => time()
        });

}

$cce->bye('SUCCESS');
exit(0);

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