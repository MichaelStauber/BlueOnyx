ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

.PHONY: base-alpine-ci4-vendor-rpm

all: base-alpine-ci4-vendor-rpm

install: base-alpine-ci4-vendor-rpm

rpm: base-alpine-ci4-vendor-rpm

base-alpine-ci4-vendor-rpm:
		tar --exclude='.svn' -zcvf /tmp/base-alpine-ci4-vendor.tar.gz base-alpine-ci4-vendor base-alpine-ci4-vendor.spec &> /dev/null
		$(RPMBUILD) -ta /tmp/base-alpine-ci4-vendor.tar.gz &> /tmp/rpm.log
		/bin/rm -f /tmp/base-alpine-ci4-vendor.tar.gz
