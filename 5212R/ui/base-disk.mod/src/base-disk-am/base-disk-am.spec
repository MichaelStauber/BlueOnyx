Summary: Binaries and scripts used by Active Monitor for base-disk
Name: base-disk-am
Version: 1.4.3
Release: 1%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: base-disk-am.tar.gz
BuildRoot: /tmp/%{name}
Requires: perl-Unix-ConfigFile >= 0.06-SOL1
Requires: perl-MIME-Lite >= 3.023-2
Requires: perl-Email-Date-Format >= 1.002-1
Requires: quota >= 4.0.4

%prep
%setup -n %{name}

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/swatch/bin/*
/usr/sausalito/sbin/*

%description
This package contains a number of binaries and scripts used by the Active
Monitor subsystem to monitor services provided by the base-disk module.  

%changelog

* Mon Jun 29 2026 Michael Stauber <mstauber@solarspeed.net> 1.4.3-1
- Fixed XFS quota detection to use /usr/sbin/xfs_quota with an absolute path.
- Prevents fallback to du-based accounting when PATH is restricted.
- Ensures serverDiskUsage and related quota views report correct XFS usage on /home.
- Applied to get_quotas.pl and am_disk.pl.

* Thu Nov 13 2025 Michael Stauber <mstauber@solarspeed.net> 1.4.2-1
- Modified get_quotas.pl to use Disk.pm's get_dir_size().

* Mon Aug 25 2025 Michael Stauber <mstauber@solarspeed.net> 1.4.1-2
- Added exclusion of serverAdmins for quota related suspensions
- Added cleanup of email map files for stale suspensions

* Mon Aug 25 2025 Michael Stauber <mstauber@solarspeed.net> 1.4.1-1
- Modified am_disk.pl for rework of over-quota suspend/unsuspend mechanism. 
- Warn-at-95%, block-at-100% model:
  - Default thresholds: red_pcnt=95, yellow_pcnt=90, red_free=100, yellow_free=125.
  - Users are notified when usage >= 95% (once per day); "over_quota" is set ONLY at 100%.
  - Added quota_toggle timestamp whenever over_quota flips.
- Suppress user mail when delivery would be blocked:
  - Skip staging and sending user email if the user is already flagged over_quota
    or current usage >= 100%. Also re-check in the send loop to avoid races.
- Admin notifications:
  - Daily 04:00 summary includes users over quota (as user@vsite_fqdn) and vsites over quota.
  - DEBUG mode forces admin mail regardless of time window.
  - i18n locale is respected for admin and per-user emails; UTF-8 / EUC-JP charset set accordingly.
- Vsite flags and server state:
  - Set Vsite.Disk.vsite_over_quota=1 when site usage >= 100%; clear it when recovered.
  - If any vsite is over quota, bump Active Monitor server status to YELLOW.
  - Maintain Vsite.Disk.user_over_quota based on current users over quota.
- Quota detection & accounting:
  - Prefer real quotas via `repquota -O csv` for users and groups.
  - Fallback path (no FS quotas): compute usage with find/stat; pull allowances from CODB.
  - Include /web directory (owned by site admin) in that admin user’s usage on fallback path.
  - Treat vsites whose /web owner is over quota as over-quota sites for admin reporting.
- Mail-block cleanup aligned with 100% policy:
  - Scan /etc/mail/access and /etc/postfix/suspended_users; clear CODB flags for users
    no longer at/over 100% (or without site quotas), triggering unblocking.
- Disk usage warnings & CCE protection:
  - df-based device warnings respect yellow/red % and free-MB thresholds.
  - If root filesystem free falls below root_thresh, lock/suspend CCE; otherwise sync lock.
- Robustness & polish:
  - Safer truthiness checks (e.g., DEBUG), use of `// 0` where appropriate.
  - Reset and then set flags in batch to reflect current state.
  - Minor log/message cleanups and consistent comparisons (use >= where appropriate).

* Fri Jun 06 2025 Michael Stauber <mstauber@solarspeed.net> 1.4.0-3
- Modified am_disk.pl to rework the over-quota suspend/unsuspend 
  Email for Users by introducing a new CODB key as <useroid> . 
  'Disk' 'quota_toggle'. This allows us to run the quota-treatment 
  on Users even if their over-quota status hasn't changed. We also
  now parse the Sendmail and Postfix access files to find users with
  email set to over-quota rejects. These then get re-checked for their
  quota status and if need be we use the 'quota_toggle' to trigger 
  a cleanup run for their settings.

* Thu Oct 17 2024 Michael Stauber <mstauber@solarspeed.net> 1.4.0-2
- Modified am_disk.pl by extending the emailing routine with an eval.
  Emailing to over-quota users fails and then the 'lastemailed' flag
  never gets set. So we encapsulate the sending of emails in an eval.

* Thu Oct 17 2024 Michael Stauber <mstauber@solarspeed.net> 1.4.0-1
- Usage of Unix::PasswdFile and Unix::GroupFile turns out to be         
  problematic because even for read-only transactions it locks the
  /etc/passwd and /etc/group files. Sooner or later this will lead
  to runtime issues with locks being left around.
- Added our own Base::CustomPasswdFile and Base::CustomGroupFile
  which serve as drop in replacements for our common read-only
  access types. These are part of base-disk now.
- Modified src/base-disk-am/am_disk.pl accordingly
- Modified src/base-disk-am/get_quotas.pl accordingly 

* Sat Jul 13 2024 Michael Stauber <mstauber@solarspeed.net> 1.3.1-4
- Locale related fixes in src/base-disk-am/am_disk.pl
- Locale related fixes in src/base-disk-am/get_quotas.pl

* Thu Jul 04 2024 Michael Stauber <mstauber@solarspeed.net> 1.3.1-3
- Set $ENV{red_pcnt} to 99 in am_disk.pl to not turn off services too early.

* Sat May 18 2024 Michael Stauber <mstauber@solarspeed.net> 1.3.1-2
- Small fixes in am_disk.pl

* Wed May 15 2024 Michael Stauber <mstauber@solarspeed.net> 1.3.1-1
- Modified am_disk.pl to work with enabled user and group quotas
  on the file-system level and without.
- Modified am_disk.pl to work with enabled user and group quotas
  on the file-system level and without.
- Extended am_disk.pl to set and remove the flag 'vsite_over_quota' in
  Vsite . Disk as needed.

* Thu Sep 26 2018 Michael Stauber <mstauber@solarspeed.net> 1.3.0-0BX02
- Bugfixes and code cleanup

* Thu Sep 26 2018 Michael Stauber <mstauber@solarspeed.net> 1.3.0-0BX01
- Modified get_quotas.pl to deprecate perl-Quota.

* Fri Sep 15 2017 Michael Stauber <mstauber@solarspeed.net> 1.2.0-0BX03
- Modified src/base-disk-am/get_quotas.pl to only look under /home/.sites/ for
  the Vsites, as this is the only place where we really support them to be at
  this time. This is faster and more reliable.

* Mon Oct 13 2014 Michael Stauber <mstauber@solarspeed.net> 1.2.0-0BX02
- Modified src/base-disk-am/get_quotas.pl to add debugging. Also added a check that if
  there is only one disk (think OpenVZ, Cloud or similar) which is mounted, is 'internal'
  and has the flag 'isHomePartition', then we use it, but check for the sites on /home
  instead. This eleminates the last vestiges of BlueOnyx really needing a /home
  partition. This is the one work around that eleminates the need for all the other
  duct-tape measures in this regards. Still works if the other usual band-aids are
  already applied on a box.

* Thu Dec 05 2013 Michael Stauber <mstauber@solarspeed.net> 1.2.0-0BX01
- Modified am_disk.pl to allow mailing in 'ja_JP' again. Now that our Japanese locales are
  in UTF-8 it might finally work.

* Thu Apr 05 2012 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX25
- Modified am_disk.pl to set correct locale for Emails that it generates. Required changes
  after massive UTF-8 update, as it was still sending in 'ISO-8859-1' format.

* Tue Oct 04 2011 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX24
- Updated am_disk.pl again to make output generation of user-over-quota-mails more readable.

* Fri Sep 28 2011 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX23
- Updated am_disk.pl again to hard code Japanese emails to 'en' or 'en_US' but with
  some more respect to the platform specific locales.
- Added am_disk.pl-japanese-test outside the source tree. Contains a not yet working
  test-version which uses perl-MIME-Lite-TT-Japanese instead of MIME:Lite.

* Fri Sep 28 2011 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX22
- Updated am_disk.pl to use MIME::Lite for mailings to admin and users.
- Updated am_disk.pl to prepend hostname if it emails admin on over-quota users. 
  It also adds the FQDN of the user to the mail, so that it is easier to see which 
  site the user belongs to. Because without that the info is often useless.
- Updated requirements to add perl-MIME-Lite and perl-Email-Date-Format

* Mon Nov 15 2010 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX21
- Updated am_disk.pl to check if /home/.sites/ exists before it tries to open it blindly.
- Otherwise Swatch throws errors on fresh installed systems until the 1st site gets added.

* Mon Nov 15 2010 Taco Scargo <taco@scargo.nl> 1.1.0-15BX20
- Fixed am_disk.pl as users did not receive quota warning e-mails

* Sun Jun 06 2010 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BX19
- On CentOS6 user 'nfsnobody' has UID > 500, so we need to ignore him as well.

* Wed Dec 03 2008 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BQ18
- Rebuilt for BlueOnyx.

* Mon Dec 01 2008 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BQ17
- Another small fix in get_quota.pl: SITExx-logs users are no longer reported.

* Mon Dec 01 2008 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BQ16
- Small fix in get_quota.pl. Replaced 'lt' with '<'. One day I'll learn to avoid this kind of mistake.

* Thu Nov 27 2008 Michael Stauber <mstauber@solarspeed.net> 1.1.0-15BQ15
- Since all users are no longer in the 'users' group, quota info couldn't be obtained for sites AND users.
- Updated get_quota.pl to now use UnixConfigFile Perl Module to determine group on demand.
- Streamlined user and group parsing routines in get_quota using Unix::PasswdFile.
- Added requirement for perl-Unix-ConfigFile >= 0.06-SOL1 to specfile.
- Major version bump to 1.1.0 to make clear that this is a radical modify, although 100% compatible to the outside.

* Tue Mar 04 2008 Michael Stauber <mstauber@solarspeed.net> 1.0.1-15BQ14
- Fixed am_disk.pl again. Set safe defaul for $dev if its undefined.

* Sat Mar 01 2008 Michael Stauber <mstauber@solarspeed.net> 1.0.1-15BQ13
- Updated am_disk.pl to address cases where $dev is undefined.

* Sun Feb 03 2008 Hisao SHIBUYA <shibuya@bluequartz.org> 1.0.1-15BQ12
- add sign to the package.

* Thu Apr 13 2006 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ11
- modify am_disk.pl to fix the issue when gid is NULL by Brian.

* Thu Mar 30 2006 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ10
- The am_disk.pl supports LVM partition.

* Mon Oct 31 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ9
- add dist macro for release.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ8
- use vendor macro for Vendor tag.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ7
- remove Serial tag.

* Fri Aug 12 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ6
- add serial number.

* Thu Aug 11 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ5
- clean up spec file.

* Tue May 17 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ4
- modified am_disk.pl. 

* Tue Apr 26 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ3
- The am_disk.pl supports LVM partition.

* Sat Dec 25 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ2
- modified get_quotas.pl to exclude 'games' user.

* Wed Mar 10 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.0.1-15BQ1
- build for Blue Quartz
- fix disk active monitor alert

* Tue Jun 20 2000 Tim Hockin <thockin@cobalt.com>
- initial spec file

