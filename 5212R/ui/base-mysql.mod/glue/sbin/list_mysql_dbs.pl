#!/usr/bin/perl -I/usr/sausalito/perl

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use warnings;
use JSON;
use DBI;

my $cce = new CCE;
$cce->connectuds();

# Get System Information
my @soids = $cce->find("System");
my ($ok, $system) = $cce->get($soids[0]);

# Get MySQL Information
my @oids = $cce->find("MySQL");
my ($mok, $MySQL) = $cce->get($oids[0]);

# MySQL database configuration
my $dbhost = $MySQL->{'sql_host'};
my $dbuser = $MySQL->{'sql_root'};
my $dbpass =$MySQL->{'sql_rootpassword'};

# Find all Vsites:
my @vhosts = ();
(@vhosts) = $cce->findx('Vsite');

# Start sane:
my @allready_assigned_DBs = ();
my @nwa_DBs = ();

# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, $MYSQL_Vsite) = $cce->get($vsite, 'MYSQL_Vsite');
    if ($MYSQL_Vsite->{'DB'} ne '') {
        push @allready_assigned_DBs, $MYSQL_Vsite->{'DB'};
    }

    if (($MYSQL_Vsite->{'DBmulti'} ne '') && ($MYSQL_Vsite->{'DBmulti'} ne '&&')) {
        my @ExtraDBs = $cce->scalar_to_array($MYSQL_Vsite->{'DBmulti'});
        foreach my $x (@ExtraDBs) {
            push @allready_assigned_DBs, $x;
        }
    }
}

# Connect to MySQL database
my $dbh = DBI->connect("DBI:mysql:host=$dbhost", $dbuser, $dbpass) or die "Cannot connect to database: $DBI::errstr";

# Retrieve list of databases
my $query = "SHOW DATABASES";
my $sth = $dbh->prepare($query);
$sth->execute();

# Fetch database names and store in an array
my @databases;
while (my $row = $sth->fetchrow_arrayref) {
    my $database = $row->[0];
    next if $database eq 'avspam6';
    next if $database eq 'avspam7';
    next if $database eq 'information_schema';
    next if $database eq 'performance_schema';
    next if $database eq 'mysql';
    next if $database eq 'test';
    next if (in_array(\@allready_assigned_DBs, $database));
    if ($database =~ /^nwa_(.*)$/) {
        push @nwa_DBs, $database;
    } 
    else {
        push @databases, $database;
    }
}

# Disconnect from MySQL database
$sth->finish;
$dbh->disconnect;

# Encode array as JSON
my $json = encode_json(\@databases);

# Print JSON output
print $json;

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub in_array {
    my ($arr,$search_for) = @_;
    my %items = map {$_ => 1} @$arr; # create a hash out of the array values
    return (exists($items{$search_for}))?1:0;
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
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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
