# $Id: Makefile 3 2003-07-17 15:19:15Z will $

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

SBINDIR = $(CCESBINDIR)
BINS = bxvzresolve.sh
UNIT = bxvzresolve.service

all:
	true

install: all
	mkdir -p $(SBINDIR)
	install -m 750 -o root -g root $(BINS) $(SBINDIR)
	mkdir -p $(SYSTEMDUNITDIR)
	install -m 644 -o root -g root bxvzresolve.service $(SYSTEMDUNITDIR)/bxvzresolve.service

