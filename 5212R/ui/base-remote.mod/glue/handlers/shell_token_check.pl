#!/usr/bin/perl -w -I /usr/sausalito/perl

use strict;
use JSON;
use Sys::Syslog qw(:DEFAULT setlogsock);

my $TOKEN_DIR = '/usr/sausalito/sessions/shellinabox-tokens';
my $TOKEN_TTL = 600;
$| = 1;

sub log_event {
    my ($message) = @_;
    setlogsock('unix');
    openlog('shell_token_check', '', 'authpriv');
    syslog('notice', '%s', $message);
    closelog();
}

sub validate_token {
    my ($lookup) = @_;
    my ($token, $client_ip) = split(/:/, defined($lookup) ? $lookup : '', 2);
    return 'invalid' unless defined $token && $token =~ /\A[0-9a-f]{64}\z/;

    my $file = "$TOKEN_DIR/$token";
    return 'invalid' unless -f $file;

    open(my $fh, '<', $file) or return 'invalid';
    local $/;
    my $json = <$fh>;
    close($fh);

    my $data = eval { decode_json($json) };
    return 'invalid' if $@ || ref($data) ne 'HASH';
    return 'invalid' unless defined $data->{version} && $data->{version} == 1
        && defined $data->{user} && $data->{user} ne ''
        && defined $data->{created}
        && defined $data->{expires}
        && $data->{expires} =~ /\A[0-9]+\z/;
    return 'expired' if time() > $data->{expires};
    return 'invalid' if time() < $data->{created};

    $client_ip = '' unless defined $client_ip;
    if ($client_ip ne '' && defined $data->{ip} && $data->{ip} ne ''
        && $data->{ip} ne $client_ip) {
        log_event('Shellinabox authorization IP mismatch');
        return 'invalid';
    }

    return 'valid';
}

while (my $line = <STDIN>) {
    chomp($line);
    print validate_token($line), "\n";
}

#
# Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
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
