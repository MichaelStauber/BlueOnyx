# $Id: Makefile 

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

.PHONY: all
all: install

install: 
	mkdir -p $(PREFIX)/usr/sausalito/sbin
	install -m 0755 -o root -g root mb2md.pl $(PREFIX)/usr/sausalito/sbin/mb2md.pl
	install -m 0755 -o root -g root mbox_maildir_convert.pl $(PREFIX)/usr/sausalito/sbin/mbox_maildir_convert.pl
