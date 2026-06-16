Summary: BlueOnyx Mailbox Converter
Name: blueonyx-mailbox-converter
Version: 1.0.1
Release: 1%{dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: blueonyx-mailbox-converter.tar.gz
BuildRoot: /tmp/blueonyx-mailbox-converter

%prep
%setup -q -n %{name}

%build
make all

%install
mkdir -p $RPM_BUILD_ROOT/usr/sausalito/sbin/
install -m755 mb2md.pl $RPM_BUILD_ROOT/usr/sausalito/sbin/mb2md.pl
install -m755 mbox_maildir_convert.pl $RPM_BUILD_ROOT/usr/sausalito/sbin/mbox_maildir_convert.pl

%files
%attr(0755,root,root) /usr/sausalito/sbin/mb2md.pl
%attr(0755,root,root) /usr/sausalito/sbin/mbox_maildir_convert.pl

%description
This package contains BlueOnyx Mailbox Converter.
It allows to convert all mailboxes from Mbox to
Maildir format and from Maildir to Mbox format.

%changelog

* Sun Nov 20 2022 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Release version for BlueOnyx 5211R

* Tue Jun 09 2020 Michael Stauber <mstauber@solarspeed.net> 1.0.0-0BX01
- Initial build.


