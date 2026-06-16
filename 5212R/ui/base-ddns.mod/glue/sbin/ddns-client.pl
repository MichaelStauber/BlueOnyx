#!/usr/bin/perl

use strict;
use warnings;

use IO::Socket::SSL qw(SSL_VERIFY_NONE);
use LWP::UserAgent;
use URI::Escape;

my $DNS_Server = 'your-blueonyx-dns-server';
my $username   = 'admin';
my $password   = '';

my $ua = LWP::UserAgent->new(
    timeout => 10,
    ssl_opts => {
        verify_hostname => 0,
        SSL_verify_mode => SSL_VERIFY_NONE,
    },
);

# Build the URL with query parameters
my $url = sprintf(
    "https://%s:81/ddns/ddnsapi?un=%s&pw=%s",
    $DNS_Server,
    uri_escape($username),
    uri_escape($password)
);

# Make GET request instead of POST
my $resp = $ua->get($url);

if ($resp->is_success) {
    my $message = $resp->decoded_content;
    if ($message =~ m/DNSOK/) {
        print "DNS Updated\n";
    }
    else {
        print "An error occurred while updating the DNS\n";
    }
}
else {
    print "HTTP GET error code: ", $resp->code, "\n";
    print "HTTP GET error message: ", $resp->message, "\n";
}

# 
# Copyright (c) 2021-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2021-2025 Team BlueOnyx, BLUEONYX.IT
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
