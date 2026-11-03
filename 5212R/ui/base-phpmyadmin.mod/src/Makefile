# $Id$
# module.mk (BUILDSRC=yes) runs: make -C src rpm|all|install
# Nested package builds blueonyx-phpMyAdmin from upstream + SSO.

ifdef TOPDIR
include $(TOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

DIRS = blueonyx-phpMyAdmin

.PHONY: all install rpm clean

all:
	for t in $(DIRS) ; do \
		$(MAKE) -C $$t all ; \
	done

install: all
	for t in $(DIRS) ; do \
		$(MAKE) -C $$t install || $(MAKE) -C $$t rpm ; \
	done

rpm:
	for t in $(DIRS) ; do \
		$(MAKE) -C $$t rpm ; \
	done

clean:
	for t in $(DIRS) ; do \
		$(MAKE) -C $$t clean || true ; \
	done
