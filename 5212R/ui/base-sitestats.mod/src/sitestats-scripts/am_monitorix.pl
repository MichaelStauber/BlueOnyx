#!/usr/bin/perl -I/usr/sausalito/perl

# System name of the service:
$service = 'monitorix';

# Debugging switch:
$DEBUG = "0";
if ($DEBUG)
{
        use Sys::Syslog qw( :DEFAULT setlogsock);
}

use AM::Util;
use Sauce::Service;
use CCE;

my $cce = new CCE;
$cce->connectuds();

my %am_states = am_get_statecodes();

# Check if the service is enabled (init + systemd).
$enabled = Sauce::Service::service_get_init($service);
my $sys_en = `systemctl is-enabled $service.service 2>/dev/null`;
chomp($sys_en);

# Deliberately disabled: NOINFO, do not force-enable (jailkit pattern).
# First-boot enable is initServices.sh. Credit: Greg Kuhnert.
if ($enabled eq "0" || $sys_en eq "disabled" || $sys_en eq "masked") {
    &debug_msg("Service $service is not enabled ($sys_en) — NOINFO, leave alone.\n");
    print $ENV{greenMsg} if defined $ENV{greenMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_NOINFO};
}

$status = `systemctl is-active $service.service 2>/dev/null`;
chomp($status);
if ($status eq "active") {
    &debug_msg("Service $service is working. \n");
    print $ENV{greenMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_GREEN};
}

&debug_msg("Service $service has failed and we are restarting it. \n");
Sauce::Service::service_run_init($service, 'restart');
sleep 12;
$status = `systemctl is-active $service.service 2>/dev/null`;
chomp($status);
if ($status ne "active") {
    &debug_msg("Service $service has failed. \n");
    print $ENV{redMsg};
    $cce->bye('SUCCESS');
    exit $am_states{AM_STATE_RED};
}
print $ENV{greenMsg};
$cce->bye('SUCCESS');
exit $am_states{AM_STATE_GREEN};

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}
