Summary: BlueOnyx JailKit helpers
Name: base-shell-jailer
Version: 1.0.0
Release: 2%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-shell-jailer.tar.gz
BuildRoot: /tmp/base-shell-jailer

%prep
%setup -n base-shell-jailer

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
%defattr(-,root,root)
%attr(0644,root,root) /usr/lib/systemd/system/jailer.service
%attr(0755,root,root) /usr/sausalito/sbin/jailer.sh

%post
/usr/bin/systemctl daemon-reload >/dev/null 2>&1 ||: 

%postun
/usr/bin/systemctl daemon-reload >/dev/null 2>&1 ||: 

%description
This package contains a Systemd Unit file for the service 'jailer'.
Plus /usr/sausalito/sbin/jailer.sh, which it calles on startup.

%changelog

* Sat Nov 19 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.0-2
- Release version for BlueOnyx 5211R

* Sat Nov 02 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- First build
