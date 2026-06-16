ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

PKGNAME = blueonyx-db
VERSION = 1.85
SOURCE_TARBALL = $(CURDIR)/$(PKGNAME)-$(VERSION).tar.gz
WORKDIR = /tmp/$(PKGNAME)-$(VERSION)
TOPDIR = /tmp/$(PKGNAME)-$(VERSION)-rpmbuild
SPEC = $(CURDIR)/$(PKGNAME)-$(VERSION).spec
SCRIPTS = examine_codb.pl repair_codb_classes.pl

.PHONY: all source rpm srpm clean

all: rpm

refresh-source:
	@test -n "$(SOURCE_DIR)" || (echo "SOURCE_DIR must be set, e.g. make refresh-source SOURCE_DIR=/path/to/db.1.85" && false)
	rm -rf $(WORKDIR)
	mkdir -p $(WORKDIR)/$(PKGNAME)-$(VERSION)
	cp -a $(SOURCE_DIR) $(WORKDIR)/$(PKGNAME)-$(VERSION)/db.1.85
	find $(WORKDIR)/$(PKGNAME)-$(VERSION)/db.1.85/PORT/linux -type f \
		\( -name '*.o' -o -name 'libdb.a' -o -name 'db_load185' \) -delete
	tar -C $(WORKDIR) -czf $(SOURCE_TARBALL) $(PKGNAME)-$(VERSION)

source: $(SOURCE_TARBALL)

$(SOURCE_TARBALL):
	@echo "Missing $(SOURCE_TARBALL). Run: make refresh-source SOURCE_DIR=/path/to/db.1.85"
	@false

srpm: source
	rm -rf $(TOPDIR)
	mkdir -p $(TOPDIR)/BUILD $(TOPDIR)/RPMS $(TOPDIR)/SOURCES $(TOPDIR)/SPECS $(TOPDIR)/SRPMS
	cp $(SOURCE_TARBALL) $(TOPDIR)/SOURCES/
	cp db_load185.c $(TOPDIR)/SOURCES/
	cp $(SCRIPTS) $(TOPDIR)/SOURCES/
	cp $(SPEC) $(TOPDIR)/SPECS/
	$(RPMBUILD) -bs --define "_topdir $(TOPDIR)" $(TOPDIR)/SPECS/$(PKGNAME)-$(VERSION).spec

rpm: source
	rm -rf $(TOPDIR)
	mkdir -p $(TOPDIR)/BUILD $(TOPDIR)/RPMS $(TOPDIR)/SOURCES $(TOPDIR)/SPECS $(TOPDIR)/SRPMS
	cp $(SOURCE_TARBALL) $(TOPDIR)/SOURCES/
	cp db_load185.c $(TOPDIR)/SOURCES/
	cp $(SCRIPTS) $(TOPDIR)/SOURCES/
	cp $(SPEC) $(TOPDIR)/SPECS/
	$(RPMBUILD) -ba --define "_topdir $(TOPDIR)" $(TOPDIR)/SPECS/$(PKGNAME)-$(VERSION).spec

clean:
	rm -rf $(WORKDIR) $(TOPDIR)
