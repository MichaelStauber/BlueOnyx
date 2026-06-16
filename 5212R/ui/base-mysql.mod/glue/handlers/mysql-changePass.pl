#!/usr/bin/perl -I/usr/sausalito/perl
# Authors: Brian N. Smith and Michael Stauber
# $Id: mysql-changePass.pl

#use strict;
use Sauce::Service;
use CCE;
use POSIX qw(strftime);

# Debugging switch:
my $DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $SOCKET_PATH = "/var/lib/mysql/mysql.sock";
my $PORT = 3306;
my $MYSQL = "/usr/bin/mysql";

# Set up CCE and connect
my $cce = new CCE;
$cce->connectfd();

my $oid = $cce->event_oid();
my $obj = $cce->event_object();

my $firstboot = "0";
my @oids = $cce->find('System');
if (!defined($oids[0])) {
    &debug_msg("'System' Object not found!");
    exit 0;
}
else {
    ($ok, $obj) = $cce->get($oids[0]);
    if ($obj->{isLicenseAccepted} == "0") {
        $firstboot = "1";
    }
}

my ($ok, $nuMySQL) = $cce->get($oids[0], "mysql");

my @moids = $cce->find('MySQL');
if (!defined($moids[0])) {
    &debug_msg("'MySQL' Object not found!");
    exit 0;
}

my ($ok, $MySQL) = $cce->get($moids[0], "");

$host = $MySQL->{'sql_host'};
$PORT = $MySQL->{'sql_port'};

$user = $nuMySQL->{'mysqluser'};
$old = $nuMySQL->{'oldpass'};
$new = $nuMySQL->{'newpass'};

&debug_msg("user: " . $user . "\n");
&debug_msg("old: " . $old . "\n");
&debug_msg("new: " . $new . "\n");

# Make sure MySQL runs before we change the password:
if (($firstboot eq "1") || ($nuMySQL->{enabled} eq "0")) {
    &debug_msg("Firstboot OR SQL not running. Making sure MariaDB runs.");
    ($ok) = $cce->set($oids[0], 'mysql',{
        "enabled" => "1",
        "onoff" => time()
    });
}

# Attempt connection via socket first
my $test_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
$test_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$test_cmd .= "-sN -e \"SELECT CURRENT_USER();\"";
&debug_msg("Testing socket connection: $test_cmd");

my $output = `$test_cmd 2>&1`;
my $exitcode = $? >> 8;
if ($output ne "") {
    &debug_msg("MySQL returned output:\n$output");
}
&debug_msg("Exit code from socket test: $exitcode");

my $status = $exitcode;
&debug_msg("Testing socket connection status: $status");

# Retry logic if socket fails
if ($status != 0) {
    &debug_msg("Socket connection failed, trying TCP");
    $test_cmd = "$MYSQL --user=$user --host=127.0.0.1 --port=$PORT ";
    $test_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
    $test_cmd .= "-sN -e \"SELECT CURRENT_USER();\"";
    &debug_msg("Testing TCP connection: $test_cmd");
    $output = `$test_cmd 2>&1`;
    &debug_msg("Testing TCP connection returned: $output");
    $status = $? >> 8;
    &debug_msg("Testing TCP connection status: $status");

    if ($status != 0) {
        &debug_msg("Both socket and TCP connection attempts failed: $output");
        $cce->bye('FAIL');
        exit(1);
    }
}
chomp($output);
&debug_msg("Connected as $output");

# Check if root@127.0.0.1 exists
my $check_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
$check_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$check_cmd .= "-sN -e \"SELECT COUNT(*) FROM mysql.user WHERE User = 'root' AND Host = '127.0.0.1';\"";
&debug_msg("Checking for root\@127.0.0.1: $check_cmd");
my $has_127 = `$check_cmd 2>/dev/null`;
chomp($has_127);
&debug_msg("Check result: $has_127");

if ($has_127 !~ /^\d+$/ || $has_127 == 0) {
    &debug_msg("root\@127.0.0.1 missing, creating...");
    my $create_sql = "CREATE USER 'root'\@'127.0.0.1' IDENTIFIED BY '$new';";
    my $grant_sql  = "GRANT ALL PRIVILEGES ON *.* TO 'root'\@'127.0.0.1' WITH GRANT OPTION;";
    my $create_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
    $create_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
    $create_cmd .= "-e \"$create_sql $grant_sql\"";
    &debug_msg("Executing: $create_cmd");
    system($create_cmd);
} else {
    &debug_msg("root\@127.0.0.1 already exists");
}

# Force-reset password for root@127.0.0.1 to be safe
my $set_pw_127_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
$set_pw_127_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$set_pw_127_cmd .= "-e \"ALTER USER 'root'\@'127.0.0.1' IDENTIFIED BY '$new';\"";
&debug_msg("Explicitly resetting password for root\@127.0.0.1: $set_pw_127_cmd");
system($set_pw_127_cmd);

# Reset root@localhost if plugin = unix_socket or auth_or contains it
my $auth_check_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
$auth_check_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$auth_check_cmd .= "-sN -e \"SELECT JSON_EXTRACT(priv, '$.plugin'), JSON_CONTAINS(JSON_EXTRACT(priv, '\$.auth_or'), '{\\\"plugin\\\":\\\"unix_socket\\\"}') FROM mysql.global_priv WHERE User='root' AND Host='localhost';\"";

my $auth_check_output = `$auth_check_cmd 2>/dev/null`;
chomp($auth_check_output);
my ($plugin, $has_unix_socket) = split(/\s+/, $auth_check_output);

chomp($plugin);
chomp($has_unix_socket);

&debug_msg("root\@localhost plugin: $plugin, has_unix_socket: $has_unix_socket");

if ($plugin eq '"unix_socket"' || $has_unix_socket eq '1') {
    &debug_msg("Resetting root\@localhost to mysql_native_password");
    my $fix_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
    $fix_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
    $fix_cmd .= "-e \"ALTER USER 'root'\@'localhost' IDENTIFIED BY '$new';\"";
    &debug_msg("Executing: $fix_cmd");
    system($fix_cmd);
}

# Check authentication plugins for debugging
my $plugin_check_cmd = "$MYSQL --user=$user --socket=$SOCKET_PATH ";
$plugin_check_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$plugin_check_cmd .= "-sN -e \"SELECT User, Host, plugin FROM mysql.user WHERE User='root';\"";
my $plugin_out = `$plugin_check_cmd 2>&1`;
&debug_msg("Authentication plugins for root accounts:\n$plugin_out");

# Update passwords for all root@* entries at once
my $bulk_update_cmd = "$MYSQL --user=$user --host=localhost --port=$PORT ";
$bulk_update_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$bulk_update_cmd .= "-sN -e \"SELECT CONCAT('ALTER USER \\'', User, '\\'@\\'', Host, '\\' IDENTIFIED BY \\'', '$new', '\\';') FROM mysql.user WHERE User='root';\"";
&debug_msg("Building ALTER commands for all root@*: $bulk_update_cmd");

my @alter_cmds = `$bulk_update_cmd`;
foreach my $sql (@alter_cmds) {
    chomp($sql);
    next unless $sql =~ /^ALTER USER /;
    my $cmd = "$MYSQL --user=$user --host=localhost --port=$PORT ";
    $cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
    $cmd .= "-e \"$sql\"";
    &debug_msg("Executing: $cmd");
    system($cmd);
}

# Flush privileges
my $flush_cmd = "$MYSQL --user=$user --host=localhost --port=$PORT ";
$flush_cmd .= ($old ne "") ? "--password='$old' " : "--skip-password ";
$flush_cmd .= "-e \"FLUSH PRIVILEGES;\"";
&debug_msg("Executing: $flush_cmd");
system($flush_cmd);

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', "$0: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright (C) 2006, NuOnce Networks, Inc. All rights reserved.
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