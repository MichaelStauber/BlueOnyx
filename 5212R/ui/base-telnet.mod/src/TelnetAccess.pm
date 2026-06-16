package TelnetAccess;

use lib qw( /usr/sausalito/perl );
use Sauce::Service;

$TelnetAccess::Lockdir          = "/var/lock";
$TelnetAccess::SecretTelnetPort = "telnet";
$TelnetAccess::ShellSymlink     = "/bin/usersh";
$TelnetAccess::GoodShell        = "/bin/bash";
$TelnetAccess::BadShell         = "/bin/badsh";
$TelnetAccess::SuspendShell     = "/bin/false";
#
# changeAccess (code)
# returns a message string describing what happened.
#
sub changeAccess {
    my $code = shift;
    my $rate = shift;
    my $state = shift;
    $enabled = $state ? 'on' : 'off';

    my %accessTable = (
                        'none' => 0,
                        'root' => 0,
                        'reg'  => 1,
                    );
    $accessTable{$code} ||= 'none';

    my $ret = set_telnet_server_on($enabled, $rate);
    $ret .= set_telnet_server_open($accessTable{$code});

    return $ret;
}

#
# set_telnet_server_on
#
# Arguments: 1 for on, 0 for off
# Return value: a status string
# Side effects: modifies inetd.conf
#
sub set_telnet_server_on {
    my $state = shift;
    my $rate = shift;
    $rate ||= 40;

    my $ret = service_set_xinetd('telnet', $state, $rate);
    service_send_signal('xinetd', 'HUP');

    return "Telnet server " . $state?"started":"stopped";
}

#
# get_telnet_server_on
#
# Is the telnet server set to be on?
# Returns 1 if the telnet server is activated, 0 if deactivated
# Arguments: none
# Side effects: none
#
sub get_telnet_server_on {
    my $result = service_get_xinetd('telnet');

    return $result == 0 ? 0 : 1;
}

#
# set_telnet_server_open
#
# Set whether only root can telnet in, or anyone at all
# Arguments: 1 for anyone, 0 for root only
#
sub set_telnet_server_open {
    my $newState = shift;

    unlink($ShellSymlink);
    symlink($newState?$GoodShell:$BadShell, $ShellSymlink) || return "Error creating shell symlink, go in and fix manually";
}

#
# get_telnet_server_open
#
# Check whether telnet access is open
# return 0 if only root can log in, 1 if any user can, -1 if the symlink
# doesn't exist!
#
sub get_telnet_server_open {
    my $whichShell = readlink($ShellSymlink);
    return 1 if ($whichShell eq $GoodShell);
    return 0 if ($whichShell eq $BadShell);
    return -1;
}

1;

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