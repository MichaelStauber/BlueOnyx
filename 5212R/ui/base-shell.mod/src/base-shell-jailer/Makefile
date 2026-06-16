# $Id: Makefile 
#
include /usr/sausalito/devel/defines.mk

UDIR = $(SYSTEMDUNITDIR)
UNIT = jailer.service
SBINDIR = $(CCESBINDIR)
BIN = jailer.sh

.PHONY: all
all: install

install:
	mkdir -p $(SYSTEMDUNITDIR)
	mkdir -p $(CCESBINDIR)
	install -m 0644 -o root -g root $(UNIT) $(SYSTEMDUNITDIR)
	install -m 0755 -o root -g root $(BIN) $(CCESBINDIR)
