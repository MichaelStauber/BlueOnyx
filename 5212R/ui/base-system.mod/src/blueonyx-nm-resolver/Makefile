# $Id: Makefile 

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

.PHONY: all
all: install

install: 
	mkdir -p $(PREFIX)/etc/NetworkManager/dispatcher.d
	install -m 755 50-blueonyx $(PREFIX)/etc/NetworkManager/dispatcher.d/50-blueonyx
	mkdir -p $(PREFIX)/etc/NetworkManager/conf.d
	install -m 644 no-dns.conf $(PREFIX)/etc/NetworkManager/conf.d/no-dns.conf
	mkdir -p $(PREFIX)/usr/sausalito/sbin
	install -m 755 refresh_resolver.pl $(PREFIX)/usr/sausalito/sbin/refresh_resolver.pl
	mkdir -p $(PREFIX)/usr/sausalito/bin
	install -m 755 fix_hostname.pl $(PREFIX)/usr/sausalito/bin/fix_hostname.pl
