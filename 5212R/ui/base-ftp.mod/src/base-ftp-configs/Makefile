# $Id: Makefile 259 2004-01-03 06:28:40Z shibuya $
#
# Copyright 2001 Sun Microsystems, Inc.  All rights reserved.
#

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

CONFDIR = $(CCEBASE)/configs/proftpd
BINS = proftpd proftpd.conf

.PHONY: all
all: install

install: $(BINS)
	mkdir -p $(CONFDIR)
	install -m 0644 -o root -g root $(BINS) $(CONFDIR)
