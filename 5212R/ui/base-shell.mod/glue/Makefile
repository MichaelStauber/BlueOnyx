ifndef CCEBASE
CCEBASE = /usr/sausalito
endif

include $(CCEBASE)/devel/defines.mk

SCRIPTS=sbin/jail_toggler.pl sbin/gauth_add.sh sbin/gauth_check.sh sbin/jailkit_integrity.pl
CRONS=etc/cron.daily/jailkit_integrity

all:

install:

	mkdir -p $(CCESBINDIR)
	for file in $(SCRIPTS); do \
		$(INSTALL) $(INSTALL_BINFLAGS) $$file $(CCESBINDIR); \
	done
	mkdir -p $(PREFIX)/etc/cron.daily
	for file in $(CRONS); do \
		$(INSTALL) $(INSTALL_BINFLAGS) $$file $(PREFIX)/etc/cron.daily/; \
	done

rpm:
