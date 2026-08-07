ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

.PHONY: base-ai-core-rpm

all: base-ai-core-rpm

install: base-ai-core-rpm

rpm: base-ai-core-rpm

base-ai-core-rpm:
	tar --exclude='.svn' -zcvf /tmp/base-ai-core.tar.gz ../base-ai-core &> /dev/null
	$(RPMBUILD) -ta /tmp/base-ai-core.tar.gz &> /tmp/rpm.log
	/bin/rm -f /tmp/base-ai-core.tar.gz
