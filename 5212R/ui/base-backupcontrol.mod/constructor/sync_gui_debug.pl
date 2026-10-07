#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: sync_gui_debug.pl
#
# Read CI_ENVIRONMENT from the Chorizo .env and sync System.DesktopControl.gui_debug
# in CODB. Uses CCE update() so handlers only run when the value actually changes.

use CCE;

my $env = '/usr/sausalito/ui/chorizo/ci4/.env';

my $cce = new CCE;
$cce->connectuds();

my @oids = $cce->find('System');
if (!defined($oids[0])) {
    $cce->bye('SUCCESS');
    exit(0);
}

my $debug = &env_debug_enabled($env);
$cce->update($oids[0], 'DesktopControl', { 'gui_debug' => $debug });

$cce->bye('SUCCESS');
exit(0);

sub env_debug_enabled {
    my $file = shift;
    return '0' unless ((defined($file)) && (-f $file));
    my $state = '0';
    if (open(my $fh, '<', $file)) {
        while (my $line = <$fh>) {
            next if ($line =~ /^\s*#/);
            if ($line =~ /^\s*CI_ENVIRONMENT\s*=\s*development\b/) {
                $state = '1';
            }
            elsif ($line =~ /^\s*CI_ENVIRONMENT\s*=\s*production\b/) {
                $state = '0';
            }
        }
        close($fh);
    }
    return $state;
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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