Summary: Active Monitor support for base-shell-am
Name: base-shell-am
Version: 1.0.0
Release: 4%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-shell-am.tar.gz
BuildRoot: /tmp/base-shell-am

%prep
%setup -n base-shell-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-shell-am.  

%changelog

* Tue Oct 20 2026 Greg Kuhnert <gkuhnert@compassnetworks.com.au> 1.0.0-4
- Modified am_jailkit.pl: an empty /etc/jailkit/jk_socketd.ini now means
  "nothing to broker" and is reported as AM_STATE_NOINFO (not applicable,
  the state the other monitors use when their service is deliberately not
  in play) without a restart attempt, using the same -s /
  ConditionFileNotEmpty test the unit's start condition uses, so the unit
  and the monitor cannot disagree about what "empty" means.
- Modified am_jailkit.pl: the post-restart verdict used service_get_init(),
  which is systemctl is-enabled - that reports whether the unit starts at
  boot, not whether the daemon is running, so the result was inverted: RED
  for a running daemon on a disabled unit, GREEN for a dead daemon on an
  enabled one. It now probes the process, as the top of the script already
  did.

* Sat Nov 19 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.0-3
- Release version for BlueOnyx 5211R

* Wed Jul 10 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- First build

