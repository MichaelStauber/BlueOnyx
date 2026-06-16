ifndef CCEBASE
CCEBASE = /usr/sausalito
endif

include $(CCEBASE)/devel/defines.mk

SCRIPTS=
all:

install:
	skel_files=`find etc -type f -not -path '*CVS*' -not -path '*/.*'`; \
	for file in $$skel_files; do \
		dir=`dirname $$file`; \
		if [ ! -d "$(PREFIX)/$$dir" ]; then \
			$(INSTALL) $(INSTALL_DEFAULT) -d $(PREFIX)/$$dir; \
		fi; \
		$(INSTALL) $(INSTALL_HEADERFLAGS) $$file $(PREFIX)/$$dir; \
	done
		mkdir -p $(PREFIX)/usr/sausalito/bin
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_backup_check.pl $(PREFIX)/usr/sausalito/bin/radicale_backup_check.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_backup.pl $(PREFIX)/usr/sausalito/bin/radicale_backup.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_list.pl $(PREFIX)/usr/sausalito/bin/radicale_list.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_update.pl $(PREFIX)/usr/sausalito/bin/radicale_update.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_create.pl $(PREFIX)/usr/sausalito/bin/radicale_create.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) bin/radicale_delete.pl $(PREFIX)/usr/sausalito/bin/radicale_delete.pl
		mkdir -p $(PREFIX)/usr/sausalito/sbin
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/radicale_backup_all.pl $(PREFIX)/usr/sausalito/sbin/radicale_backup_all.pl
		$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/radicale_restore_all.pl $(PREFIX)/usr/sausalito/sbin/radicale_restore_all.pl
rpm:
