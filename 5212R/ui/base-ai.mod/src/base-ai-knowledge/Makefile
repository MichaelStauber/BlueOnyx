ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

.PHONY: all install rpm clean

all:
	@true

install: all

rpm: all
	tar --exclude='.svn' -zcvf /tmp/base-ai-knowledge.tar.gz ../base-ai-knowledge &> /dev/null
	$(RPMBUILD) -ta /tmp/base-ai-knowledge.tar.gz &> /tmp/rpm.log
	/bin/rm -f /tmp/base-ai-knowledge.tar.gz

clean:
	@true
