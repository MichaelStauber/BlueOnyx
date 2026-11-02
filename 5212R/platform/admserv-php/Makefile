# admserv-php - BlueOnyx AdmServ PHP-FPM (SOLARSPEED.NET)
# Build host: typically builder10 (EL9/5212R toolchain).
#
# IMPORTANT:
#   NEVER install into the live PREFIX during build.
#   configure uses --prefix=$(PREFIX) so paths baked into binaries match production,
#   but "make install" always uses INSTALL_ROOT=$(DESTDIR) under build/dest/.
#   (PHP ignores DESTDIR; INSTALL_ROOT is the supported staging variable.)
#   Only "rpm -Uvh" (or dnf) may touch /home/solarspeed/admserv-php on a host.
#
# Usage (same as other BX modules):
#   make rpm            # full result: download + build + stage + PECL + RPM/SRPM
#   make clean          # remove build/ + as_rpms/ + as_srpms/
#   make help
#
# Advanced (optional):
#   make stage / make package   # only if you know you need a partial re-run
#
# PHP version:
#   Default: newest $(PHP_MAJOR).x from php.net JSON API
#   Pin:     make rpm PHP_VERSION=8.4.27
#
# SVN never stores PHP upstream tarballs or unpacked sources (build/ is ignored).
# Upstream php-*.tar.gz lives in TARBALL_CACHE (/tmp) and survives make clean.

NAME            = admserv-php
PHP_MAJOR       = 8.4
RELEASE         = 4

# Final install path on BlueOnyx hosts (baked into binaries via ./configure --prefix)
PREFIX          = /home/solarspeed/admserv-php

TOP             := $(CURDIR)
BUILD_ROOT      := $(TOP)/build
SRC_DIR         := $(BUILD_ROOT)/src
# DESTDIR root for make install - NEVER the live filesystem prefix alone
DESTDIR         := $(BUILD_ROOT)/dest
# Staged tree that becomes the RPM payload (DESTDIR + PREFIX)
STAGE           := $(DESTDIR)$(PREFIX)

RESOLVE_SCRIPT  := $(TOP)/scripts/resolve-php-version.sh
ifeq ($(origin PHP_VERSION),command line)
  PHP_VERSION_EFFECTIVE := $(PHP_VERSION)
else
  PHP_VERSION_EFFECTIVE := $(shell $(RESOLVE_SCRIPT) $(PHP_MAJOR) 2>/dev/null)
endif
ifeq ($(strip $(PHP_VERSION_EFFECTIVE)),)
  PHP_VERSION_EFFECTIVE := 8.4.27
endif
PHP_VERSION     := $(PHP_VERSION_EFFECTIVE)

PHP_SRC         := $(SRC_DIR)/$(NAME)
# Cache outside the module tree (not SVN, not removed by make clean)
TARBALL_CACHE   ?= /tmp/admserv-php-src
TARBALL         := $(TARBALL_CACHE)/php-$(PHP_VERSION).tar.gz

RPM_SOURCES     ?= /usr/src/redhat/SOURCES
RPM_SPECS       ?= /usr/src/redhat/SPECS
RPM_RPMS        ?= /root/rpmbuild/RPMS/x86_64
RPM_SRPMS       ?= /root/rpmbuild/SRPMS
BUILDAREA_RPMS  ?= /home/buildarea_5200r/RPMS
BUILDAREA_SRPMS ?= /home/buildarea_5200r/SRPMS
# Local drop dirs under this module (svn-ignored; removed by make clean)
OUT_RPMS        := $(TOP)/as_rpms
OUT_SRPMS       := $(TOP)/as_srpms

# PHP 8.4 Zend extension API directory (cce.so / i18n.so at runtime)
ZEND_API        = 20240924

DOWNLOAD_URL    = https://www.php.net/distributions/php-$(PHP_VERSION).tar.gz
PHP_SHA256     ?= $(shell $(RESOLVE_SCRIPT) --sha256 $(PHP_MAJOR) $(PHP_VERSION) 2>/dev/null)

CONFIGURE       = $(TOP)/scripts/configure-admserv-php.sh
SKEL_ETC        = $(TOP)/skel/etc
SPEC_IN         = $(TOP)/templates/admserv-php.spec.in
SPEC_OUT        = $(BUILD_ROOT)/$(NAME)-$(PHP_VERSION)-$(RELEASE).spec

JOBS            ?= $(shell nproc 2>/dev/null || echo 2)

.PHONY: all help resolve-version download unpack configure build stage reskel \
        package rpm clean distclean distclean-cache print-vars check-host guard-live

all: rpm

help:
	@echo "$(NAME)  PHP_MAJOR=$(PHP_MAJOR)  PHP_VERSION=$(PHP_VERSION)  RELEASE=$(RELEASE)"
	@echo "Primary (like other modules):  make rpm"
	@echo "  -> download, configure, build, INSTALL_ROOT stage, PECL redis/apcu, rpmbuild"
	@echo "  -> outputs: as_rpms/*.rpm  as_srpms/*.src.rpm"
	@echo "Clean:  make clean   (build/ + as_rpms/ + as_srpms/; keeps $(TARBALL_CACHE)/ tarballs)"
	@echo "PREFIX (configure only)=$(PREFIX)  STAGE=$(STAGE)"
	@echo "TARBALL cache: $(TARBALL)"

print-vars:
	@echo "PHP_VERSION=$(PHP_VERSION) ZEND_API=$(ZEND_API)"
	@echo "PREFIX=$(PREFIX)"
	@echo "DESTDIR=$(DESTDIR)"
	@echo "STAGE=$(STAGE)"
	@echo "PHP_SRC=$(PHP_SRC) TARBALL=$(TARBALL)"

resolve-version:
	@echo "Latest PHP $(PHP_MAJOR).x: $$($(RESOLVE_SCRIPT) $(PHP_MAJOR))"
	@echo "Effective PHP_VERSION=$(PHP_VERSION)"

# Refuse accidental writes to live prefix
guard-live:
	@case "$(DESTDIR)" in \
	  ""|"/") echo "ERROR: DESTDIR must be set to a build staging path"; exit 1;; \
	  $(PREFIX)|$(PREFIX)/*) echo "ERROR: DESTDIR must not be live PREFIX"; exit 1;; \
	esac
	@case "$(STAGE)" in \
	  $(PREFIX)) echo "ERROR: STAGE collides with live PREFIX"; exit 1;; \
	esac
	@echo "OK: staging under $(DESTDIR) (live $(PREFIX) untouched by make)"

check-host:
	@test -x /usr/bin/rpmbuild || { echo "ERROR: rpmbuild missing (build on builder10)"; exit 1; }
	@test -d $(RPM_SOURCES) || mkdir -p $(RPM_SOURCES) $(RPM_SPECS)

download: $(TARBALL)

$(TARBALL):
	mkdir -p $(TARBALL_CACHE)
	@echo "PHP_VERSION=$(PHP_VERSION)"
	@if [ -f "$(TARBALL)" ]; then \
		echo "Already have $(TARBALL) (survives make clean)"; \
	elif [ -f /home/php.source/php-$(PHP_VERSION).tar.gz ]; then \
		echo "Copying /home/php.source/php-$(PHP_VERSION).tar.gz -> $(TARBALL)"; \
		cp -a /home/php.source/php-$(PHP_VERSION).tar.gz "$(TARBALL)"; \
	else \
		echo "Downloading $(DOWNLOAD_URL) -> $(TARBALL)"; \
		wget -O "$(TARBALL).partial" --no-check-certificate "$(DOWNLOAD_URL)"; \
		mv "$(TARBALL).partial" "$(TARBALL)"; \
	fi
	@if [ -n "$(PHP_SHA256)" ]; then \
		echo "$(PHP_SHA256)  $(TARBALL)" | sha256sum -c -; \
	else \
		echo "NOTE: no SHA256 from resolver; skip verify"; \
	fi

unpack: download
	rm -rf $(PHP_SRC) $(SRC_DIR)/php-$(PHP_VERSION)
	mkdir -p $(SRC_DIR)
	tar -C $(SRC_DIR) -zxf $(TARBALL)
	mv $(SRC_DIR)/php-$(PHP_VERSION) $(PHP_SRC)
	chmod 755 $(CONFIGURE)
	cp -a $(CONFIGURE) $(PHP_SRC)/configure-admserv-php.sh

# Configure with production --prefix (path strings in binaries) - does NOT install anywhere.
configure: unpack
	cd $(PHP_SRC) && ./configure-admserv-php.sh -p $(PHP_MAJOR)

build: configure
	$(MAKE) -C $(PHP_SRC) -j$(JOBS)

# Apply BX skel onto STAGED tree only
define APPLY_SKEL
	mkdir -p $(STAGE)/docs $(STAGE)/etc/php.d $(STAGE)/etc/php.disabled
	cp -a $(SKEL_ETC)/php.d/. $(STAGE)/etc/php.d/
	cp -a $(SKEL_ETC)/php.disabled/. $(STAGE)/etc/php.disabled/
	cp -a $(SKEL_ETC)/php.ini $(STAGE)/etc/php.ini
	cp -a $(SKEL_ETC)/php-fpm.conf $(STAGE)/etc/php-fpm.conf
	test -f $(SKEL_ETC)/php-fpm.conf.default && cp -a $(SKEL_ETC)/php-fpm.conf.default $(STAGE)/etc/php-fpm.conf.default || true
	test -f $(SKEL_ETC)/pear.conf && cp -a $(SKEL_ETC)/pear.conf $(STAGE)/etc/pear.conf || true
	sed -i 's|^auto_prepend_file *=.*|auto_prepend_file =|' $(STAGE)/etc/php.ini
	sed -i 's|^auto_append_file *=.*|auto_append_file =|' $(STAGE)/etc/php.ini || true
	sed -i 's|^extension_dir *=.*|extension_dir = "$(PREFIX)/lib/php/$(ZEND_API)"|' $(STAGE)/etc/php.ini
	sed -i 's|/lib/php/[0-9][0-9]*|/lib/php/$(ZEND_API)|g' $(STAGE)/etc/pear.conf || true
	# Rewrite any Zend API dir in php.d inis to this build
	sed -i 's|/lib/php/[0-9][0-9]*/|/lib/php/$(ZEND_API)/|g' $(STAGE)/etc/php.d/*.ini || true
	# Never ship cce/i18n (other RPMs)
	rm -f $(STAGE)/etc/php.d/cce.ini $(STAGE)/etc/php.d/i18n.ini
	rm -f $(STAGE)/lib/php/$(ZEND_API)/cce.so $(STAGE)/lib/php/$(ZEND_API)/i18n.so $(STAGE)/lib/php/$(ZEND_API)/i18.so
	# GUI expects exact name opcache.ini (not 10-opcache.ini)
	rm -f $(STAGE)/etc/php.d/10-opcache.ini
endef

# make install into DESTDIR only - PHP uses INSTALL_ROOT (DESTDIR is ignored!)
# Live PREFIX must never receive files from this target.
stage: guard-live build
	rm -rf $(DESTDIR)
	mkdir -p $(DESTDIR)
	$(MAKE) -C $(PHP_SRC) install INSTALL_ROOT=$(DESTDIR)
	@test -x $(STAGE)/bin/php || { \
	  echo "ERROR: stage missing $(STAGE)/bin/php - install did not honor INSTALL_ROOT"; \
	  echo "Refusing to continue (check live $(PREFIX) was not written)"; exit 1; \
	}
	# Guard: live binary mtime/size should not match a just-built 8.4 if we still run 8.3 RPM
	@if [ -x $(PREFIX)/bin/php ]; then \
	  live_v=$$($(PREFIX)/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true); \
	  stage_v=$$($(STAGE)/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true); \
	  echo "live PHP=$$live_v  staged PHP=$$stage_v"; \
	fi
	chmod 755 $(TOP)/scripts/build-pecl-exts.sh
	$(TOP)/scripts/build-pecl-exts.sh $(STAGE) $(ZEND_API)
	$(APPLY_SKEL)
	@test -f $(STAGE)/lib/php/$(ZEND_API)/redis.so
	@test -f $(STAGE)/lib/php/$(ZEND_API)/apcu.so
	@test -f $(STAGE)/etc/php.d/opcache.ini
	@test -f $(STAGE)/etc/php.d/redis.ini
	@test -f $(STAGE)/etc/php.d/apcu.ini
	@echo "Staged php.d:"; ls -la $(STAGE)/etc/php.d/
	@echo "Staged at $(STAGE)"
	@$(STAGE)/bin/php -n -v 2>&1 | head -5
	@$(STAGE)/bin/php -m 2>&1 | grep -iE 'redis|apcu|opcache|ionCube' || true

# Re-apply skel on existing STAGE (no recompile)
reskel: guard-live
	@test -x $(STAGE)/bin/php || { echo "ERROR: $(STAGE)/bin/php missing - run make stage first"; exit 1; }
	$(APPLY_SKEL)
	@$(STAGE)/bin/php -v 2>&1 | head -5

# RPM from staged tree only
package: check-host guard-live
	@test -x $(STAGE)/bin/php || { echo "ERROR: missing $(STAGE)/bin/php - run make stage first"; exit 1; }
	@echo "Rolling RPM $(NAME)-$(PHP_VERSION)-$(RELEASE) from STAGE=$(STAGE)"
	mkdir -p $(BUILD_ROOT)
	sed \
		-e 's@XXX_MASTER_XXX@$(NAME)@g' \
		-e 's@XXX_PACKAGE_XXX@$(PHP_MAJOR)@g' \
		-e 's@XXX_VERSION_XXX@$(PHP_VERSION)@g' \
		-e 's@XXX_RELEASE_XXX@$(RELEASE)@g' \
		-e 's@XXX_ZEND_API_XXX@$(ZEND_API)@g' \
		$(SPEC_IN) > $(SPEC_OUT)
	printf '\n* %s Michael Stauber <mstauber@solarspeed.net>\n- %s-%s-%s: New build of %s-%s (SVN admserv-php Makefile, DESTDIR staging)\n' \
		"$$(date +'%a %b %d %Y')" \
		"$(NAME)" "$(PHP_VERSION)" "$(RELEASE)" "$(NAME)" "$(PHP_VERSION)" \
		>> $(SPEC_OUT)
	cp -a $(SPEC_OUT) $(RPM_SPECS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).spec
	rm -rf $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)
	# Payload layout matches %install: home/ etc/ usr/ at tarball top
	mkdir -p $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/home/solarspeed
	mkdir -p $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/etc/cron.daily
	mkdir -p $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/usr/sausalito/sessions/php
	cp -a $(STAGE) $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/home/solarspeed/admserv-php
	cp -a $(TOP)/skel/etc/cron.daily/tmpwatch-admserv-php-sessions \
		$(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/etc/cron.daily/
	chmod 755 $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/etc/cron.daily/tmpwatch-admserv-php-sessions
	touch $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)/usr/sausalito/sessions/php/.keep
	cd $(RPM_SOURCES) && tar czf $(NAME)-$(PHP_VERSION)-$(RELEASE).tar.gz $(NAME)-$(PHP_VERSION)-$(RELEASE)
	rm -rf $(RPM_SOURCES)/$(NAME)-$(PHP_VERSION)-$(RELEASE)
	rm -f $(RPM_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm
	rpmbuild -ba $(RPM_SPECS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).spec
	@if [ -f $(RPM_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm ]; then \
		echo "OK: $(RPM_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm"; \
		mkdir -p $(BUILDAREA_RPMS) $(BUILDAREA_SRPMS) $(OUT_RPMS) $(OUT_SRPMS); \
		cp -a $(RPM_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm $(BUILDAREA_RPMS)/; \
		cp -a $(RPM_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm $(OUT_RPMS)/; \
		cp -a $(RPM_SRPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).src.rpm $(BUILDAREA_SRPMS)/ 2>/dev/null || true; \
		cp -a $(RPM_SRPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).src.rpm $(OUT_SRPMS)/ 2>/dev/null || true; \
		ls -la $(OUT_RPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).x86_64.rpm; \
		ls -la $(OUT_SRPMS)/$(NAME)-$(PHP_VERSION)-$(RELEASE).src.rpm 2>/dev/null || true; \
		echo "Live $(PREFIX) was NOT modified by this make. Install RPM explicitly when ready."; \
	else \
		echo "ERROR: RPM not found after rpmbuild"; exit 1; \
	fi

rpm: stage
	@$(MAKE) package

# Drop build/ and local as_rpms / as_srpms - never touch live PREFIX
# Does NOT delete $(TARBALL_CACHE) (php-*.tar.gz in /tmp for faster rebuilds)
clean:
	rm -rf $(BUILD_ROOT) $(OUT_RPMS) $(OUT_SRPMS)
	@echo "clean: removed $(BUILD_ROOT) $(OUT_RPMS) $(OUT_SRPMS)"
	@echo "clean: kept tarball cache $(TARBALL_CACHE) (override TARBALL_CACHE=...; use make distclean-cache to wipe)"

# Optional: wipe cached upstream tarballs in /tmp
distclean-cache:
	rm -rf $(TARBALL_CACHE)
	@echo "distclean-cache: removed $(TARBALL_CACHE)"

distclean: clean
