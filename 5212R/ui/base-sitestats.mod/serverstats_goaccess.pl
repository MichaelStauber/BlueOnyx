#!/usr/bin/perl -I /usr/sausalito/perl
# $Id: sitestats_goaccess.pl
#
# Scan Virtual Site statistics and run web.log through GoAccess to
# create the JSON files that we need to display the web based access
# statistics.

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

$logfile = '/tmp/.logrotate_apache_access';

if (! -f $logfile) {
    print "Logfile not found. This should not be run outside of logrotate.\n";
    exit 1;
}

use CCE;
use DateTime;

my $cce = new CCE;
$cce->connectuds();

$GoAccess_cmd = '/usr/bin/goaccess';

my $now = time();

$cce->connectuds();

($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime();
$year += 1900;
$mon += 1;
&debug_msg("Date is: $year / $mon / $mday \n");

$stats_dir_server_dated = '/home/.sites/server/logs/' . $year . '/' . $mon . '/' . $mday;
if (! -d $stats_dir_server_dated) {
    # No stats dir found!
    &debug_msg("Did NOT find server's daily stats dir at " . $stats_dir_server_dated . "!\n");
}
else {
    # Stats dir already present. Off we go!
    &debug_msg("Found server's daily stats dir at " . $stats_dir_server_dated . "\n");

    $stats_json_file_server = $stats_dir_server_dated . '/' . 'web.json';

    $anon_ip_string = '';

    # Run GoAccess:
    $go_access_params = $logfile . ' --log-format=\'%h %^[%d:%t %^] \"%r\" %s %b \"%R\" \"%u\"\' --date-format=%d/%b/%Y --time-format=%H:%M:%S ' . $anon_ip_string . ' --json-pretty-print -o ' . $stats_json_file_server;
    &debug_msg("Running: " . $GoAccess_cmd . ' ' . $go_access_params . "\n");
    system("$GoAccess_cmd $go_access_params");
}

$cce->bye('SUCCESS');
exit 0;

#
### Subs
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        print "$msg";
        closelog;
    }
}

# 
# Copyright (c) 2015-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2015-2022 Team BlueOnyx, BLUEONYX.IT
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