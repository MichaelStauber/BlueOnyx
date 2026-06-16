ifndef CCEBASE
CCEBASE = /usr/sausalito
endif

include $(CCEBASE)/devel/defines.mk

SCRIPTS=sbin/jail_toggler.pl sbin/gauth_add.sh sbin/gauth_check.sh

all:

install:

	mkdir -p $(CCESBINDIR)
	for file in $(SCRIPTS); do \
		$(INSTALL) $(INSTALL_BINFLAGS) $$file $(CCESBINDIR); \
	done

rpm:
