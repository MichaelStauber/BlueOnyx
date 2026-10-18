# $Id: Makefile
#

SCRIPTS=sbin/goaccess_init.sh sbin/goaccess.sh sbin/get_web_stats.pl sbin/update_vsite_logrotate.pl sbin/bxacct_dump.sh

ifdef CCETOPDIR
include $(CCETOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

PWD = $(shell pwd)

install: install_sbin

install_sbin:
	mkdir -p $(PREFIX)/usr/sausalito/sbin
	mkdir -p $(PREFIX)/usr/lib/systemd/system
	mkdir -p $(PREFIX)/usr/sausalito/ui/web/base/sitestats
	@for s in $(SCRIPTS); do \
		if [ ! -f $$s ]; then echo "ERROR: missing $$s" >&2; exit 1; fi; \
		install -m 0755 $$s $(PREFIX)/usr/sausalito/sbin/; \
	done
	install -m 0644 ./systemd/goaccess.service $(PREFIX)/usr/lib/systemd/system/goaccess.service
	install -m 0644 ./systemd/.htaccess $(PREFIX)/usr/sausalito/ui/web/base/sitestats/.htaccess
