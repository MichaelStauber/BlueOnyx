Summary: BlueOnyx YUM/DNF Wrapper
Name: blueonyx-yum-wrapper
Version: 1.0.2
Release: 1%{dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: blueonyx-yum-wrapper.tar.gz
BuildRoot: /tmp/blueonyx-yum-wrapper

%prep
%setup -q -n %{name}

%build
make all

%install
mkdir -p $RPM_BUILD_ROOT/usr/bin/
install -m755 yum_wrapper $RPM_BUILD_ROOT/usr/bin/yum_wrapper
install -m755 dnf_wrapper $RPM_BUILD_ROOT/usr/bin/dnf_wrapper

%files
/usr/bin/yum_wrapper
/usr/bin/dnf_wrapper

%description
This package contains BlueOnyx yum_wrapper.
It replaces the /usr/bin/yum Symlink to make
sure that CCEd restart/rehash are handled
after YUM/DNF updates.

%changelog

* Sun Apr 23 2023 Michael Stauber <mstauber@solarspeed.net> 1.0.2-1
- Added /usr/bin/dnf_wrapper as well

* Sun Nov 20 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Release version for BlueOnyx 5211R

* Mon Oct 14 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- Initial build.


