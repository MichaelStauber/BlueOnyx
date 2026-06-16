Name:           blueonyx-db
Version:        1.85
Release:        2%{?dist}
Summary:        BlueOnyx Berkeley DB 1.85 runtime and loader

License:        BSD-3-Clause
URL:            https://www.blueonyx.it/
Source0:        blueonyx-db-1.85.tar.gz
Source1:        db_load185.c
Source2:        examine_codb.pl
Source3:        repair_codb_classes.pl

BuildRequires:  gcc
BuildRequires:  make
Requires:       perl
Requires:       tar
Requires:       gzip
Requires:       systemd
Requires:	libdb-utils

%global debug_package %{nil}
%global _build_id_links none

%description
Berkeley DB 1.85 as used by BlueOnyx CODB, packaged with a small
loader utility and installed under /home/solarspeed/db/ so CODB repair
and maintenance workflows can use a matching 1.85-compatible toolchain.

%prep
%setup -q -n blueonyx-db-1.85

%build
make -C db.1.85/PORT/linux
gcc -std=gnu99 -Wall -Wextra -O2 \
    -I db.1.85/PORT/linux/include \
    -I db.1.85/PORT/linux \
    -o db_load185 %{SOURCE1} db.1.85/PORT/linux/libdb.a

%install
rm -rf %{buildroot}

install -d -m 0755 %{buildroot}/home/solarspeed/db/bin
install -d -m 0755 %{buildroot}/home/solarspeed/db/include
install -d -m 0755 %{buildroot}/home/solarspeed/db/lib
install -d -m 0755 %{buildroot}/home/solarspeed/db/share/doc/%{name}-%{version}
install -d -m 0755 %{buildroot}/usr/sausalito/sbin

install -m 0755 db_load185 %{buildroot}/home/solarspeed/db/bin/db_load185
install -m 0644 db.1.85/PORT/linux/libdb.a %{buildroot}/home/solarspeed/db/lib/libdb.a
install -m 0644 db.1.85/PORT/linux/include/*.h %{buildroot}/home/solarspeed/db/include/
install -m 0644 db.1.85/README %{buildroot}/home/solarspeed/db/share/doc/%{name}-%{version}/README
install -m 0755 %{SOURCE2} %{buildroot}/usr/sausalito/sbin/examine_codb.pl
install -m 0755 %{SOURCE3} %{buildroot}/usr/sausalito/sbin/repair_codb_classes.pl

%files
%defattr(-,root,root,-)
/home/solarspeed/db/bin/db_load185
/home/solarspeed/db/include/*
/home/solarspeed/db/lib/libdb.a
/usr/sausalito/sbin/examine_codb.pl
/usr/sausalito/sbin/repair_codb_classes.pl
%doc /home/solarspeed/db/share/doc/%{name}-%{version}/README

%changelog

* Mon May 18 2026 BlueOnyx Packaging Automation <noreply@blueonyx.it> 1.85-2
- Added ability to detect and repair OID mismatches in codb.oids as well

* Mon May 18 2026 BlueOnyx Packaging Automation <noreply@blueonyx.it> 1.85-1
- Initial BlueOnyx Berkeley DB 1.85 package for CODB compatibility.
