# $Id: Makefile 259 2004-01-03 06:28:40Z shibuya $
# Copyright 2001 Sun Microsystems, Inc.  All rights reserved.
# Copyright (c) 2025 Michael Stauber, SOLARSPEED.NET
#
# Makefile for PHP CCE extension and class files
#

include ../../dynamic.mk

CCEMODULE = cce-libcce

all:
	$(MAKE) -C src CCE_TOPDIR=$(CCE_TOPDIR)

clean:
	$(MAKE) -C src clean

depend:

install:
	$(MAKE) -C src CCE_TOPDIR=$(CCE_TOPDIR) install
	$(MAKE) -C class install