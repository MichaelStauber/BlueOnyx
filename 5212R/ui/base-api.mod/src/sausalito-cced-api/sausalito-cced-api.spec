Name:           sausalito-cced-api
Version:        1.1.0
Release:        2%{?dist}
Summary:        CCEd-API proxy for BlueOnyx

License:        SUN-modified-BSD
URL:            https://www.blueonyx.it/
Source:         %{name}.tar.gz

BuildRoot:      %{_tmppath}/%{name}-%{version}-root

BuildRequires:  golang
Requires:       systemd
Requires:	sausalito-cce-server
Requires:	base-admserv-capstone
Requires(pre):  shadow-utils

%description
A Go-based JSON API proxy for CCEd (Cobalt Control Engine) for BlueOnyx, allowing 
secure and modern access to CCEd via HTTP/HTTPS using a configuration-driven approach.

%prep
%setup -n %{name}

%build
go build -o cced-api cced-api.go

%install
rm -rf %{buildroot}

# Binaries and scripts
install -D -m 0755 cced-api %{buildroot}/usr/sausalito/sbin/cced-api
install -D -m 0755 cced-api-pre.sh %{buildroot}/usr/sausalito/bin/cced-api-pre.sh
install -D -m 0755 gen_api_admin.pl %{buildroot}/usr/sausalito/sbin/gen_api_admin.pl

# Config and cert dirs
install -d -m 0755 %{buildroot}/etc/cced-api/config
install -d -m 0755 %{buildroot}/etc/cced-api/certs

# Config file
install -D -m 0644 cced-api.conf %{buildroot}/etc/cced-api/config/cced-api.conf

# Systemd unit
install -D -m 0644 cced-api.service %{buildroot}/usr/lib/systemd/system/cced-api.service

# Logrotate
install -D -m 0644 cced-api.logrotate %{buildroot}/etc/logrotate.d/cced-api


%files
%license SUN-modified-BSD-License.txt
%doc README.txt
/usr/sausalito/sbin/cced-api
/usr/sausalito/sbin/gen_api_admin.pl
/usr/sausalito/bin/cced-api-pre.sh
%config(noreplace) /etc/cced-api/config/cced-api.conf
%dir /etc/cced-api/config
%dir /etc/cced-api/certs
/usr/lib/systemd/system/cced-api.service
/etc/logrotate.d/cced-api
%attr(0600,admserv,admserv) %ghost /etc/cced-api/api-admin.passwd
%attr(0600,admserv,admserv) %ghost /etc/cced-api/master.key

%post
%systemd_post cced-api.service
systemctl enable cced-api.service >/dev/null 2>&1 || :
systemctl restart cced-api.service >/dev/null 2>&1 || :

%preun
%systemd_preun cced-api.service

%postun
%systemd_postun_with_restart cced-api.service

%changelog
* Sun Oct 12 2025 Michael Stauber <mstauber@solarspeed.net> - 1.1.0-2
- Modified cced-api-pre.sh to enforce proper permissions on certs.

* Wed Jun 11 2025 Michael Stauber <mstauber@solarspeed.net> - 1.1.0-1
- Added API endpoint /v2/metrics to summarily replace Prometheus and
  node_exporter by providing the same server utiliztations statistics
  on demand, without retaining and with a hell of a lot less overhead.
  Like /v2/services the new endpoint is secured against remote access
  and requires IP whitelisting and valid TOKEN, but can be polled
  without authentication from localhost.

* Sun Jun 01 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.6-1
- Modified output to stdout to omit the date and time, because otherwise
  /var/log/messages ends up with a double date/time-stamp for entries.

* Sun Jun 01 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.5-1
- More logging related fixes. AUTH and commands like SET were still
  logging the key 'password' in plaintext. Chose a more radical
  approach to fix it this time.

* Fri May 30 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.4-1
- Logging related fixes. Logging is now better sanitized and more 
  streamlined. SessionId is now only partially logged, too.

* Fri May 30 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.3-1
- Modified cced-api.go to swap out the deprecated rand function and to
  fix the logging. We DO want to log the exact commands that are sent
  to CCEd. With all bells and whistles and the kitchen sink. In the
  format that they WERE sent to CCEd. But: We don't want to see
  the cleartext of AUTH, because it has the password. We hide the many 
  AUTHKEYS, because there are too many of them. We only log then now
  if they fail. And BYE is also something we do not need or want to
  log to keep the signal to noise ratio down.

* Sun May 25 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.2-1
- Modified cced-api.go function handleGetAll() to allow empty args if
  class is set.

* Sun May 25 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.1-1
- Modified cced-api.go function handleCCE() to handle data/vars better.

* Fri May 23 2025 Michael Stauber <mstauber@solarspeed.net> - 1.0.0-1
- Initial RPM release of sausalito-cced-api 

