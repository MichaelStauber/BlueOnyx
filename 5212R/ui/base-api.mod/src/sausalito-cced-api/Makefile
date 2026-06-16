ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

.PHONY: sausalito-cced-api-rpm

all: sausalito-cced-api-rpm

install: sausalito-cced-api-rpm

rpm: sausalito-cced-api-rpm

sausalito-cced-api-rpm:
	tar --exclude='.svn' -zcvf /tmp/sausalito-cced-api.tar.gz ../sausalito-cced-api &> /dev/null
	$(RPMBUILD) -ta /tmp/sausalito-cced-api.tar.gz &> /tmp/rpm.log
	/bin/rm -f /tmp/sausalito-cced-api.tar.gz
