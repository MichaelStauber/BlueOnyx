# $Id: Makefile
#

SCRIPTS=sbin/toggle_nginx.pl


ifdef CCETOPDIR
include $(CCETOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

INSTALL = /usr/bin/install -m 0700 -o root 
PWD = $(shell pwd)

install: install_sbin

install_sbin:
	$(INSTALL) -d -o root -m 0755 $(PREFIX)/usr/sausalito/sbin; \
	$(INSTALL) -o root -m 0755 $(SCRIPTS) $(PREFIX)/usr/sausalito/sbin/; \

