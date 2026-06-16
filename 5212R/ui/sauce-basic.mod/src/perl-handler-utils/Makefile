# $Id: Makefile

ifdef TOPDIR
include $(TOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

PERLDIRS := $(shell find . -name CVS -or -mindepth 1 -type d -print )
PERLSRCS := $(shell find . -name \*.p* -print )
PERLRPMS := $(shell find . -name \*.src.rpm -or -name \*.rpm -type f -print )
HELPERS := $(shell find . -name txn\* -type f -print )

install: rpms $(PERLSRCS) 
	install -d -o root -g root -m 0755 \
  	$(patsubst ./%, $(CCEPERLDIR)/%, $(PERLDIRS))
	install -d -o root -g root -m 0755 $(CCESBINDIR)
	for t in $(PERLSRCS) ; do install -o root -g root -m 0755 \
  	$$t $(CCEPERLDIR)/$$t ; done
	for t in $(HELPERS) ; do install -o root -g root -m 0755 \
	$$t $(CCESBINDIR)/$$t ; done
	mkdir -p $(PREFIX)/usr/lib/systemd/system/
	install ./sauce_serviced.service $(PREFIX)/usr/lib/systemd/system/sauce_serviced.service

all:
	@echo Nothing to do.

rpms: $(PERLRPMS)
	test "$(PERLRPMS)" = "" || rpm -U --force $(PERLRPMS)
