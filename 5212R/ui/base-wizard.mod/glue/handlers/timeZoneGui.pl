#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: timeZoneGui.pl
#
# Configure the CodeIgniter 4 TimeZone in /usr/sausalito/ui/chorizo/ci4/.env
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for virtual_host.pl\n");
}

use CCE;
use Sauce::Service;
use Sauce::Util;

# defines that should probably go elsewhere
my $GUI_env = '/usr/sausalito/ui/chorizo/ci4/.env';

my $cce = new CCE(Namespace => "Time");
$cce->connectfd();

my $time_obj = $cce->event_object();

&debug_msg("Startup of timeZoneGui.pl.\n");

my $timeZone = $time_obj->{timeZone};
if ($timeZone) {
    # timeZone defined
    &debug_msg("Editing $GUI_env\n");
    if (!Sauce::Util::editfile($GUI_env, *update_gui_env_conf, $timeZone)) { 
        $cce->warn("[[base-time.errorWritingConfFile]]");
    }
}

system("rm -f /usr/sausalito/ui/chorizo/ci4/.env.backup.*");

&debug_msg("End of timeZoneGui.pl reached.\n");

$cce->bye('SUCCESS');
exit 0;

#
### Subroutines:
#

sub update_gui_env_conf {
    my ($fin, $fout, $timeZone) = @_;
    while (<$fin>) {
        if (/^app\.appTimezone(.*)$/) {
            # Replace the stock timeZone line.
            &debug_msg("Found 'app.appTimezone' - replacing it.\n");
            print $fout 'app.appTimezone = \'' . $timeZone . '\'' . "\n";
        }
        else {
            # some other line, leave it there
            print $fout $_;
        }
    }
    return 1;
}

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
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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
