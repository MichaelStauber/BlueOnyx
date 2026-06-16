#!/usr/bin/perl -w -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email

use strict;
use CCE;

# Debugging switch:
my $DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

# Get "System" Object from CODB:
my @soids = $cce->find('System');
if (not @soids) {
    debug_msg("No 'System' object found!\n");
    $cce->bye('FAIL');
    exit 1;
}

my $oid = $cce->event_oid();
my ($ok, $domain) = $cce->get($oid);

debug_msg("State of Vsite 'allow_sender_spoof': " .  $domain->{allow_sender_spoof} . "\n");
if ($domain->{allow_sender_spoof} eq '1') {
    debug_msg("Nothing to do ...\n");
    $cce->bye("SUCCESS");
    exit(0);    
}

my $virtualsite = $domain->{'fqdn'};
my ($ok, $vsite_php) = $cce->get($oid, "PHP");

my @user_oids = $cce->findx("User", '', { 'site' => $domain->{name}});
for my $user_oid (@user_oids) {
    my ($uok, $user) = $cce->get($user_oid);
    if (!$uok) {
        debug_msg("Failed to get User OID $user_oid\n");
        next;
    }
    if ($vsite_php->{prefered_siteAdmin} eq $user->{name}) {
        debug_msg("Skipping siteAdmin who owns /web: " .  $user->{name} . "\n");
        next;
    }
    debug_msg("Processing User: " .  $user->{name} . " to turn 'allow_sender_spoof' off.\n");
    $cce->update($user_oid, 'Email', { 'allow_sender_spoof' => '0' })
}

# Conditionally restart MTA:
$cce->set($soids[0], 'Email', { 'force_mc_rebuild' => time() });

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

# Debug:
sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
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