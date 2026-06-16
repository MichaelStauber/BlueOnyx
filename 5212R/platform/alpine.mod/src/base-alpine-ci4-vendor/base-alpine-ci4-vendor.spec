#
# Spec file for base-alpine-ci4-vendor
#

%define pkgname base-alpine-ci4-vendor
%define instdir .base-alpine-ci4-vendor

Name:           %{pkgname}
Version:        4.5.5
Release:        1
Packager:       Michael Stauber <mstauber@blueonyx.it>
Vendor:         BLUEONYX.IT
URL:            http://www.blueonyx.it
License:        Sun Modified BSD
Group:          System
BuildRoot:      %{_tmppath}/%{name}-%{version}-root
BuildArch:      noarch
Distribution:   BlueOnyx 5212R
Source:         %{name}.tar.gz
Summary:        CodeIgniter 4 Vendor directory
AutoReq         : no
AutoProv        : no

%description
CodeIgniter 4 Vendor directory. Has to be packed separately, as it
contains 'src' directories that the sausalito-devel-tools will 
refuse to include in RPMs.

%prep
%setup -n %{name}

%build
echo "Working on $RPM_BUILD_ROOT"

%install

%{__rm} -rf %{buildroot}
mkdir %{buildroot}/
mv * %{buildroot}/

mkdir -p $RPM_BUILD_ROOT/usr/sausalito/ui/chorizo/ci4/
mv $RPM_BUILD_ROOT/vendor $RPM_BUILD_ROOT/usr/sausalito/ui/chorizo/ci4/
rm -f $RPM_BUILD_ROOT/base-alpine-ci4-vendor.spec

%pre

%post

%preun

%postun

%clean
rm -R -f $RPM_BUILD_ROOT

%files
%defattr(-,root,root)
%attr(-,root,root) /usr/sausalito/ui/chorizo/ci4/vendor

%changelog

* Sat Nov 30 2024 Michael Stauber <mstauber@blueonyx.it>
- [4.5.5-1] Version number bump to match installed CI
- Upgraded for CodeIgniter 4.5.5

* Thu Aug 22 2024 Michael Stauber <mstauber@blueonyx.it>
- [4.0-9] Version number bump
- Adding symfony/yaml and cbowden/Ratchet had dropped the
  auto-loading of sonata-project/google-authenticator and
  it needed to be added in again for 2FA
- Modified composer/autoload_psr4.php
- Modified composer/autoload_static.php
- Modified composer/installed.json
- Modified composer/installed.php

* Mon Jul 22 2024 Michael Stauber <mstauber@blueonyx.it>
- [4.0-8] Version number bump
- Added back cbowden/Ratchet and React related modules as
  we need them again for Websockets in the IncusAPI.
  This also added a ton of dependency modules.

* Sat Jul 06 2024 Michael Stauber <mstauber@blueonyx.it>
- [4.0-7] Version number bump
- Added vendor/symfony/yaml for Incus support

* Thu Feb 08 2024 Michael Stauber <mstauber@blueonyx.it>
- [4.0-6] Version number bump
- Added new modules for Elmer integration

* Mon Nov 21 2022 Michael Stauber <mstauber@blueonyx.it>
- [4.0-5] Version number bump

* Mon Nov 21 2022 Michael Stauber <mstauber@blueonyx.it>
- [4.0-4] Version number bump

* Mon Nov 21 2022 Michael Stauber <mstauber@blueonyx.it>
- [4.0-3] Initial build
