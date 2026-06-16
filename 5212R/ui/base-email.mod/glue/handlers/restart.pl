#!/usr/bin/perl -w -I/usr/sausalito/perl/
# $Id: restart.pl

use Sauce::Service;
use CCE;
use FileHandle;

# debugging flag, set to 1 to turn on logging to STDERR
my $DEBUG = 0;
if ($DEBUG) { 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE( Namespace => 'Email', Domain => 'base-email' );

$cce->connectfd();

my $obj = $cce->event_object();

# Get "System" Object from CODB:
my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

# Get system object:
my ($ok, $System) = $cce->get($oids[0]);
if (!$ok) { 
    $cce->bye('FAIL');
    exit(1);
}

# Default MTA:
my $MTA = 'postfix';
if ($System->{'MTA'} ne '') {
    $MTA = lc($System->{'MTA'});
}

&debug_msg("Server is using MTA: $MTA\n");

# make sure this is the System object being modified and not a Vsite
if ($obj->{NAMESPACE} ne 'Email') {
    my ($sys_oid) = $cce->find('System');
    (my $ok, $obj) = $cce->get($sys_oid, 'Email');
    if (!$ok) {
        $cce->bye('FAIL', '[[base-email.cantReadSystem]]');
        &debug_msg("Not restarting MTA - early exit.");
        exit(1);
    }
}

if (($obj->{enableSMTP}) || ($obj->{enableSMTPS})) {
    &debug_msg("Restarting Dovecot.");
    Sauce::Service::service_run_init('dovecot', 'restart');
    &debug_msg("Restarting MTA.");
    Sauce::Service::service_run_init($MTA, 'restart');
}

$cce->bye("SUCCESS");
exit 0;

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
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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