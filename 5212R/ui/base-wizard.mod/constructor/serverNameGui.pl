#!/usr/bin/perl -I/usr/sausalito/perl
#
# $Id: serverNameGui.pl
#
# Configure the CodeIgniter 4 server name in /usr/sausalito/ui/chorizo/ci4/.env
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debugging enabled for serverNameGui.pl\n");
}

use CCE;
use Sauce::Service;
use Sauce::Util;

# defines that should probably go elsewhere
my $GUI_env = '/usr/sausalito/ui/chorizo/ci4/.env';
my $admserv_port_cfg = '/etc/admserv/conf.d/admserv.conf';
my $index_html = '/var/www/html/index.html';
my $shellinabox_cfg = '/etc/admserv/conf.d/shellinabox.conf';

my $cce = new CCE(Namespace => "Time");
$cce->connectuds();

# Get system and network object ids:
my ($system_oid) = $cce->find("System");

# get system object:
my ($ok, $System) = $cce->get($system_oid);
if (!$ok) {
    $cce->bye('FAIL');
    exit(1);
}

&debug_msg("Startup of serverNameGui.pl.\n");

my $hostname = $System->{hostname};
my $domainname = $System->{domainname};

my $fqdn = $hostname . '.' . $domainname;
&debug_msg("hostname: $hostname - domainname: $domainname - fqdn: $fqdn\n");

my $GUI_PORT = $System->{GUI_PORT} || 81;
my $GUI_URLs = $System->{GUI_URLs};

&debug_msg("GUI_PORT: $GUI_PORT - GUI_URLs: $GUI_URLs\n");

$check_conf = `cat $GUI_env|grep ^app.baseURL|grep $fqdn|wc -l`;
chomp($check_conf);

$config_update_required = '0';
if ($check_conf eq '0') {
    $config_update_required = '1';
    &debug_msg("Need to modify FQDN in $GUI_env\n");
}

if (($hostname ne '') && ($domainname ne '') && ($GUI_PORT ne '')) {
    # We have a name
    &debug_msg("Editing $GUI_env to set FQDN: $hostname " . '.' . "$domainname\n");
    if (!Sauce::Util::editfile($GUI_env, *update_gui_env_conf, $hostname, $domainname, $GUI_PORT)) {
        $cce->warn("[[base-time.errorWritingConfFile]]");
    }
}
else {
    &debug_msg("NOT editing $GUI_env to set FQDN: $hostname " . '.' . "$domainname\n");
}

system("rm -f /usr/sausalito/ui/chorizo/ci4/.env.backup.*");

&debug_msg("Editing $admserv_port_cfg to set GUI port to $GUI_PORT\n");

# Write out new /etc/admserv/conf.d/admserv.conf with the primary LISTEN port for the GUI:
open(my $fh, '>', $admserv_port_cfg) or die "Could not open file '$admserv_port_cfg' $!";

# Writing the desired string to the file
print $fh "Listen $GUI_PORT\n";

# Closing the file
close $fh;

# Fix perms:
chmod(0644, $admserv_port_cfg);

#
### Handle /etc/admserv/conf.d/shellinabox.conf:
#

&debug_msg("Editing $shellinabox_cfg to set GUI port to $GUI_PORT\n");

open(my $fh, '<', $shellinabox_cfg) or die "Could not open file '$shellinabox_cfg' $!";
my @lines = <$fh>;
close($fh);

# Open the same file for writing
open(my $fh_out, '>', $shellinabox_cfg) or die "Could not open file '$shellinabox_cfg' $!";
foreach my $line (@lines) {
    # Check if the line contains the redirect with a port number
    if ($line =~ /Redirect permanent \/bxshell https:\/\/%\{HTTP_HOST\}:\d+\/bxshell/) {
        # Replace the line with the new port number
        $line = "Redirect permanent /bxshell https://%{HTTP_HOST}:$GUI_PORT/bxshell\n";
    }
    print $fh_out $line;
}
close($fh_out);

# Fix perms:
chmod(0644, $shellinabox_cfg);

#
### Reload AdmServ:
#

# Reload AdmServ directly with no roundtrip through Sauce::Service:
system("/usr/bin/systemctl reload admserv");

&debug_msg("Length GUI_URLs: " . length($System->{GUI_URLs}));

if (!length($System->{GUI_URLs}) || $System->{GUI_URLs} =~ /\&\&/) {
    # Our 'GUI_URLs' is empty. Set it to the defaults IF initial setup was never completed:
    if ($System->{'isLicenseAccepted'} eq '0') {
        $cce->set($System->{OID}, '', {'GUI_URLs' => '&login&'});
        $System->{GUI_URLs} = '&login&';
        &debug_msg("Empty GUI_URLs. Fixing that. \n");
    }
}

if (-f $index_html) {
    &debug_msg("Editing $index_html to set GUI port to $GUI_PORT\n");

    # Write out new /var/www/html/index.html with the primary LISTEN port for the GUI:
    open(my $fh, '>', $index_html) or die "Could not open file '$index_html' $!";

    # Writing the content:
    print $fh '<HTML>' . "\n";
    print $fh '<HEAD>' . "\n";
    print $fh '<META HTTP-EQUIV="expires" CONTENT="-1">' . "\n";
    print $fh '<META HTTP-EQUIV="Pragma" CONTENT="no-cache">' . "\n";
    print $fh '</HEAD>' . "\n";
    print $fh '<BODY onLoad="location=\'https://\'+location.host+\':' . $GUI_PORT . '/\'">' . "\n";
    print $fh '</BODY>' . "\n";
    print $fh '</HTML>' . "\n";

    # Closing the file
    close $fh;

    # Fix perms:
    chmod(0644, $index_html);
}
else {
    &debug_msg("The file $index_html is not present. Skipping edit to set GUI port to $GUI_PORT\n");
}

# Reload Apache directly with no roundtrip through Sauce::Service:
system("/usr/bin/systemctl reload httpd");

&debug_msg("End of serverNameGui.pl reached.\n");

$cce->bye('SUCCESS');
exit 0;


#
### Subroutines:
#

sub update_gui_env_conf {
    my ($fin, $fout, $hostname, $domainname, $port) = @_;
    &debug_msg("Have order to set: " . 'app.baseURL = \'https://' . $hostname . '.' . $domainname . ':' . $port . '/\'' . "\n");
    while (<$fin>) {
        if (/^app\.baseURL(.*)$/) {
            # Replace the app.baseURL line.
            &debug_msg("Found 'app.baseURL' - replacing it.\n");
            print $fout 'app.baseURL = \'https://' . $hostname . '.' . $domainname . ':' . $port . '/\'' . "\n";
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
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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
