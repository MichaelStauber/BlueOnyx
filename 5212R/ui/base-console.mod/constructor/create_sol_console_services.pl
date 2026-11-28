#!/usr/bin/perl
# $Id: create_sol_console_services.pl
# Copyright 2006-2026 Solarspeed.net, All rights reserved.
# Copyright 2008-2026 Team BlueOnyx, All rights reserved.
#
# Creates required objects used to register services.
#

use lib qw(/usr/sausalito/perl);
use CCE;
use Sauce::Util;

my $cce = new CCE;

$cce->connectuds();

# make sure the SOL_Console object exists
if (not scalar($cce->find('SOL_Console'))) {
	my ($ok) = $cce->create('SOL_Console');
	if (not $ok) {
		$cce->bye('FAIL', 'System object SOL_Console does not exist!');
		exit(1);
	}
}

# make sure the pam_abl_settings object exists
if (not scalar($cce->find('pam_abl_settings'))) {
	my ($ok) = $cce->create('pam_abl_settings');
	if (not $ok) {
		$cce->bye('FAIL', 'System object pam_abl_settings does not exist!');
		exit(1);
	}
}

# Ensure localhost IPv4 + IPv6 on whitelist (CCE + pam_abl.conf).
# Do NOT fire update_config from inside cce_construct (can deadlock).
migrate_pam_abl_localhost_whitelist($cce);

$cce->bye('SUCCESS');
exit(0);

sub migrate_pam_abl_localhost_whitelist {
	my ($cce) = @_;

	my ($oid) = $cce->find('pam_abl_settings');
	return if (!$oid);

	my ($ok, $abl) = $cce->get($oid);
	return if (!$ok);

	my @wl = $cce->scalar_to_array($abl->{host_whitelist} || '');
	my %seen;
	my @out;
	my $changed = 0;

	foreach my $e (@wl) {
		next if (!defined($e));
		$e =~ s/^\s+|\s+$//g;
		next if ($e eq '');
		if ($e eq '127.0.0.1') {
			$e = '127.0.0.1/32';
			$changed = 1;
		}
		if (($e eq '::1') || ($e eq '0:0:0:0:0:0:0:1') || ($e eq '0:0:0:0:0:0:0:1/128')) {
			$e = '::1/128';
			$changed = 1;
		}
		my $key = $e;
		$key =~ tr/A-F/a-f/;
		next if ($seen{$key}++);
		push @out, $e;
	}

	foreach my $must (qw(127.0.0.1/32 ::1/128)) {
		my $key = $must;
		$key =~ tr/A-F/a-f/;
		if (!$seen{$key}) {
			push @out, $must;
			$seen{$key} = 1;
			$changed = 1;
		}
	}

	return if (!$changed);

	my $scalar = $cce->array_to_scalar(@out);
	# Update CCE only - do not set update_config here (construct-safe).
	my ($sok) = $cce->set($oid, '', { 'host_whitelist' => $scalar });
	# Always merge into on-disk conf even if schema reject (old type still loaded).
	my $conf = '/etc/security/pam_abl.conf';
	if (-f $conf) {
		my $wl_str = join(';', @out) . ';';
		my $purge = $abl->{host_purge} || '1d';
		my $rule  = $abl->{host_rule}  || '*:30/1h';
		Sauce::Util::editfile($conf, *write_pam_abl_conf, $purge, $rule, $wl_str);
	}
}

sub write_pam_abl_conf {
	my ($in, $out, $purge, $rule, $wl) = @_;
	print $out <<EOF;
db_home=/var/lib/pam_abl
host_db=/var/lib/pam_abl/hosts.db
host_purge=$purge
host_rule=$rule
#user_db=/var/lib/pam_abl/users.db
user_purge=1d
user_rule=*:3/1h
host_clear_cmd=[/usr/bin/logger] [clear] [host] [%h]
host_block_cmd=[/usr/bin/logger] [block] [host] [%h]
user_clear_cmd=[/usr/bin/logger] [clear] [user] [%u]
user_block_cmd=[/usr/bin/logger] [block] [user] [%u]
limits=1000-1200
host_whitelist=$wl
user_whitelist=admin
EOF
	return 1;
}
