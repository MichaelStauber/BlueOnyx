# Makefile for cce.so PHP 8.3 extension - BlueOnyx 5212R
# Uses phpize/php-config build system like the i18n extension.
#
# CCE_TOPDIR must be set (via environment or make argument).
# Points to the root of the CCE source tree so we can find
# cce.h, cce_common.h, libcce, libcce_common, etc.
#
# The config.m4 handles library linkage. The Makefile passes
# EXTRA_CFLAGS and EXTRA_LDFLAGS to the phpize-generated Makefile
# for include paths and library paths that config.m4 can't resolve.

ADM_PHP_PREFIX=/home/solarspeed/admserv-php
ADM_PHPIZE=$(ADM_PHP_PREFIX)/bin/phpize
ADM_PHPCONFIG=$(ADM_PHP_PREFIX)/bin/php-config
ADM_EXTENSION_DIR=$(shell $(ADM_PHPCONFIG) --extension-dir)
ADM_PHP_INI_DIR=$(ADM_PHP_PREFIX)/etc/php.d

INSTALL?=install
PREFIX?=

BUILD_DIR=build
HEADER_FILE=../include/php_cce.h
MODULE=$(BUILD_DIR)/modules/cce.so

# CCE library and include paths (requires CCE_TOPDIR)
CCE_INCS=-I$(CCE_TOPDIR)/include -I$(CCE_TOPDIR)/client/include
CCE_LIBS=-L$(CCE_TOPDIR) -L$(CCE_TOPDIR)/client/src -lcce_common -lcce

# glib flags
GLIB_CFLAGS=$(shell glib-config --cflags)
GLIB_LIBS=$(shell glib-config --libs)

.PHONY: all install clean local_clean

all: $(MODULE)

$(MODULE): config.m4 $(HEADER_FILE) cce.c
	rm -rf $(BUILD_DIR)
	mkdir -p $(BUILD_DIR)
	cp config.m4 $(BUILD_DIR)/
	cp $(HEADER_FILE) $(BUILD_DIR)/php_cce.h
	cp cce.c $(BUILD_DIR)/
	cd $(BUILD_DIR) && $(ADM_PHPIZE)
	cd $(BUILD_DIR) && ./configure --with-php-config=$(ADM_PHPCONFIG) --enable-cce
	$(MAKE) -C $(BUILD_DIR) EXTRA_CFLAGS="$(CCE_INCS) $(GLIB_CFLAGS) -D_GNU_SOURCE" EXTRA_LDFLAGS="$(CCE_LIBS) $(GLIB_LIBS)"
	chrpath -d $(MODULE)

install: $(MODULE) cce.ini
	$(INSTALL) -d $(PREFIX)$(ADM_EXTENSION_DIR)
	$(INSTALL) -d $(PREFIX)$(ADM_PHP_INI_DIR)
	$(INSTALL) -m 0755 $(MODULE) $(PREFIX)$(ADM_EXTENSION_DIR)/cce.so
	$(INSTALL) -m 0644 cce.ini $(PREFIX)$(ADM_PHP_INI_DIR)/cce.ini

clean local_clean:
	rm -rf $(BUILD_DIR)