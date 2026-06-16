#!/usr/bin/perl -I/usr/sausalito/perl -I.
#
# /usr/sausalito/sbin/gen_api_admin.pl
#
# Ensures existence of 'api-admin' user and securely encrypts password for cced-api use

use strict;
use CCE;
use Base::HomeDir;
use File::Path qw(make_path);
use File::Basename;
use MIME::Base64;
use Sys::Syslog qw( :DEFAULT setlogsock);

my $DEBUG = 0;

# Constants
my $username     = 'api-admin';
my $passwdfile   = '/etc/cced-api/api-admin.passwd';
my $keyfile      = '/etc/cced-api/master.key';
my $password_len = 24;

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        print STDERR "$0: $msg\n";
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', $msg);
        closelog;
    }
}

sub generate_password {
    open(RND, "<", "/dev/urandom") or die "Cannot open /dev/urandom: $!";
    my $buf;
    read(RND, $buf, $password_len);
    close(RND);
    return encode_base64($buf, '') =~ s/[^A-Za-z0-9]//gr;
}

sub ensure_master_key {
    unless (-f $keyfile) {
        make_path(dirname($keyfile), { mode => 0700 }) unless -d dirname($keyfile);
        open(my $kf, '>', $keyfile) or die "Cannot create $keyfile: $!";
        print $kf encode_base64(pack("C*", map { int(rand(256)) } 1..32), '');  # 32 raw bytes, base64
        close($kf);
        chmod(0600, $keyfile);
        system("chown admserv:admserv $keyfile");
        debug_msg("Created new master key at $keyfile");
    }
}

#
### Main Logic:
#

# Ensure the key file exists
ensure_master_key();

# Connect to CODB
my $cce = new CCE;
$cce->connectuds();

# Generate random password
my $password = generate_password();

# Home dir for CODB
my $homeDir = $Base::HomeDir::HOME_ROOT;
$homeDir = '/home' if $homeDir eq '/';

# Check if user exists
my @oids = $cce->find('User', { 'name' => $username });
my $success = 0;

if (@oids) {
    my $oid = $oids[0];
    ($success) = $cce->set($oid, '', {
        'password' => $password,
        'systemAdministrator' => 1,
        'volume' => $homeDir,
        'ui_enabled' => 1
    });
    $cce->set($oid, 'Shell',      { 'enabled' => 0 });
    $cce->set($oid, 'RootAccess', { 'enabled' => 0 });
}
else {
    ($success) = $cce->create('User', {
        'fullName' => 'API Integration Account',
        'name'     => $username,
        'password' => $password,
        'systemAdministrator' => 1,
        'volume'   => $homeDir,
        'stylePreference' => 'BlueOnyx',
        'localePreference' => 'browser',
        'ui_enabled' => 1
    });
    if ($success) {
        my $oid = $cce->oid();
        $cce->set($oid, 'Shell',      { 'enabled' => 0 });
        $cce->set($oid, 'RootAccess', { 'enabled' => 0 });
    }
}

$cce->bye();

# Encrypt and store password if success
if ($success) {
    my $key = `cat $keyfile`;
    chomp($key);

    my $tmpfile = "/tmp/.api-admin-pass.$$";
    open(my $tfh, '>', $tmpfile) or die "Cannot write temp file: $!";
    print $tfh $password;
    close($tfh);

    system("openssl enc -aes-256-cbc -pbkdf2 -salt -pass pass:$key -in $tmpfile -out $passwdfile");
    unlink($tmpfile);

    chmod(0600, $passwdfile);
    system("chown admserv:admserv $passwdfile");
    debug_msg("Encrypted password ($password) and updated $passwdfile");
}
else {
    debug_msg("Failed to create or update user $username");
    exit 1;
}

exit 0;

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
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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