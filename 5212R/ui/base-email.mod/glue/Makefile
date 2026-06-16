ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

# include defines.mk here before checking to see which target make is doing
include $(CCETOPDIR)/devel/defines.mk

# check PREFIX, because if it is defined we are 'make rpm' and CCETOPDIR
# needs to be redefined appropriately
ifdef PREFIX
CCETOPDIR = $(PREFIX)/usr/sausalito
endif

# files to install
SCRIPTS = usr/local/sbin/vacation.pl

all:

install:
	mkdir -p $(PREFIX)/usr/local/sbin/
	mkdir -p $(PREFIX)/etc/smrsh/
	mkdir -p $(PREFIX)/etc/cron.d
	mkdir -p $(PREFIX)/etc/cron.daily
	mkdir -p $(PREFIX)/etc/dovecot/conf.d
	mkdir -p $(PREFIX)/etc/httpd/conf.d
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) \
	usr/local/sbin/vacation.pl $(PREFIX)/usr/local/sbin/
	cd $(PREFIX)/etc/smrsh; \
	ln -sfn /usr/local/sbin/vacation.pl vacation.pl
	mkdir -p $(PREFIX)/usr/sausalito/bin
	mkdir -p $(PREFIX)/usr/sausalito/sbin
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/remove_domain.pl $(PREFIX)/usr/sausalito/bin/remove_domain.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/findalias.pl $(PREFIX)/usr/sausalito/bin/findalias.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/whichmta $(PREFIX)/usr/sausalito/bin/whichmta
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/blueonyx-postfix-confgen $(PREFIX)/usr/sausalito/bin/blueonyx-postfix-confgen
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/blueonyx-postfix-generate-sender-login-maps.pl $(PREFIX)/usr/sausalito/bin/blueonyx-postfix-generate-sender-login-maps.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/blueonyx-postfix-canonical.pl $(PREFIX)/usr/sausalito/bin/blueonyx-postfix-canonical.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/custom-postfix-confgen.sh $(PREFIX)/usr/sausalito/bin/custom-postfix-confgen.sh
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/get_opendkim.pl $(PREFIX)/usr/sausalito/bin/get_opendkim.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/blueonyx-auth-maponly $(PREFIX)/usr/sausalito/bin/blueonyx-auth-maponly
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/email-auth-helper.pl $(PREFIX)/usr/sausalito/sbin/email-auth-helper.pl
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) usr/local/sbin/break-smrsh-loop.pl $(PREFIX)/usr/local/sbin/
	$(INSTALL) etc/cron.d/break-smrsh-loop.cron $(PREFIX)/etc/cron.d
	$(INSTALL) etc/cron.daily/mboxfix.pl $(PREFIX)/etc/cron.daily
	$(INSTALL) etc/dovecot/conf.d/10-ssl.conf.bx $(PREFIX)/etc/dovecot/conf.d/10-ssl.conf.bx
	$(INSTALL) etc/dovecot/conf.d/blueonyx-auth.conf.ext $(PREFIX)/etc/dovecot/conf.d/blueonyx-auth.conf.ext
	$(INSTALL) etc/httpd/conf.d/radicale_redirects.conf $(PREFIX)/etc/httpd/conf.d/radicale_redirects.conf
rpm:
