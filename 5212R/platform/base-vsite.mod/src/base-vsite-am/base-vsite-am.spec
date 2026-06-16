Summary: Active Monitor support for base-vsite-am
Name: base-vsite-am
Version: 1.0.1
Release: 1%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-vsite-am.tar.gz
BuildRoot: /tmp/base-vsite-am

%prep
%setup -n base-vsite-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*
/usr/sausalito/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-vsite-am.  

%changelog

* Sat Nov 20 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Release version for BlueOnyx 5211R

* Thu Feb 25 2021 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX05
- Cleaned up debugging env.

* Sun Aug 18 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX04
- Added provisions to skip Alias check for Vsite if a Subdomain uses the
  alias in question.

* Tue Jun 18 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX03
- Posix isalpha fix

* Tue Sep 05 2017 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX02
- Small fix. We now use uniq() to weed out doublettes.

* Sat Aug 26 2017 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- Initial build
