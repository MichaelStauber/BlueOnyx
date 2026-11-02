# BlueOnyx phpMyAdmin packaging
#
# Two layers:
# 1) blueonyx-phpMyAdmin RPM - upstream phpMyAdmin + BlueOnyx SSO overlays
#    Build:  cd src/blueonyx-phpMyAdmin && make rpm
# 2) base-phpmyadmin module RPM - Chorizo GUI (signon/pusher menus locales)
#    Build:  make (module.mk) after blueonyx-phpMyAdmin is installed/available
#
# Upstream tarball cache: /tmp/phpmyadmin-src/ (not in SVN; survives make clean)
#
# SSO mechanism (must keep on upgrades):
# - GUI Controllers Signon.php / Pusher.php set cookies PMA_USER, PMA_PASSWORD
# - config.inc.php: auth_type=signon, SignonScript=signon-script-blueonyx.php
# - signon-script-blueonyx.php reads those cookies for get_login_credentials()
# - Apache conf: /base/phpMyAdmin under AdmServ DocumentRoot
#
# PHP note: upstream 5.2.x lists php <8.4; AdmServ is 8.4.x. Smoke-test after
# install. If broken, pin PMA_VERSION=... or wait for upstream 5.2/6.x with 8.4.
#
# Force version:  make rpm  is automatic via resolve-pma-version.sh
#                PMA_MAJOR_LINE=5.2 make rpm
