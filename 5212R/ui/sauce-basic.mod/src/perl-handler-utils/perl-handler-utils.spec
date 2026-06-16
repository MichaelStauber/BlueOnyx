Summary: Perl modules that contain useful utility functions for handlers.
Name: perl-handler-utils
Version: 1.8.7
Release: 1%{?dist}
Vendor: %{vendor}
License: Sun modified BSD
Group: System Environment/BlueOnyx
Source: perl-handler-utils.tar.gz
BuildRoot: /tmp/perl-sauce
Provides: perl(Sauce::Util::SecurityLevels)
Requires: perl(Proc::Daemon)
Requires: perl(Proc::ProcessTable)
Requires: perl(File::Path)
Requires: perl(File::Basename)
Requires: perl(JSON)

%prep
%setup -n perl-handler-utils

%build
make all

%install
make PREFIX=$RPM_BUILD_ROOT install

%files
/usr/sausalito/perl/Sauce/Config.pm
/usr/sausalito/perl/Sauce/Util.pm
/usr/sausalito/perl/Sauce/Validators.pm
/usr/sausalito/perl/Sauce/Service.pm
%attr(0755,root,root) /usr/sausalito/perl/Sauce/Service/sauce_serviced.pl
/usr/sausalito/perl/Sauce/Service
/usr/sausalito/perl/Sauce/Util
/usr/sausalito/sbin/txnend
/usr/sausalito/sbin/txnrollback
%attr(0644,root,root) /usr/lib/systemd/system/sauce_serviced.service

%description
This package contains a number of perl modules that contain useful
utility functions for writing cced event handler scripts.

%post

test "$1" != "1" || systemctl daemon-reload >/dev/null 2>&1 || :
systemctl enable sauce_serviced.service >/dev/null 2>&1 || :
systemctl restart sauce_serviced.service >/dev/null 2>&1 || :

%preun
# Stop and disable the service before uninstallation
if [ $1 -eq 0 ]; then
    /bin/systemctl stop sauce_serviced.service
    /bin/systemctl disable sauce_serviced.service
fi

%postun
# Reload Systemd configuration after uninstallation
test "$1" -ge 1 || /bin/systemctl daemon-reload >/dev/null 2>&1 || :

%changelog

* Tue Dec 09 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.7-1
- Bugfix in Sauce/Service/Client.pm related to 'start' event.

* Fri Jul 04 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.6-1
- Modified debugging in Sauce/Service/Client.pm
- Modified debugging in Sauce/Service.pm

* Thu Jun 19 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.5-1
- Modified Sauce/Service/Daemon.pm to upgrade 'reload' to 'restart'
  if a 'reload' attempt hasn't gone through successfully.

* Tue May 06 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.4-1
- Modified Sauce/Service/Daemon.pm to handle stop actions better.

* Wed Apr 02 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.3-1
- Small improvement in Sauce/Service/Daemon.pm in process_queue()
  We now wait two seconds after triggering a service reload and then
  check with 'systemctl is-active' if the service is up again. This
  is more reliable than just parsing the exit state of systemctl,
  because that just checks if Systemd did what it was supposed to do.
  And not if it really worked. The 'is-active' check tells us the
  whole story.

* Sat Mar 08 2025 Michael Stauber <mstauber@solarspeed.net> 1.8.2-1
- Blindly restarting Apache and ALL PHP-FPM daemons is not really the
  greatest way to do things. We can make do with 'reload' IF we check
  if the services is running before we issue the 'reload'. If it is
  not running or in failed state, then we need to upgrade the request
  to a 'restart', but otherwise a 'reload' is fine.
- Modified Sauce/Service.pm to treat all 'httpd' PHP-FPM restarts as
  reloads - unless the service is dead. If it's dead: Upgrade to
  'restart' instead.
- Modified Sauce/Service/Client.pm accordingly.

* Thu Dec 12 2024 Michael Stauber <mstauber@solarspeed.net> 1.8.1-1
- Extended Sauce/Service/Daemon.pm with provisions for reporting a
  running YUM/DNF update.

* Sat Jun 08 2024 Michael Stauber <mstauber@solarspeed.net> 1.8.0-1
- Modified 'sauce_serviced.service' to write a JSON encoded file to
  /usr/sausalito/sessions/.sauce_serviced_daemon.state which the GUI
  can parse via /gui/services?q=services in order to find out if there
  are pending service changes and which ones those are.
- Modified Sauce/Service/Daemon.pm
- Updated requirements

* Sat Sep 23 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-6
- Modified Sauce/Service/Daemon.pm:
- Service queue files are now expunged after five minutes
- Added MTA check to process_queue() to expunge queue files of the
  MTA we are no longer using. This will get rid of sendmail queue
  files if postfix is active and vice versa.
- Added a check to process_queue() to expunge queue files for services
  that do not exists in first place.

* Mon Aug 14 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-5
- Several 5209R have weird issues with perl(Proc::Daemon). Devised
  a method to not use perl(Proc::Daemon) on 5209R.
- Modified Sauce/Service/sauce_serviced.pl
- Modified sauce_serviced.service

* Mon Aug 14 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-4
- Added missing requirement perl(Proc::Daemon) 
- Added missing requirement perl(Proc::ProcessTable)

* Sun Aug 13 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-3
- Removed File::Flock requirement from Sauce/Service/Daemon.pm

* Sun Aug 13 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-2
- Small fix in Sauce/Service/sauce_serviced.pl

* Sat Aug 12 2023 Michael Stauber <mstauber@solarspeed.net> 1.7.0-1
- Modified Sauce::Service so that 'sauce_serviced' is now a full
  Daemon that runs all the time and is managed my Systemd. It does 
  queue processing of systemctl calls in a delayed fashion to minimize
  the multiple restarts of the same services on massive CODB updates.
- Modified Sauce/Service/Client.pm to only handle the transaction
  queue. It does so in a flat file format in /usr/sausalito/services
- Modified Sauce/Service.pm to use the new feature set.
- Added Sauce/Service/sauce_serviced.pl as starter for the Daemon
- Added sauce_serviced.service as Systemd Unit-File
- Updated Makefile

* Sun Feb 19 2023 Michael Stauber <mstauber@solarspeed.net> 1.6.0-2
- Updated Sauce/Service.pm so that service_get_state() also knows
  state 'inactive' and recognizes it as service is stopped.

* Thu Jan 12 2023 Michael Stauber <mstauber@solarspeed.net> 1.6.0-1
- Overhauled and streamlined version for 5211R and 5210R. Renmoved
  InitV/Upstart support. Added queueing again for all services which
  don't use 'nobg'. Special provisions for ignoring xinetd and inetd
  on 5211R, added function service_get_state(), took more Systemd
  related oddities and helpful features into account. All around
  improvements in code sanity.

* Sun Nov 20 2022 Michael Stauber <mstauber@solarspeed.net> 1.5.1-1
- Release version for BlueOnyx 5211R

* Mon Nov 14 2022 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX06
- Fixes for Apache restart in Sauce::Service.

* Wed Nov 10 2021 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX05
- Initial build for BlueOnyx 5211R on EL9

* Tue Jun 09 2020 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX04
- Bumped timeout values in Sauce/Service/Daemon.pm to be nicer to Systemd

* Mon Jun 08 2020 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX03
- Better integration of Postfix and the AV-SPAM.
- Updated Sauce/Service/Daemon.pm
- Updated Sauce/Service.pm

* Mon Jun 01 2020 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX02
- Updated Sauce/Service.pm with provisions for Sendmail/Postfix.

* Sun Jul 07 2019 Michael Stauber <mstauber@solarspeed.net> 1.5.0-0BX01
- Introduced 5210R Vsite/User path changes.
- Modified Sauce/Config.pm
- Modified Sauce/Util.pm

* Fri Jul 05 2019 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX19
- Modified Sauce/Util.pm to add better debugging to 'makedirectory'

* Sun Jul 08 2018 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX18
- Modified Sauce/Service/Daemon.pm again to include improvements
  suggested by Tomohiro Hosaka <bokutin@gmail.com>. Additionally added
  check to not use pkill when the service is already dead, because a HUP
  will then not bring it back.

* Sun Mar 04 2018 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX17
- Extended src/perl-handler-utils/Sauce/Service/Daemon.pm to use
  'pkill -HUP httpd' to restart Apache if it is still running with no
  detached children. We still use '/sbin/service' or 'systemctl' in case
  of Apache being dead already or in case of detached children. The net
  result of using 'pkill -HUP httpd' is a more reliable and less intrusive
  restart of Apache than anything that the regular init scripts perform
  outside of a 'reload', which in itself is utterly unreliable as a 'reload'
  will always fail if the service has detached children or is dead to begin
  with.
- Additionally _check_apache_state() now not only checks for detached
  children, but (before that) also checks if the service is up via
  '/sbin/service' or 'systemd' and additionally also does an actual
  GET request to localhost of which we check the response code. 

* Fri Mar 02 2018 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX16
- Modified perl-handler-utils/Sauce/Util.pm to fix replaceblock function.
  If it never found the end tag it might have clean slated the rest of 
  the file it was editing.

* Tue Jun 14 2016 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX15
- More Systemd related improvements. Now that we split the unit files
  for cced.init in two, we need special provisions to run it depending
  on which init transaction we want to run when triggering cced.init

* Sat Jun 04 2016 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX14
- More Systemd madness in relation with restarting Apache. I really had
  to go to town on this one. Big overhaul of /Sauce/Service/Daemon.pm
  to deal with it and to properly restart daemonized services. Streamlined
  the code to turn repetitive code into re-usable functions. Intentionally
  left debugging enabled for now so that /var/log/messages shows what's
  going on when Sauce::Service::Daemon is being used.

* Sat May 28 2016 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX13
- Modified Sauce/Service.pm to fix function service_get_init(), which
  wasn't working correctly under InitV when the locale was not en_US.
  The new method is a bit over the top, but it works.

* Sat May 28 2016 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX12
- Modified Sauce/Service/Daemon.pm for better 'avspam' service handling.
- Modified Sauce/Service.pm to always upgrade a 'reload' of Apache to
  a 'restart'. Reloading does not go through if an Apache child is busy.
  Go figure. 

* Sat May 28 2016 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX11
- Modified Sauce/Service.pm to add a special provision to daemonize
  restarts of the 'avspam' as well. Additionally systemctl based calls
  now honor the 'nobg' parameter and execute then without the systemctl
  option --no-block. This is done to make systemctl actually wait for
  the call to finish. It doesn't, but at least it waits a bit longer.

* Fri Jun 26 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX10
- Turned out running Swatch isn't the best of ideas. So I switched that
  to just run am_apache.sh instead.

* Fri Jun 19 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX09
- Modified Sauce/Service/Daemon.pm yet again for better httpd reloads.
  We check if childs detached. Kill them if need be. Then we do a 
  non-blocking reload. Check again if Apache is up. If not, we directly
  upgrade from reload to restart and issue that (non-blocking) as well.
  Lastly we run the Active Monitor am_apache.sh component. If need be,
  we loop through this for friggin 30 seconds. And if THAT fails as well,
  then we run a full Swatch at the end. If THAT doesn't get Apache up
  again, then I don't know what else will.

* Thu Jun 18 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX08
- Bugfix in Sauce/Service.pm as it was disabling services that were 
  supposed to be on.
- Modified Sauce/Service/Daemon.pm to do a full swatch run if a transaction
  fails. That is our last resort to make sure we've done all we can to get
  everything running that is supposed to run.

* Tue Jun 09 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX07
- Removed commented out line in src/perl-handler-utils/Sauce/Service.pm
- Modified src/perl-handler-utils/Sauce/Service/Daemon.pm to replace 
  system() calls for 5207R/5208R with backticks as well.

* Sun Jun 07 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX06
- More debugging and some fixes in src/perl-handler-utils/Sauce/Service.pm

* Sat Jun 06 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX05
- Small fixes in Sauce/Service/Daemon.pm for the binary locations.

* Sat Jun 06 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX04
- Reloading 'httpd' is tricky for a multitude of reasons. One being that
  systemctl doesn't reload when the service is stopped. Or we have the
  case where Apache child processes have detached from the master.
  Likewise we need to avoid restarting/reloading Apache in quick 
  succession, which sadly is done by certain events that affect both
  server wide settings and Vsite wide settings. Each causes a reload or
  a restart. Sausalito has a Client/Daemon implementation in Sauce::Service
  for this purpose. If Apache is triggered to be restarted or reloaded,
  then this spawns a Client/Daemon that waits for a moment to see if there
  is only one request, or multiple. At the end of all requests it performs
  the desired action only once. This needed to be adapted to handle both
  InitV and Systemd, which I just did. It also needed to be adapted to
  really check if Apache is now running, or if a reload needed to be
  upgraded to a restart if Apache is dead. It also kills off detached
  child processes and does a restart (and health check) afterwards.
  If we can, we will reload when a reload was called. If that doesn't
  work, we will kill off Apache and restart it and then check if it is
  really up. 
- Modified Sauce/Service.pm to hand off Apache to Daemon/Client.
- Modified Sauce/Service/Client.pm for better logging.
- Extended Sauce/Service/Daemon.pm to do better logging, added health
  checks, checks if services run properly and loads of special
  provisions to deal with Apache to begin with. Also added support for
  both InitV and Systemd.

* Sun Jan 25 2015 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX03
- More Systemd related fixes. Use systemctl if present and if we're
  stopping or starting a service we use --no-block without exception.

* Thu Dec 04 2014 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX02
- More Systemd related fixes.

* Wed Dec 03 2014 Michael Stauber <mstauber@solarspeed.net> 1.4.0-0BX01
- Modified Sauce/Service.pm to deal with Systemd if present.

* Wed Aug 31 2011 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BX02
- Modified Sauce/Service.pm with a special case for 'crond' to prevent forking
  multiple 'crond' instances.

* Fri Jul 01 2011 Michael Stauber <mstauber@solarspeed.net> 1.3.1-0BX01
- Modified Sauce/Util.pm with a debugging switch that helps us to find 
  troublesome Sauce:editfile calls.

* Wed Dec 03 2008 Michael Stauber <mstauber@solarspeed.net> 1.3.0-11BQ13
- Rebuilt for BlueOnyx.

* Mon Dec 01 2008 Michael Stauber <mstauber@solarspeed.net> 1.3.0-11BQ12
- Modified hash_edit_function in Sauce/Util.pm to ignore php.ini style section headers.

* Sat May 05 2007 Hisao SHIBUYA <shibuya@bluequartz.org> 1.3.0-11BQ11
- change option -l for tar 1.15 again.

* Thu May 03 2007 Hisao SHIBUYA <shibuya@bluequartz.org> 1.3.0-11BQ10
- change option -l for tar 1.15.

* Fri Nov 25 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ9
- fixed the issue that permission of / partision.

* Mon Oct 31 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ8
- add dist macro for release.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ7
- use vendor macro for Vendor tag.

* Fri Oct 21 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ6
- remove Serial tag.

* Mon Aug 15 2005 Hisao SHIBUYA <shibuya@alpha.or.jP. 1.3.0-11BQ5
- celan up spec file.

* Thu Aug 11 2005 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ4
- modified Service.pm to use chkconfig $service off instead of chkconfig --del.

* Thu Dec 16 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ3
- modified Validators.pm to fix validate domainname.

* Tue Nov 23 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ2
- modified Service.pm to fix httpd defunct problem.

* Tue Jan 08 2004 Hisao SHIBUYA <shibuya@alpha.or.jp> 1.3.0-11BQ1
- build for Blue Quartz

* Thu Apr  4 2002 Patrick Baltz <patrick.baltz@sun.com> 1.3.0-11
- pr 14438.  Add Sauce::Service::Daemon and Client.  This queues init
  script runs that get registered with it to prevent race conditions.

* Thu Mar 21 2002 Patrick Baltz <patrick.baltz@sun.com> 1.3.0-10
- pr 14310.  The domain name validator function was incorrectly identifying
  a one part domain (no '.'s) as blank.

* Mon Nov 19 2001 Patrick Baltz <patrick.baltz@sun.com>
- bump release so changes to SecurityLevels.pm get into build

* Tue Jun 26 2001 Patrick Baltz <patrick.baltz@sun.com>
- add new Sauce/Util/ sub-directory to rpm

* Sun Apr 29 2000 Jonathan Mayer <jmayer@cobalt.com>
- initial spec file

