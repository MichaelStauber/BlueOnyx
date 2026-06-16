# $Id: Makefile 

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

.PHONY: all
all: install

install: 
	mkdir -p $(PREFIX)/usr/bin
	install -m 0755 -o root -g root yum_wrapper $(PREFIX)/usr/bin/yum_wrapper
	install -m 0755 -o root -g root dnf_wrapper $(PREFIX)/usr/bin/dnf_wrapper