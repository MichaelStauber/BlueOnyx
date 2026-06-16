#!/usr/bin/perl -w -I/usr/sausalito/perl
# $Id: telnetAccess.pl

use strict;
use TelnetAccess;

if ($#ARGV < 0) {
    printState();
}
elsif ($ARGV[0] eq "-h" || $ARGV[0] eq "--help" || $#ARGV > 0) {
    printHelp();
}
else {
    my $msg = TelnetAccess::changeAccess($ARGV[0]);
    printHelp() if ($msg eq "");
    printState();
}

sub printHelp {
  print STDERR<<EOF;
Usage : $0 [<none | root | reg>]
 No arguments : show current state
 -h or --help : show usage info
 none         : Don't allow telnet for anyone.
 root         : Allow telnet for only root.
 reg          : Allow telnet to all registered users.
EOF
  exit 0;
}

sub printState {
    if (TelnetAccess::get_telnet_server_on()) {
        print "Service is allowed.\n";
        print "Access is restricted to the root user.\n"
        if (!TelnetAccess::get_telnet_server_open());
    }
    else {
        print "Service is disallowed.\n";
    }
}

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