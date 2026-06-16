#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
#
# $Id: jail_toggler.pl
#
# This script walks through all sites and toggles Jails off and back on.
#
# Usage:
#
# Simply run this script once. Running it multiple times will do no harm, though.

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

# Find all Vsites:
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');
my $errors = '0';

print "====================================================================\n";
print "Going through all sites to toggle the Jailkit jails off and back on.\n";
print "====================================================================\n\n";

# Walk through all Vsites:
for my $vsite_oid (@vhosts) {
    my %userlist = ();
    my @user_oids = ();
    ($ok, my $vsite) = $cce->get($vsite_oid);
    ($ok, my $vsite_shell) = $cce->get($vsite_oid, 'Shell');

    print "Processing Site: $vsite->{fqdn}: ";

    if ($vsite_shell->{enabled} eq '0') {
        print "Shell is disabled. Skipping. [ OK ]\n";
    }
    else {
        # Create list of Users with Shell for this Vsite and store their Shell settings:
        @user_oids = $cce->find("User", { 'site' => $vsite->{name} });

        foreach my $u_oid (@user_oids) {
            ($ok, my $user_shell) = $cce->get($u_oid, 'Shell');
            if ($user_shell->{enabled} ne '0') {
                $userlist{$u_oid} = $user_shell->{enabled};
            }
        }

        # Toggle Vsite Shell off:
        ($ok) = $cce->set($vsite_oid, 'Shell',{  'enabled' => '0' });

        # Toggle User Shell off:
        foreach my $u_oid (@user_oids) {
            ($ok) = $cce->set($u_oid, 'Shell',{  'enabled' => '0' });
        }

        # Toggle Vsite Shell back to previous state:
        ($ok) = $cce->set($vsite_oid, 'Shell',{  'enabled' => $vsite_shell->{enabled} });

        print " - Shell set back to '" . $vsite_shell->{enabled} . "' [ OK ]\n";

        while (($oid, $shell_val) = each (%userlist)) {
            $shell_val = $userlist{$oid};
            if (($shell_val == '') || ($shell_val > '4') || ($shell_val < '1')) {
                print " - ERROR: User-Shell of User '" . $user_obj->{name} . "' was: " . $shell_val . "\n";
            }
            else {
                sleep(5);
                ($ok, my $user_obj) = $cce->get($oid);
                ($ok) = $cce->set($oid, 'Shell',{  'enabled' => $shell_val });
                print " - Setting User-Shell of User '" . $user_obj->{name} . "' to: '" . $shell_val . "'";
                ($ok, my $user_shell) = $cce->get($oid, 'Shell');
                if ($user_shell->{enabled} ne $shell_val) {
                    print " [ FAIL! ] Trying again:";
                    $errors++;
                    sleep(5);
                    ($ok) = $cce->set($oid, 'Shell',{  'enabled' => $shell_val });
                    ($ok, my $user_shell) = $cce->get($oid, 'Shell');
                    if ($user_shell->{enabled} ne $shell_val) {
                        print " [ FAIL! ] Skipping!\n";
                        $errors++;
                    }
                    else {
                        print " [ OK ]\n";
                    }
                }
                else {
                    print " [ OK ]\n";
                }
            }
        }
    }
}

if ($errors > '0') {
    print "\n[ WARNING:] Finished with errors.\n\n";
}
else {
    print "\n[ INFO: ] Finished without errors.\n\n";
}

# tell cce everything is okay
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
