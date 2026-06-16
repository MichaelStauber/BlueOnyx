#!/usr/bin/perl -I/usr/sausalito/perl -I.
# $Id: fix_apache_prefork.pl
#
# This constructor checks /etc/httpd/conf.modules.d/00-mpm.conf and makes sure that the
# Apache module mod_mpm_prefork.so is loaded.

use Sauce::Service;
use CCE;

my $cce = new CCE;
$cce->connectuds();

$apache_needs_restart = '0';

# Check /etc/httpd/conf.modules.d/00-mpm.conf
if (-f "/etc/httpd/conf.modules.d/00-mpm.conf") {
    $check = `cat /etc/httpd/conf.modules.d/00-mpm.conf|grep -v ^#|grep "mod_mpm_prefork.so"|wc -l`;
    chomp($check);
    if ($check eq "0") {
        open(my $fh, '>', '/etc/httpd/conf.modules.d/00-mpm.conf');
        print $fh "# Select the MPM module which should be used by uncommenting exactly\n";
        print $fh "# one of the following LoadModule lines.  See the httpd.service(8) man\n";
        print $fh "# page for more information on changing the MPM.\n";
        print $fh "\n";
        print $fh "# prefork MPM: Implements a non-threaded, pre-forking web server\n";
        print $fh "# See: http://httpd.apache.org/docs/2.4/mod/prefork.html\n";
        print $fh "#\n";
        print $fh "# NOTE: If enabling prefork, the httpd_graceful_shutdown SELinux\n";
        print $fh "# boolean should be enabled, to allow graceful stop/shutdown.\n";
        print $fh "#\n";
        print $fh "#LoadModule mpm_prefork_module modules/mod_mpm_prefork.so\n";
        print $fh "\n";
        print $fh "# worker MPM: Multi-Processing Module implementing a hybrid\n";
        print $fh "# multi-threaded multi-process web server\n";
        print $fh "# See: http://httpd.apache.org/docs/2.4/mod/worker.html\n";
        print $fh "#\n";
        print $fh "#LoadModule mpm_worker_module modules/mod_mpm_worker.so\n";
        print $fh "\n";
        print $fh "# event MPM: A variant of the worker MPM with the goal of consuming\n";
        print $fh "# threads only for connections with active processing\n";
        print $fh "# See: http://httpd.apache.org/docs/2.4/mod/event.html\n";
        print $fh "#\n";
        print $fh "LoadModule mpm_event_module modules/mod_mpm_event.so\n";
        print $fh "\n";
        close $fh;
        $apache_needs_restart++;
    }
}

# Apache module proxy_hcheck_module causes Apache to fall flat on its face if H2 is disabled. But with H2 enabled we turn it on again:
if (-f "/etc/httpd/conf.modules.d/00-proxy.conf") {
    $check = `cat /etc/httpd/conf.modules.d/00-proxy.conf|grep -v ^#|grep mod_proxy_hcheck.so|wc -l`;
    chomp($check);
    if ($check eq "1") {
        open(my $fh, '>', '/etc/httpd/conf.modules.d/00-proxy.conf');
        print $fh "# This file configures all the proxy modules:\n";
        print $fh "LoadModule proxy_module modules/mod_proxy.so\n";
        print $fh "LoadModule lbmethod_bybusyness_module modules/mod_lbmethod_bybusyness.so\n";
        print $fh "LoadModule lbmethod_byrequests_module modules/mod_lbmethod_byrequests.so\n";
        print $fh "LoadModule lbmethod_bytraffic_module modules/mod_lbmethod_bytraffic.so\n";
        print $fh "LoadModule lbmethod_heartbeat_module modules/mod_lbmethod_heartbeat.so\n";
        print $fh "LoadModule proxy_ajp_module modules/mod_proxy_ajp.so\n";
        print $fh "LoadModule proxy_balancer_module modules/mod_proxy_balancer.so\n";
        print $fh "LoadModule proxy_connect_module modules/mod_proxy_connect.so\n";
        print $fh "LoadModule proxy_express_module modules/mod_proxy_express.so\n";
        print $fh "LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so\n";
        print $fh "LoadModule proxy_fdpass_module modules/mod_proxy_fdpass.so\n";
        print $fh "LoadModule proxy_ftp_module modules/mod_proxy_ftp.so\n";
        print $fh "LoadModule proxy_http_module modules/mod_proxy_http.so\n";
        print $fh "LoadModule proxy_hcheck_module modules/mod_proxy_hcheck.so\n";
        print $fh "LoadModule proxy_scgi_module modules/mod_proxy_scgi.so\n";
        print $fh "LoadModule proxy_uwsgi_module modules/mod_proxy_uwsgi.so\n";
        print $fh "LoadModule proxy_wstunnel_module modules/mod_proxy_wstunnel.so\n";
        print $fh "\n";
        close $fh;
        $apache_needs_restart++;
    }
}

# Create directory for disabled stock module configs if it doesn't exist yet:
if (! -d '/etc/httpd/conf.modules.d.disabled') {
    system("mkdir /etc/httpd/conf.modules.d.disabled");
}

# Disable 10-mod_ruid2.conf:
if (-f "/etc/httpd/conf.modules.d/10-mod_ruid2.conf") {
    system("mv /etc/httpd/conf.modules.d/10-mod_ruid2.conf /etc/httpd/conf.modules.d.disabled/10-mod_ruid2.conf");
    $apache_needs_restart++;
}

### On 5211R we want these active:
# 
# # Disable h2 module:
# if (-f "/etc/httpd/conf.modules.d/10-h2.conf") {
#     system("mv /etc/httpd/conf.modules.d/10-h2.conf /etc/httpd/conf.modules.d.disabled/10-h2.conf");
#     $apache_needs_restart++;
# }
# 
# # Disable h2-proxy module:
# if (-f "/etc/httpd/conf.modules.d/10-proxy_h2.conf") {
#     system("mv /etc/httpd/conf.modules.d/10-proxy_h2.conf /etc/httpd/conf.modules.d.disabled/10-proxy_h2.conf");
#     $apache_needs_restart++;
# }

# Conditionally restart Apache if we did something that warrants a restart:
if ($apache_needs_restart gt '0') {
    # Restart Apache:
    Sauce::Service::service_run_init('httpd', 'restart');
}

$cce->bye('SUCCESS');
exit 0;

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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
