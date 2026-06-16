#!/usr/bin/perl -I/usr/sausalito/perl

use CCE;
use Sauce::Service;
use Sauce::Util;
use Data::Dumper;

# Debugging switch:
$MDEBUG = "0";
if ($MDEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

&debug_msg("Startup!\n");

umask(002);

$cce = new CCE;
$cce->connectfd();

$oid = $cce->event_oid();

($ok, $vsite) = $cce->get($oid);
($ok, $vsite_mysql) = $cce->get($oid, 'MYSQL_Vsite');
($ok, $vsite_PHP) = $cce->get($oid, 'PHP');

@sysoids = $cce->find('MySQL');
($ok, $MySQL) = $cce->get($sysoids[0]);

$dir_user = $vsite_PHP->{'prefered_siteAdmin'};
$dir_group = $vsite->{'name'};

if (($vsite_mysql->{'doBackupDB'} ne '') && ($vsite_mysql->{'doBackupDBname'} ne '')) {
    $backup_dir = $vsite->{'basedir'} . "/wwwroot/sql/";
    if ( ! -e $backup_dir ) {
        $_sys_cmd[$i++] = "/bin/mkdir -p -m 0755 $backup_dir";
    }

    if ( -e $backup_dir ) {
        $_sys_cmd[$i++] = "/bin/chmod 0755 $backup_dir";
    }

    # DB export command:
    $_sys_cmd[$i++] = "/usr/bin/mysqldump --opt --routines --extended-insert=false -c -Q --user=" . $MySQL->{'sql_root'} . 
      " --password=" . $MySQL->{'sql_rootpassword'} . " --host='" . $MySQL->{'sql_host'} . "' " . $vsite_mysql->{'doBackupDBname'} . " > " . $backup_dir . $vsite_mysql->{'doBackupDBname'} . ".sql";

    if ( -d $backup_dir ) {
        $_sys_cmd[$i++] = "/bin/chown -R $dir_user:$dir_group $backup_dir";
        $_sys_cmd[$i++] = "/bin/chmod -R 0644 $backup_dir";
        $_sys_cmd[$i++] = "/bin/chmod 0755 $backup_dir";
    }

    # Do the deeds:
    foreach $s(@_sys_cmd) {
        &debug_msg("Running: $s \n");
        system($s);
    }

    # Cleanup in CODB:
    ($ok) = $cce->set($cce->event_oid(), 'MYSQL_Vsite', { 'doBackupDB' => '', 'doBackupDBname' => '' });
    if (not $ok) {
        &debug_msg("Cannot clean up  site 'doBackupDBname'.\n");
        exit(1);
    }

    # Done:
    $cce->bye('SUCCESS');
    exit(0);
}
else {
    # We fail softly:
    $cce->bye('SUCCESS');
    exit(0);
}

# Debug:
sub debug_msg {
    if ($MDEBUG) {
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
# Copyright (c) 2008      Brian N. Smith, NuOnce Networks, Inc.
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