#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: handle_password.pl
#
# update root's password if admin changes their password

use CCE;
use Base::User qw(usermod);

# debugging flag, set to 1 to turn on logging to STDERR
my $DEBUG = 0;
if ($DEBUG) { 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE; 
$cce->connectfd();

&debug_msg("Startup");

my $obj = $cce->event_object();

my $name = $obj->{name};
my $md5_pw = $obj->{md5_password};

# just leave if it's not admin
if ($name ne 'admin') {
    &debug_msg("Not 'admin' - exit SUCCESS");
    $cce->bye('SUCCESS');
    exit(0);
}

# set admin's root password
my ($ok) = usermod({ 'name' => 'root', 'password' => $md5_pw });

if ($ok == '0') {
    &debug_msg("Root password set.");
    $cce->bye('SUCCESS');
    exit(0);
} 
else {
    &debug_msg("Could not change ${name}'s password.");
    $cce->bye('FAIL');
    exit(1);
}

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        $DEBUG && print STDERR "$ARGV[0]: ", $msg, "\n";
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
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