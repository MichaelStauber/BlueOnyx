ADM_PHP_PREFIX=/home/solarspeed/admserv-php
ADM_PHPIZE=$(ADM_PHP_PREFIX)/bin/phpize
ADM_PHPCONFIG=$(ADM_PHP_PREFIX)/bin/php-config
ADM_EXTENSION_DIR=$(shell $(ADM_PHPCONFIG) --extension-dir)
ADM_PHP_INI_DIR=$(ADM_PHP_PREFIX)/etc/php.d

INSTALL?=install
PREFIX?=

BUILD_DIR=build
BUILD_FILES=config.m4 php_i18n.h i18n_php8.c
MODULE=$(BUILD_DIR)/modules/i18n.so

.PHONY: all install clean local_clean

all: $(MODULE)

$(MODULE): $(BUILD_FILES)
	rm -rf $(BUILD_DIR)
	mkdir -p $(BUILD_DIR)
	cp $(BUILD_FILES) $(BUILD_DIR)/
	cd $(BUILD_DIR) && $(ADM_PHPIZE)
	cd $(BUILD_DIR) && ./configure --with-php-config=$(ADM_PHPCONFIG)
	$(MAKE) -C $(BUILD_DIR)
	chrpath -d $(MODULE)

install: $(MODULE) i18n.ini
	$(INSTALL) -d $(PREFIX)$(ADM_EXTENSION_DIR)
	$(INSTALL) -d $(PREFIX)$(ADM_PHP_INI_DIR)
	$(INSTALL) -m 0755 $(MODULE) $(PREFIX)$(ADM_EXTENSION_DIR)/i18n.so
	$(INSTALL) -m 0644 i18n.ini $(PREFIX)$(ADM_PHP_INI_DIR)/i18n.ini

clean local_clean:
	rm -rf $(BUILD_DIR)
