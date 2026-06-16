#!/usr/bin/perl

package Sauce::Service;
use vars qw(@ISA @EXPORT @EXPORT_OK);
require Exporter;
@ISA =    qw(Exporter);
@EXPORT = qw(service_get_init  service_set_init  service_run_init 
         service_toggle_init service_get_state
         service_get_inetd service_set_inetd service_restart_inetd
         service_get_multi_inetd service_set_multi_inetd
         service_get_xinetd service_set_xinetd service_restart_xinetd
         service_get_multi_xinetd service_set_multi_xinetd
         service_send_signal 
        );

use lib '/usr/sausalito/perl';
use Sauce::Util;
use Sauce::Service::Client;

# Debugging switch:
$DEBUG = 0;
if ($DEBUG)
{
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Find out Platform
$OLD_BLUEONYX = 0;
$BUILD = `grep -E '5209R|5210R' /etc/build |wc -l`;
chomp($BUILD);
if ($BUILD == 1) {
    #
    ### Older BlueOnyx (before 5211R):
    #
    $OLD_BLUEONYX = 1;
}
else {
    #
    ### Modern BlueOnyx (5211R and later):
    #
    $OLD_BLUEONYX = 0;
}

1;

sub inetd_conf { return '/etc/inetd.conf' };
sub inetd_perm { return 0644; };

sub xinetd_conf { return '/etc/xinetd.conf' };
sub xinetd_conf_dir { return '/etc/xinetd.d' };
sub xinetd_perm { return 0644; };

&debug_msg("OLD_BLUEONYX: $OLD_BLUEONYX"); 

sub service_run_init
# changes the init state. it does this in the background by default
# arguments: file, arguments ('start', 'stop', or 'restart') and
# optionally 'nobg' as third parameter to not shoot the call into 
# the background.
{
    my ($service, $arg, $options) = @_;
    my $pid;

    #
    ### Special case xinetd:
    #

    if ((($service =~ /\bxinetd\b/) || ($service =~ /\binetd\b/)) && ($OLD_BLUEONYX == 0)) {
        $DEBUG = 1;
        &debug_msg("Special case: $service $arg - Skipping."); 
        $DEBUG = 0;
        return 1;
    }

    #
    ### Special case Apache, Nginx, FPM:
    #

    # Define services and their matching regex patterns
    my %service_patterns = (
        'httpd' => qr/\bhttpd\b/,
        'nginx' => qr/\bnginx\b/,
        'php'   => qr/^php/,
    );

    # Handle special cases for service actions
    for my $service_key (keys %service_patterns) {
        if ($service =~ $service_patterns{$service_key} && ($arg eq 'restart' || $arg eq 'reload')) {
            my $state = service_get_state($service);
            
            if ($arg eq 'restart') {
                if ($state eq '1') {
                    &debug_msg("Special case: $service restart - changing to reload (running)");
                    $arg = 'reload';
                }
                else {
                    &debug_msg("Special case: $service restart - keeping restart (stopped/failed)");
                    $arg = 'restart';  # Explicit for clarity, though already set
                }
            }
            elsif ($arg eq 'reload') {
                if ($state eq '1') {
                    &debug_msg("Special case: $service reload - keeping reload (running)");
                    $arg = 'reload';  # Explicit for clarity, though already set
                }
                else {
                    &debug_msg("Special case: $service reload - changing to restart (stopped/failed)");
                    $arg = 'restart';
                }
            }
        }
    }

    #
    ### Special case Sendmail/Postfix:
    #

    if (($service =~ /\bsendmail\b/) || ($service =~ /\bpostfix\b/)) {

        &debug_msg("Special case ($service): $arg\n");
    
        # Check which MTA is actually configured to run:
        if (-f '/etc/sysconfig/bxmta') {
            $MTA=`cat /etc/sysconfig/bxmta |grep ^MTA=|cut -d = -f2`;
            chomp($MTA);
            $MTA = lc $MTA;
        }
        else {
            $MTA = 'sendmail';
        }
    
        &debug_msg("Action $arg of MTA $service requested - performing $arg of service $MTA\n");
    
        # Redefine $service to the real service:
        $service = $MTA;
    }

    #
    ### Special case cced.init:
    #

    if ((($service eq 'cced.init') || ($service eq 'cced')) && (-f '/usr/bin/systemctl')) {
        if (-f '/usr/sausalito/sbin/cced.init') {
            &debug_msg("Special case cced.init $arg: Using /usr/sausalito/sbin/cced.init $arg");
            $wtf = `/usr/sausalito/sbin/cced.init $arg`;
            chomp($wtf);
            &debug_msg("Result: $wtf");
            # Return 1 on success instead of the standard unix command 0
            if ($wtf == 0) {
                return 1;
            }
            return 0;
        }
    }

    #
    ### Actual processing: 'nobg' executes directly, if not specified queue the call instead and execute it in the background:
    #

    if (defined($options) && $options =~ /\bnobg\b/) {
        # Restarts Service:
        &debug_msg("Got 'nobg' request. Running: systemctl $arg $service.service directly"); 
        system("/usr/bin/systemctl $arg $service.service"); 
        # Return 1 on success instead of the standard unix command 0
        if ($? == 0) {
            return 1;
        }
        return 0;
    }
    else {
        &debug_msg("Registering Queue Event to get $service to $arg\n");

        my $ssc = Sauce::Service::Client->new();

        if (!$ssc) {
            debug_msg("Failed to create Sauce::Service::Client object.\n");
            return(1);
        }

        if (!$ssc->register_event($service, $arg)) {
            &debug_msg("Failed to 'register_event' with Sauce::Service::Client\n");
            return(0);
        }
        return(1);
    }

    #
    ### Give a proper return signal:
    #

    exit 0 unless (($options // '') =~ /\bnobg\b/);
}

sub service_toggle_init
# toggle the service
# arguments: $service, $newstate
{
    my ($service, $new, $options) = @_;

    if (($service eq "sendmail") || ($service eq "postfix")) {
    
        &debug_msg("Special case ($service): $arg\n");
    
        # Check which MTA is actually configured to run:
        if (-f '/etc/sysconfig/bxmta') {
            $MTA=`cat /etc/sysconfig/bxmta |grep ^MTA=|cut -d = -f2`;
            chomp($MTA);
            $MTA = lc $MTA;
        }
        else {
            $MTA = 'sendmail';
        }
    
        &debug_msg("Auto-Start $new of MTA $service requested - performing it for service $MTA instead.\n");
    
        # Redefine $service to the real service:
        $service = $MTA;
    }

    if (($new eq "1") || ($new eq "on")) {
        &debug_msg("Running: service_set_init($service, 'on')"); 
        service_set_init($service, 'on');
        #&debug_msg("Running: service_run_init($service, 'restart', $options)"); 
        #service_run_init($service, 'restart', $options);
    } else {
        &debug_msg("Running: service_set_init($service, 'off')"); 
        service_set_init($service, 'off');
        &debug_msg("Running: service_run_init($service, 'stop', $options)"); 
        service_run_init($service, 'stop', $options);
    }
}


sub service_get_init
# get the state of the service in the given runlevel
# arguments: state, runlevel (defaults to 3 if not specified)
{
    my ($service, $state) = @_;
    $state ||= 3;
    my $return = -1;

    if (-f "/usr/bin/systemctl") {
        # Got Systemd:
        my $status = `/usr/bin/systemctl is-enabled $service`;
        if ($status) {
            $return = ($status =~ /^enabled/) ? 1 : 0;
        }
    }
    else {
        # Thank God, no Systemd. Still sucks enough to get the state:
        $return = "0";
        # This is a clear case of "YGBSM!". But if it works ... then we'll use it:
        my $status = `ls -la /etc/rc$state.d/S*|grep ^lrwxrwxrwx|grep -E "S*$service ->"|wc -l`;
        chomp($status);
        if ($status eq "1") {
            $return = "1";
        }
    }
    return $return;
}

sub service_get_state
# Find out if a service is currently running and healthy.
# arguments: service, runlevel (defaults to 3 if not specified)
{
    my ($service, $state) = @_;
    $state ||= 3;

    # Check if service is active
    my $active_output = qx{systemctl is-active $service --wait};
    chomp($active_output);
    
    # Check if service has failed
    my $failed_output = qx{systemctl is-failed $service --wait};
    chomp($failed_output);

    if ($active_output eq 'active' && $failed_output ne 'failed') {
        # Service is running and not failed:
        &debug_msg("service_get_state reports: Service $service is in state '1' (running)");
        return 1;
    }
    else {
        # Service is either not active or has failed:
        &debug_msg("service_get_state reports: Service $service is in state '0' (active: $active_output, failed: $failed_output)");
        return 0;
    }
}

sub service_set_init
# set the given service state to on or off
# arguments: service name, state, list of runlevels
{
    my ($service, $state, @runlevels) = @_;
    my $level;

    if ($state eq "1") {
        $state = 'on';
    }
    if ($state eq "0") {
        $state = 'off';
    }

    if (@runlevels) {
        $level = ' --level ';
        $level .= join('',@runlevels);
        $state = 'off' unless $state eq 'on';

        # Define Systemd state:
        my $SystemdState = 'disable';
        if ($state eq "on") {
            $SystemdState = 'enable';
        }

        # Set state:
        if (-f "/usr/bin/systemctl") {
            &debug_msg("1. Running: /usr/bin/systemctl $SystemdState $service.service"); 
            `/usr/bin/systemctl $SystemdState $service.service`;
        }
        else {
            &debug_msg("1. Running: /sbin/chkconfig $level $service $state"); 
            `/sbin/chkconfig $level $service $state`;
        }
    } else {
        if (service_get_init($service) == -1) {
            # Set state:
            if (-f "/usr/bin/systemctl") {
                &debug_msg("2. Running: /usr/bin/systemctl enable $service.service"); 
                `/usr/bin/systemctl enable $service.service`;
            }
            else {
                &debug_msg("2. Running: /sbin/chkconfig --add $service");
                `/sbin/chkconfig --add $service`;
            }
        }

        # Define Systemd state:
        my $SystemdXState = 'disable';
        my $cmd = 'off';
        if ($state eq "on") {
            $SystemdXState = 'enable';
            $cmd = 'on';
        }

        # Set state:
        if (-f "/usr/bin/systemctl") {
            &debug_msg("3. Running: /usr/bin/systemctl $SystemdXState $service.service");
            `/usr/bin/systemctl $SystemdXState $service.service`;
        }
        else {
            &debug_msg("3. Running: /sbin/chkconfig $service $cmd");
            `/sbin/chkconfig $service $cmd`;
        }
    }

    #
    # chkconfig returns 0 on success, while this routine should return
    # 1 (the perl standard)
    #
    if ($? == 0) {
        return 1;
    }
    return 0;
}

sub service_get_inetd
# get the state of the service in inetd.conf
# arguments: service
{
    my $service = shift;

    open(INETD, inetd_conf());
    while (<INETD>) {
        next unless /\s*(\#*)\s*$service\s/;
        close(INETD);
        return $1 =~ /\#/ ? 0 : 'on';
    }
    close(INETD);
}

sub service_get_multi_inetd
# get the state of the service in inetd.conf
# arguments: list of settings
# returns hash of settings/values
{
    my @list = @_;
    my $conf = inetd_conf();
    my $services = ',' . join(',', @list) . ',';
    my ($set, $service, %settings);

    open(INETD, $conf);
    while (<INETD>) {
        next unless /\s*(\#*)\s*(\S+)\s/;
        ($set, $service) = ($1, $2);
        next unless $services =~ /,$service,/;
        $settings{$service} = ($set =~ /\#/ ? 0 : 'on') unless $settings{$service};
    }
    close(INETD);

    return %settings;
}

sub _edit_inetd
{
    my ($input, $output, $service, $enabled, $rate) = @_;
    while (<$input>) {
        if (/^[\s\#]*($service\s.*)/) {
            my $service_record = $1;
            $service_record =~ s/wait(\.*\d*)(\s)/wait\.$rate$2/ if ($rate);

            print $output ($enabled eq 'on') ? $service_record : "# $service_record";
            print $output "\n";
            next;
        }
        print $output $_;
    }
    return 1;
}
    
sub _edit_multi_inetd
{
    my ($input, $output, %settings) = @_;
    my $services = ',' . join(',', keys %settings) . ',';
    my ($service, $rest);
    
    while (<$input>) {
        if (/^[\s\#]*(\S+)(\s.*)/) {
            ($service, $rest) = ($1, $2);
            if ($services =~ /,$service,/) {
                print $output ($settings{$service} eq 'on') ? "$service$rest" : "# $service$rest";  
                print $output "\n";
                next;
            }
        }
        print $output $_;
    }
    return 1;
}
    
sub service_set_inetd
# sets the state of the service in inetd.conf
# arguments: service, state
{
    my ($service, $state, $rate) = @_;

    my $ret = Sauce::Util::editfile(inetd_conf(), *_edit_inetd, $service, $state, $rate);
    chmod(inetd_perm(), inetd_conf());
    return $ret;
}

sub service_set_multi_inetd
# set the state of the service in inetd.conf
# arguments: hash of services/settings
{
    my $ret = Sauce::Util::editfile(inetd_conf(), *_edit_multi_inetd, @_);
    chmod(inetd_perm(), inetd_conf());
    return $ret;
}


sub service_send_signal
# send a signal to a process
{
    my ($service, $signal) = @_;
    $signal =~ tr/[a-z]/[A-Z]/;
    `killall -$signal $service`;
}

sub service_restart_inetd
# send sighup to inetd so it rereads /etc/inetd.conf
# this is a backwards compatibility routine. 
{

    #
    ### Special case inetd:
    #

    if ((($service =~ /\bxinetd\b/) || ($service =~ /\binetd\b/)) && ($OLD_BLUEONYX == 0)) {
        &debug_msg("Special case: $service $arg - This version of BlueOnyx has no $service. Not doing anything."); 
        return 0;
    }

    service_send_signal('inetd', 'HUP');
}


## functions for xinetd
## Auther: Hisao SHIBUYA <shibuya@alpha.or.jp>
# service_get_xinetd, service_get_multi_xinetd, _edit_xinetd
# service_set_xinetd, service_set_multi_xinetd, service_restart_xinetd

sub service_get_xinetd
# get the state of the service in inetd.conf
# arguments: service
{
    my $service = shift;
    my $conf_file = xinetd_conf_dir() . "/$service";
 
    open(SERVICE, $conf_file);
    while (<SERVICE>) {
        next unless /^\s*(disable)\s/;
        close(SERVICE);
        return $_ =~ /yes/ ? 0 : 'on';
    }
    close(SERVICE);
}
 
sub service_get_multi_xinetd
# get the state of the service in xinetd.d/*
# arguments: list of settings
# returns hash of settings/values
{
        my @list = @_;
        my ($service,%settings);
 
        for ($i=0; $i<@list; $i++) {
                $service = $list[$i];
                $settings{$service} = service_get_xinetd($service);
        }
 
        return %settings;
}
 
sub _edit_xinetd
{
        my ($input, $output, $service, $enabled, $rate) = @_;
        my $instances_done = 'false';

        while (<$input>) {
            if (/^(\s*disable\s.*=)\s/) {
                print $output ($enabled eq 'on') ? "$1 no" : "$1 yes";
                print $output "\n";
                next;
            }
            if (/^(\s*instances\s.*=)\s/ && $rate ne '') {
                print $output "$1 $rate";
                print $output "\n";
                $instances_done = 'true';
                next;
            }
            if (/^}$/ && $instances_done eq 'false' && $rate ne '') {
                print $output "\tinstances = $rate";
                print $output "\n}\n";
                next;
            }
            print $output $_;
        }
        return 1;
}
 
sub service_set_xinetd
# set the state of the service in xinetd.d/*
# arguments: service, state
{

        #
        ### Special case xinetd:
        #

        if ((($service =~ /\bxinetd\b/) || ($service =~ /\binetd\b/)) && ($OLD_BLUEONYX == 0)) {
            &debug_msg("Special case: $service $arg - This version of BlueOnyx has no $service. Not doing anything."); 
            return 0;
        }

        my ($service, $state, $rate) = @_;
        my $conf_file = xinetd_conf_dir() . "/$service";
        my $ret = Sauce::Util::editfile($conf_file, *_edit_xinetd, $service, $state, $rate);
        chmod(xinetd_perm(), $conf_file);
        return $ret;
}
 
sub service_set_multi_xinetd
# set the state of the service in xinetd.d/*
# arguments: hash of services/settings
{
        my %settings = @_;
 
        foreach my $key (keys %settings) {
                my $conf_file = xinetd_conf_dir() . "/$key";
                my $ret = Sauce::Util::editfile($conf_file, *_edit_xinetd, $key, $settings{$key});
                chmod(xinetd_perm(), $conf_file);
        }
        return 0;
}
 
sub service_restart_xinetd
# send sighup to inetd so it rereads /etc/inetd.d/*
# this is a backwards compatibility routine.
{

        #
        ### Special case xinetd:
        #

        if ((($service =~ /\bxinetd\b/) || ($service =~ /\binetd\b/)) && ($OLD_BLUEONYX == 0)) {
            &debug_msg("Special case: $service $arg - This version of BlueOnyx has no $service. Not doing anything."); 
            return 0;
        }

        service_send_signal('xinetd', 'HUP');
}

# Debug:
sub debug_msg {
    if ($DEBUG == 1) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "Service.pm: $msg");
        closelog;
    }
    else {
        # If debugging is disabled, we still need to do *something* in this routine
        # or we get a 'Sauce/Service.pm did not return a true value ...' error message. 
        my $msg = shift;
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