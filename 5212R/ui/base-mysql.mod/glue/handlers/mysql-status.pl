#!/usr/bin/perl -I/usr/sausalito/perl
#
# mysql-status.pl

use CCE;
use POSIX;
use DBI;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

$cce = new CCE;

$cce->connectfd();

# Get System Information
my @oids = $cce->find("System");
my ($ok, $system) = $cce->get($oids[0]);

# Get MySQL Information

# Get MySQL Information
my @oids = $cce->find("MySQL");
my ($ok, $mysql) = $cce->get($oids[0]);

$server_mysql->{DB} = 'mysql';

# Enable and Disable Check
if ($mysql->{enabled} eq '0') {
    &debug_msg("DB: MySQL is disabled \n");
    $cce->set($system->{OID}, "mysql", {connectionstatus => '0'});
    &debug_msg("connectionstatus: 0 \n");
    $cce->bye('SUCCESS');
    exit(0);
}

## MySQL Server Connection Check
$dbh = DBI->connect(
        "DBI:mysql:mysql:$mysql->{sql_host}:$mysql->{sql_port}",
        $mysql->{sql_root}, $mysql->{sql_rootpassword},
        {
            RaiseError => 0,
            PrintError => 0
        }
); 

if ($dbh) {
    $cce->set($system->{OID}, "mysql", {connectionstatus => '1'});
    &debug_msg("connectionstatus: 1 \n");
    $dbh->disconnect;
    $cce->bye('SUCCESS');
    exit(0);
}
else {
    $cce->set($system->{OID}, "mysql", {connectionstatus => '0'});
    &debug_msg("connectionstatus: 0 \n");
    #$dbh->disconnect;
    $cce->bye('SUCCESS');
    exit(0);
}

$cce->bye('SUCCESS');
exit(0);

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