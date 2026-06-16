Summary: Active Monitor support for base-nginx-am
Name: base-nginx-am
Version: 1.0.0
Release: 0BX03%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-nginx-am.tar.gz
BuildRoot: /tmp/base-nginx-am

%prep
%setup -n base-nginx-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-nginx-am.  

%changelog

* Fri Jul 19 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX03
- Small EL8 related fixes.

* Sun Apr 15 2018 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX02
- Version number bump for Release Candidate

* Fri Apr 13 2018 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- First build

