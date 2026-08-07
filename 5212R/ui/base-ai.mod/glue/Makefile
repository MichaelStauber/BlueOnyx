ifndef CCEBASE
CCEBASE = /usr/sausalito
endif

include $(CCEBASE)/devel/defines.mk

SCRIPTS=sbin/ai_helper.pl
CONFIGS=sudoers/blueonyx_ai

all:

install:
	mkdir -p $(CCESBINDIR)
	for file in $(SCRIPTS); do \
		$(INSTALL) $(INSTALL_BINFLAGS) $$file $(CCESBINDIR); \
	done
	mkdir -p $(PREFIX)/etc/sudoers.d
	for file in $(CONFIGS); do \
		$(INSTALL) $(INSTALL_HEADERFLAGS) $$file $(PREFIX)/etc/sudoers.d/; \
	done

rpm:
