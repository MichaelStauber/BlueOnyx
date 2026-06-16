Summary: BlueOnyx NetworkManager Extension
Name: blueonyx-nm-resolver
Version: 1.0.3
Release: 1%{dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: blueonyx-nm-resolver.tar.gz
BuildRoot: /tmp/blueonyx-nm-resolver

%prep
%setup -q -n %{name}

%build
make all

%install
mkdir -p $RPM_BUILD_ROOT/etc/NetworkManager/dispatcher.d
install -m 755 50-blueonyx $RPM_BUILD_ROOT/etc/NetworkManager/dispatcher.d/50-blueonyx
mkdir -p $RPM_BUILD_ROOT/etc/NetworkManager/conf.d
install -m 644 no-dns.conf $RPM_BUILD_ROOT/etc/NetworkManager/conf.d/no-dns.conf
mkdir -p $RPM_BUILD_ROOT/usr/sausalito/sbin
install -m 755 refresh_resolver.pl $RPM_BUILD_ROOT/usr/sausalito/sbin/refresh_resolver.pl
mkdir -p $RPM_BUILD_ROOT/usr/sausalito/bin
install -m 755 fix_hostname.pl $RPM_BUILD_ROOT/usr/sausalito/bin/fix_hostname.pl

%files
/etc/NetworkManager/dispatcher.d/50-blueonyx
/etc/NetworkManager/conf.d/no-dns.conf
/usr/sausalito/sbin/refresh_resolver.pl
/usr/sausalito/bin/fix_hostname.pl

%description
This package contains BlueOnyx NetworkManager
Extenion. It disables NetworkManager's DNS
handling and ties it into CCEd instead. It also
makes sure the server name stays what it should
be.

%changelog

* Sun Mar 02 2025 Michael Stauber <mstauber@solarspeed.net> 1.0.3-1
- Updated version for NetworkManager rewrite of BlueOnyx

* Sat Jun 08 2024 Michael Stauber <mstauber@solarspeed.net> 1.0.2-1
- Updated version for NetworkManager rewrite of BlueOnyx

* Sun Nov 20 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Release version for BlueOnyx 5211R

* Tue Nov 12 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- Initial build.


