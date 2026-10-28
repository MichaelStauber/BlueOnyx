#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
#
# $Id: force_php_pool_update.pl
#
# This script ensures that every Vsite's PHP-FPM pool (and the suPHP
# php.ini where applicable) has been written by the CURRENT pool
# generator code - specifically the per-Vsite PHP temp and session
# directories introduced in base-apache 2.4.9-7.
#
# Criterion is the actual configuration state, NOT a flag file: it
# checks whether the existing pool configs already contain the new
# directives. Only if at least one Vsite config is outdated does it
# fire PHPVsite.force_update on all Vsites to regenerate them.
#
# This makes it fully idempotent and self-healing: it can be called on
# every package update without version juggling, and it re-repairs
# manually mangled pool configs on the next update.
#
# Output is intended for syslog (RPM %post pipes stdout/stderr to
# logger). Exit 0 on success / nothing-to-do; exit 1 on root check
# failure or if any force_update CCE set fails.

use CCE;
my $cce = new CCE;
$cce->connectuds();

# Root check:
my $id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!\n";
    $cce->bye('FAIL');
    exit(1);
}

# Quick state check BEFORE walking CCE: if every existing Vsite pool
# config and every suPHP php.ini already carries the per-Vsite temp
# directory directive, everything has been written by the new generator
# and there is nothing to do. (The www.conf master pools are excluded -
# they never carry Vsite directives.)
my $needs_update = 0;

my @pool_files = glob('/etc/php-fpm.d/*.conf /etc/php-fpm-[0-9]*.d/*.conf');
for my $pf (@pool_files) {
    my $base = `basename $pf`;
    chomp($base);
    next if ($base eq 'www.conf');
    my $found = `grep -c 'sys_temp_dir' $pf 2>/dev/null`;
    chomp($found);
    if ($found eq "0") {
        $needs_update = 1;
        print "Outdated pool config found: $pf\n";
    }
}

# Same check for suPHP php.ini copies (only exist for suPHP sites):
my @suphp_inis = glob('/home/.sites/*/wwwroot/php.ini');
for my $ini (@suphp_inis) {
    my $found = `grep -c 'sys_temp_dir' $ini 2>/dev/null`;
    chomp($found);
    if ($found eq "0") {
        $needs_update = 1;
        print "Outdated suPHP php.ini found: $ini\n";
    }
}

if (!$needs_update) {
    print "All Vsite PHP configurations are current. Nothing to do.\n";
    $cce->bye('SUCCESS');
    exit(0);
}

# Find all Vsites:
my (@vhosts) = $cce->findx('Vsite');
my $failures = 0;

print "Going through all sites to force a PHP-FPM pool rewrite:\n";

# Walk through all Vsites:
for my $vsite (@vhosts) {
    my ($ok, $my_vsite) = $cce->get($vsite);

    print "Processing Site: $my_vsite->{fqdn}\n";

    # Fire force_update on the Vsite object. The handlers regenerate
    # the FPM pool (and suPHP php.ini) with the current generator
    # code, which includes the per-Vsite temp and session dirs.
    ($ok) = $cce->set($vsite, 'PHPVsite', { 'force_update' => time() });

    if (!$ok) {
        print "FAILED to set force_update for Vsite $my_vsite->{fqdn}\n";
        $failures++;
    }
}

print "Done.\n";

if ($failures) {
    $cce->bye('FAIL');
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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