#!/usr/bin/perl
# $Id: rsscache_cron.pl

my $DEBUG = 0;
my $ignore_sleep = 0;

require LWP::UserAgent;
use Getopt::Long;

GetOptions(
    'i' => \$ignore_sleep, # Process '-i' switch
    'd' => \$DEBUG, # Process '-d' switch to enable debug mode
);

# Sleep for a random amount of time, but no longer than 5 minutes:
sleep rand(300) unless $ignore_sleep;

# Connect to AdmServ's cron page:
my $ua = LWP::UserAgent->new(
    ssl_opts => {
        verify_hostname => 0,
        SSL_verify_mode => 0, # Corrected: use a numeric value
    }
);

# Connect to AdmServ's cron page:
my $response = $ua->get('https://127.0.0.1:81/swupdate/rsscron');

# Status check:
if ($response->is_success) {
    # It worked. We exit silently. Unless debug is enabled:
    if ($DEBUG) {
        print $response->status_line;
    }
    exit(0);
}
else {
    # That didn't work. Print more information about the failure if debug is on:
    if ($DEBUG) {
        print "Status: " . $response->status_line . "\n";
        print "Response content: " . $response->decoded_content . "\n";
        die "Connect to AdmServ failed\n";
    }
}

exit(0);

# 
# Copyright (c) 2016-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2016-2024 Greg Kuhnert, Compass Networks
# Copyright (c) 2016-2024 Team BlueOnyx, BLUEONYX.IT
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
