Summary: Active Monitor support for base-api-am
Name: base-api-am
Version: 1.0.0
Release: 1%{dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-api-am.tar.gz
BuildRoot: /tmp/base-api-am

%prep
%setup -n base-api-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-api-am.

%changelog

* Fri May 23 2025 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial build.


