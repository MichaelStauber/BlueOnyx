Summary: Config files for ProFTPD on BlueOnyx
Name: base-ftp-configs
Version: 1.0.1
Release: 1%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-ftp-configs.tar.gz
BuildRoot: /tmp/base-ftp-configs

%prep
%setup -n %{name}

%build

%install

%{__rm} -rf %{buildroot}
mkdir %{buildroot}/
mv * %{buildroot}/

mkdir -p $RPM_BUILD_ROOT/usr/sausalito/configs/proftpd
ls -la $RPM_BUILD_ROOT
rm -f $RPM_BUILD_ROOT/base-ftp-configs.spec
rm -f $RPM_BUILD_ROOT/Makefile
mv $RPM_BUILD_ROOT/proftpd $RPM_BUILD_ROOT/usr/sausalito/configs/proftpd/
mv $RPM_BUILD_ROOT/proftpd.conf $RPM_BUILD_ROOT/usr/sausalito/configs/proftpd/
ls -la $RPM_BUILD_ROOT/
ls -la $RPM_BUILD_ROOT/usr/sausalito/configs/proftpd/

%files
/usr/sausalito/configs/proftpd/*

%description
This package contains the config files for the modified ProFTPD on BlueOnyx

%changelog

* Mon Sep 29 2025 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Updated proftpd.conf with missing PassivePorts and TLS settings

* Fri Dec 30 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial build
