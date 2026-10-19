Summary: Small Helper CentOS 8 on for OpenVZ 7 to fix missing /etc/resolv.conf
Name: base-network-vz
Version: 1.0.0
Release: 2%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-network-vz.tar.gz
BuildRoot: /tmp/%{name}

%prep
%setup -n %{name}

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
%attr(0644,root,root) /usr/lib/systemd/system/bxvzresolve.service
%attr(0755,root,root) /usr/sausalito/sbin/bxvzresolve.sh

%description
This package contains a Systemd Unit-Script and a shell script. On server
startup it copies /etc/resolvconf/resolv.conf.d/base (if it exists) to
/etc/resolv.conf to get our resolver working. This is only needed on
CentOS 8 Containers on Aventurin{e} 6109R or OpenVZ 7.

%post

systemctl daemon-reload >/dev/null 2>&1 || :
systemctl enable bxvzresolve.service &>/dev/null || :
systemctl restart bxvzresolve.service &>/dev/null || :

%preun
systemctl stop bxvzresolve.servicee >/dev/null 2>&1 || :
systemctl disable bxvzresolve.servicee >/dev/null 2>&1 || :

%changelog

* Sun Oct 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.0-2
- bxvzresolve.sh: stop writing /etc/issue directly. It matched the
  factory "Welcome to your new server" text and replaced the banner
  with the addressless block, dropping the DHCP address and the
  factory credentials. All /etc/issue writes now go through
  /usr/sausalito/sbin/bx_update_issue.sh.

* Tue Oct 15 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- initial build


