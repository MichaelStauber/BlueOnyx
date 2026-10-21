ifndef CCEBASE
CCEBASE = /usr/sausalito
endif

include $(CCEBASE)/devel/defines.mk

SCRIPTS=sbin/jail_toggler.pl sbin/gauth_add.sh sbin/gauth_check.sh sbin/jailkit_integrity.pl
CRONS=etc/cron.daily/jailkit_integrity
# systemd drop-ins are not covered by module.mk's GLUEDIRS, so they are installed here
# and listed explicitly in the glue %files section of templates/rpmdefs.tmpl.
DROPIN_DIR=/etc/systemd/system/jailkit.service.d
DROPINS=etc/systemd/system/jailkit.service.d/zz-blueonyx-empty-config.conf

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
	mkdir -p $(PREFIX)$(DROPIN_DIR)
	for file in $(DROPINS); do \
		$(INSTALL) $(INSTALL_HEADERFLAGS) $$file $(PREFIX)$(DROPIN_DIR)/; \
	done

rpm:
