#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: set_web_owner.pl
#
# Repair script for Vsite web owners.
# Walk through all Vsites and ensure PHP.prefered_siteAdmin points to a real
# site admin user instead of empty, nobody or apache.

use CCE;

my $DEBUG = 0;

my $cce = new CCE;
$cce->connectuds();

my @vsites = $cce->findx('Vsite');
my $updated = 0;

foreach my $vsite_oid (@vsites) {
    my ($ok, $vsite) = $cce->get($vsite_oid);
    next if (!$ok || !defined($vsite));

    my ($php_ok, $vsite_php) = $cce->get($vsite_oid, 'PHP');
    my $current_owner = '';
    if ($php_ok && defined($vsite_php) && defined($vsite_php->{'prefered_siteAdmin'})) {
        $current_owner = $vsite_php->{'prefered_siteAdmin'};
    }

    # Collect users for this Vsite and build a list of valid site admins.
    my @site_users = $cce->findx('User', { 'site' => $vsite->{'name'} });
    my @site_admins = ();

    foreach my $user_oid (@site_users) {
        my ($user_ok, $user) = $cce->get($user_oid);
        next if (!$user_ok || !defined($user));

        next if (!defined($user->{'capLevels'}));
        next if ($user->{'capLevels'} !~ /&siteAdmin&/);

        push(@site_admins, $user->{'name'}) if ($user->{'name'} ne '');
    }

    # Determine whether the current owner is valid.
    my $owner_is_valid = 0;
    if ($current_owner ne '' && lc($current_owner) ne 'nobody' && lc($current_owner) ne 'apache') {
        foreach my $site_admin (@site_admins) {
            if ($current_owner eq $site_admin) {
                $owner_is_valid = 1;
                last;
            }
        }
    }

    next if ($owner_is_valid);

    # Prefer the Vsite creator if they are a site admin. Otherwise use the
    # first site admin we can find.
    my $new_owner = '';
    if (defined($vsite->{'createdUser'}) && $vsite->{'createdUser'} ne '') {
        foreach my $site_admin (@site_admins) {
            if ($vsite->{'createdUser'} eq $site_admin) {
                $new_owner = $site_admin;
                last;
            }
        }
    }

    if ($new_owner eq '' && scalar(@site_admins) > 0) {
        $new_owner = $site_admins[0];
    }

    next if ($new_owner eq '');

    if ($DEBUG) {
        print STDERR "Updating Vsite $vsite->{'name'} ($vsite->{'fqdn'}) owner from '$current_owner' to '$new_owner'\n";
    }

    ($ok) = $cce->set($vsite_oid, 'PHP', { 'prefered_siteAdmin' => $new_owner });
    if ($ok) {
        $updated++;
    }
    elsif ($DEBUG) {
        print STDERR "Failed to update Vsite $vsite->{'name'} ($vsite->{'fqdn'})\n";
    }
}

$cce->bye('SUCCESS');
exit(0);

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
