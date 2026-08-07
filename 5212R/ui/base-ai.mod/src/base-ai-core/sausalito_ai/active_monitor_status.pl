#!/usr/bin/perl

use strict;
use warnings;
use lib qw(/usr/sausalito/perl);
use CCE;
use JSON::XS;

my $login = (getpwuid $>);
die "must run as root\n" if (!defined($login) || $login ne 'root');

my $cce = CCE->new();
$cce->connectuds();

my ($sys_oid) = $cce->find('System');
my ($am_oid) = $cce->find('ActiveMonitor');

my %out = (
    system_oid => ($sys_oid || 0),
    active_monitor_oid => ($am_oid || 0),
    system => {},
    active_monitor => {},
);

if ($sys_oid) {
    for my $ns (qw(DNS Email Ftp SSH)) {
        my ($ok, $obj) = $cce->get($sys_oid, $ns);
        $out{system}{$ns} = $obj if ($ok && $obj);
    }
}

if ($am_oid) {
    for my $ns (qw(DNS Email FTP mysql SSH)) {
        my ($ok, $obj) = $cce->get($am_oid, $ns);
        $out{active_monitor}{$ns} = $obj if ($ok && $obj);
    }
}

$cce->bye('SUCCESS');

my $coder = JSON::XS->new->utf8->allow_nonref;
print $coder->encode(\%out) . "\n";

exit(0);
