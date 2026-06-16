Summary: Active Monitor support for base-organizer-am
Name: base-organizer-am
Version: 1.0.0
Release: 1%{dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-organizer-am.tar.gz
BuildRoot: /tmp/base-organizer-am

%prep
%setup -n base-organizer-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-organizer-am.

%changelog

* Tue Jun 27 2023 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial build.


