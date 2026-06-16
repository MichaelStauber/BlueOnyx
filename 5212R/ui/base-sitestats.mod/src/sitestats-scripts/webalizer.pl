#!/usr/bin/perl -I/usr/sausalito/perl
#
# This script runs webalizer for every Vsite on this BlueOnyx.
# This depends on the configuration file /etc/webalizer.conf

use CCE;
my $cce = new CCE;
$cce->connectuds();

use Base::HomeDir qw(homedir_get_group_dir);

# Root check:
my $id = `id -u`;
chomp($id);
if ($id ne "0") {
    print "$0 must be run by user 'root'!\n";
    $cce->bye('FAIL');
    exit(1);
}

# Find all Vsites:
my $messages = '';
my @vhosts = ();
my (@vhosts) = $cce->findx('Vsite');

# Walk through all Vsites:
for my $vsite (@vhosts) {
    ($ok, my $my_vsite) = $cce->get($vsite);

    my $site_dir = homedir_get_group_dir($my_vsite->{name}, $my_vsite->{volume});

    $webpath =   "$site_dir/wwwroot/web";
    $logpath =   "$site_dir/var/logs/web.log";
    $statspath = "$site_dir/var/webalizer";

    # Create a directory $site_dir/var/webalizer if it isn't there yet:
    if (!-d $statspath) {
      system("mkdir $statspath");
      system("chmod 0755 $statspath");
      system("chown root:root $statspath");
      $message .= "Created directory $statspath for Vsite " . $my_vsite->{fqdn} ."\n";
    }

    if (-f $logpath) {
      $messages .= `/usr/bin/webalizer -p -n $my_vsite->{fqdn} -s $my_vsite->{fqdn} -r $my_vsite->{fqdn} -T -o $statspath $logpath`;
    }
    else {
      $messages .= "No log file $logpath for Vsite " . $my_vsite->{fqdn} ."\n";
    }
}

open(LOGFILE, ">>/var/log/webalizer.log");
print LOGFILE $messages;
close(LOGFILE);

# Tell cce everything is okay
$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2020-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2020-2022 Team BlueOnyx, BLUEONYX.IT
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