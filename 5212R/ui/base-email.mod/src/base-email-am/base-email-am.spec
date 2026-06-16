Summary: Active Monitor support for base-email-am
Name: base-email-am
Version: 1.3.7
Release: 1%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-email-am.tar.gz
BuildRoot: /tmp/base-email-am

%prep
%setup -n base-email-am

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*

%description
This package contains binaries and scripts used by the Active Monitor 
subsystem for base-email-am.  

%changelog

* Sun May 31 2026 Michael Stauber <mstauber@solarspeed.net> 1.3.7-1
- Modified am_smtp.exp timeout as we're using Tcl’s after, which takes
  milliseconds, but we fed it the Expect-style timeout value as if it
  were seconds.

* Sat May 30 2026 Michael Stauber <mstauber@solarspeed.net> 1.3.6-1
- Modified am_smtp.exp to switch it from telnet for SMTP probes to 
  native socket commands instead. And we check 587 first (if available)
  as it's not behind 'postscreen_greet_wait', so it will answer faster.
  This speeds up checks and is way more reliable. 

* Fri May 29 2026 Michael Stauber <mstauber@solarspeed.net> 1.3.5-1
- Modified am_OpenDKIM.pl to return full locale strings and ENV
- Modified am_imap.exp to return full locale strings
- Modified am_pb4s.exp to return full locale strings
- Modified am_pop.exp to return full locale strings
- Modified am_sasl.exp to return full locale strings
- Modified am_smtp.exp to return full locale strings

* Wed May 27 2026 Michael Stauber <mstauber@solarspeed.net> 1.3.4-1
- Modified am_imap.exp am_pop.exp to drop the connection checks and
  rely on Systemd entirely.

* Wed May 27 2026 Michael Stauber <mstauber@solarspeed.net> 1.3.3-1
- Various fixes

* Tue Jan 28 2025 Michael Stauber <mstauber@solarspeed.net> 1.3.2-1
- Modified am_smtp.exp to work even if the MTA has been configured
  to force STARTTLS (smtpd_tls_security_level = encrypt). In which
  case we then use openssl_client for the test instead of telnet.

* Thu Jan 05 2023 Michael Stauber <mstauber@solarspeed.net> 1.3.1-5
- Added am_OpenDKIM.pl

* Wed Jun 03 2020 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BX04
- Modified am_smtp.exp to handle Postfix as well.

* Thu Dec 04 2014 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BX03
- Some Systemd love in AM-Scripts.

* Thu Dec 05 2013 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BX02
- Removed .svn directory from rpm package. 

* Wed Aug 31 2011 Michael Stauber <mstauber@solarspeed.net> 1.3.1-1BX01
- Modified am_smtp.exp with improvements suggested by Steven Howes.
  Gets rid of the 'did not issue MAIL/EXPN/VRFY/ETRN during connection to MTA'
  info line in maillog.

* Wed Dec 03 2008 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BQ4
- Rebuilt for BlueOnyx.

* Sun Jan 27 2008 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BQ3
- Fixed /usr/sausalito/swatch/bin/check-popb4smtp.sh *again*

* Wed Jan 23 2008 <mstauber@solarspeed.net> 1.3.1-0BQ2
- Fixed /usr/sausalito/swatch/bin/check-popb4smtp.sh 
- Was not working right with non English locales.

* Mon Oct 30 2007 <mstauber@solarspeed.net> 1.3.1-0BQ1
- Added provisions to monitor SMTP-Auth and POP before SMTP

* Sat Jun 10 2006 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.1.0-0BQ1
- change scripts for dovecot.

* Mon Oct 31 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.2-4BQ6
- add dist macro for release.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.2-4BQ5
- use vendor macro for Vendor tag.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpah.or.jp> 1.0.2-4BQ4
- remove Serial tag.

* Fri Aug 12 2005 Hisao SHIBUYA <shibuya@alpah.or.jp> 1.0.2-4BQ3
- add Serial tag.

* Thu Aug 11 2005 Hisao SHIBUYA <shibuya@alpah.or.jp> 1.0.2-4BQ2
- clean up spec file.

* Tue Jan 08 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.2-4BQ1
- build for Blue Quartz

* Thu Jun 15 2001 James Cheng <james.y.cheng@sun.com>
- initial spec file, add expect style tests

