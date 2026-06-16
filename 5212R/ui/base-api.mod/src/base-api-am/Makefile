# $Id: Makefile

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

BINDIR = $(SWATCHBINDIR)
BINS = am_api.pl

.PHONY: all
all: install
install: install
rpm: base-api-am-rpm

install: $(BINS)
	mkdir -p $(BINDIR)
	install -m 750 -o root -g root $(BINS) $(BINDIR)

base-api-am-rpm:
	tar --exclude='.svn' -zcvf /tmp/base-api-am.tar.gz ../base-api-am &> /dev/null
	$(RPMBUILD) -ta /tmp/base-api-am.tar.gz &> /tmp/rpm.log
	/bin/rm -f /tmp/base-api-am.tar.gz