Summary: Missing Nginx config files for 5210R
Name: blueonyx-nginx-conf
Version: 1.0.0
Release: 0BX02%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: blueonyx-nginx-conf.tar.gz
BuildRoot: /tmp/base-nginx-am
Requires: nginx

%prep
%setup -n blueonyx-nginx-conf

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/etc/nginx/*

%post

cp /etc/nginx/ssl_defaults.conf.bxdefault /etc/nginx/ssl_defaults.conf
cp /etc/nginx/ssl_proto_chiffres.conf.bxdefault /etc/nginx/ssl_proto_chiffres.conf
systemctl condreload nginx.service &>/dev/null || :

%description
This package contains the config files that the stock Nginx on EL8 is missing,
but which we need for our slightly non-stock Nginx configuration.

%changelog

* Mon Oct 26 2020 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX02
- Commented out 'X-Frame-Options SAMEORIGIN' in security.conf to
  allow the GUI to access internal pages not hosted on AdmServ.

* Wed Oct 09 2019 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- First build

