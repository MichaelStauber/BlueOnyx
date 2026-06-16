# $Id: Makefile
#

SCRIPTS=sbin/goaccess_init.sh sbin/goaccess.sh sbin/get_web_stats.pl sbin/update_vsite_logrotate.pl

ifdef CCETOPDIR
include $(CCETOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

INSTALL = /usr/bin/install -m 0700 -o root 
PWD = $(shell pwd)

install: install_sbin

install_sbin:
	$(INSTALL) -o root -m 0755 $(SCRIPTS) $(PREFIX)/usr/sausalito/sbin/; \
	$(INSTALL) -d -o root -m 0755 $(PREFIX)/usr/sausalito/sbin; \
	$(INSTALL) -o root -m 0755 $(SCRIPTS) $(PREFIX)/usr/sausalito/sbin/; \
	mkdir -p $(PREFIX)/usr/lib/systemd/system/
	install -o root -m 0644 ./systemd/goaccess.service $(PREFIX)/usr/lib/systemd/system/goaccess.service
	mkdir -p $(PREFIX)/usr/sausalito/ui/web/base/sitestats
	install ./systemd/.htaccess $(PREFIX)/usr/sausalito/ui/web/base/sitestats/.htaccess


