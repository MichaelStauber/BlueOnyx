# $Id: Makefile 259 2004-01-03 06:28:40Z shibuya $
#
# Copyright 2001 Sun Microsystems, Inc.  All rights reserved.
#

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

BINDIR = $(SWATCHBINDIR)
BINS = am_apache.exp am_apache.sh am_admserv.exp am_phpfpm.pl am_phpfpm56.pl am_phpfpm70.pl am_phpfpm71.pl am_phpfpm72.pl am_phpfpm73.pl am_phpfpm74.pl am_apache.pl am_phpfpm80.pl am_phpfpm81.pl am_phpfpm82.pl am_phpfpm83.pl am_phpfpm84.pl am_phpfpm85.pl am_phpfpm86.pl am_phpfpm90.pl am_phpfpm91.pl am_phpfpm92.pl am_phpfpm93.pl am_phpfpm94.pl

.PHONY: all
all: install

install: $(BINS)
	mkdir -p $(BINDIR)
	install -m 750 -o root -g root $(BINS) $(BINDIR)
