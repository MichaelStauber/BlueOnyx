Summary: Active Monitor support for base-shell-am
Name: base-shell-am
Version: 1.0.0
Release: 3%{?dist}
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

* Sat Nov 19 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.0-3
- Release version for BlueOnyx 5211R

* Wed Jul 10 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- First build

