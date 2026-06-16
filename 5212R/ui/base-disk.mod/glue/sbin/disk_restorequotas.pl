#!/usr/bin/perl -I /usr/sausalito/handlers/base/disk -I /usr/sausalito/perl
# 
# $Id: disk_restorequotas.pl
#

use Disk qw(setquota);
use CCE;

sub cce_sync_site_quotas {
    my ($cce) = @_;
    my (@oids, $obj, $ok, $old, $new);

    # Get the list of virtual site quotas from CCE
    @oids = $cce->find('Vsite');

    # Push this information out to the file system
    foreach $oid (@oids) {
        # Get the quota information for this site
        ($ok, $obj, $old, $new) = $cce->get($oid);
        if ($ok) {
            # Sync the quota.  Ignore the errors
            setquota($cce, $obj, $oid);
        }
    }
    return 1;
}


sub cce_sync_user_quotas {
    my ($cce) = @_;
    my (@oids, $obj, $ok, $old, $new);

    # Get the list of virtual site quotas from CCE
    @oids = $cce->find('User');

    # Push this information out to the file system
    foreach $oid (@oids) {
        # Get the quota information for this site
        ($ok, $obj, $old, $new) = $cce->get($oid);
        if ($ok) {
            # Sync the quota.  Ignore the errors
            setquota($cce, $obj, $oid);
        }
    }
    return 1;
}


#
# Main
#

my $ok;

# Open a connection to CCE
my $cce = new CCE;
$cce->connectuds();

# Fix the site quotas
$ok = cce_sync_site_quotas($cce);
if (! $ok) {
    $cce->bye('FAIL');
    exit(1);
}

# Fix the user quotas
$ok = cce_sync_user_quotas($cce);
if (! $ok) {
    $cce->bye('FAIL');
    exit(1);
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