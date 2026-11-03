# blueonyx-phpMyAdmin - download upstream phpMyAdmin, apply BlueOnyx SSO, RPM
#
# Usage (on build host):
#   make rpm          # full pipeline
#   make clean        # remove build/ as_rpms as_srpms (keeps /tmp tarball cache)
#
# Tarball cache: /tmp/phpmyadmin-src/ (survives clean, not in SVN)
# Install path:  /usr/sausalito/ui/web/base/phpMyAdmin
#
# SSO: skel/config.inc.php + skel/signon-script-blueonyx.php
# GUI module base-phpmyadmin.mod Requires: this package.

TOP := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
BUILD_ROOT := $(TOP)/build
STAGE := $(BUILD_ROOT)/dest
OUT_RPMS := $(TOP)/as_rpms
OUT_SRPMS := $(TOP)/as_srpms

TARBALL_CACHE ?= /tmp/phpmyadmin-src
PMA_MAJOR_LINE ?= 5.2
RELEASE ?= 1

# Resolved at build time
RESOLVE := $(TOP)/scripts/resolve-pma-version.sh
SKEL := $(TOP)/skel
SPEC_IN := $(TOP)/templates/blueonyx-phpMyAdmin.spec.in

PMA_WEB := /usr/sausalito/ui/web/base/phpMyAdmin
RPM_TOPDIR := $(shell rpm --eval '%{_topdir}' 2>/dev/null || echo $(HOME)/rpmbuild)

.PHONY: all help rpm install clean distclean-cache download unpack stage package

# module.mk: make -C src all|install|rpm — all three build the RPM
all: rpm
install: rpm

help:
	@echo "Targets: make rpm | clean | distclean-cache"
	@echo "Cache:   $(TARBALL_CACHE)  (PMA_MAJOR_LINE=$(PMA_MAJOR_LINE))"
	@echo "SSO:     $(SKEL)/config.inc.php + signon-script-blueonyx.php"

# --- version / download ---
.PHONY: resolve
resolve:
	@chmod +x $(RESOLVE)
	@$(RESOLVE)

define RESOLVE_VARS
$(eval _R := $(shell chmod +x $(RESOLVE) >/dev/null 2>&1; $(RESOLVE)))
$(eval PMA_VERSION := $(word 1,$(_R)))
$(eval PMA_URL := $(word 2,$(_R)))
$(eval PMA_SHA := $(word 3,$(_R)))
$(eval TARBALL := $(TARBALL_CACHE)/phpMyAdmin-$(PMA_VERSION)-all-languages.tar.gz)
$(eval SRC_DIR := $(BUILD_ROOT)/phpMyAdmin-$(PMA_VERSION)-all-languages)
endef

download:
	$(call RESOLVE_VARS)
	@echo "phpMyAdmin $(PMA_VERSION)"
	@mkdir -p $(TARBALL_CACHE)
	@if [ -f "$(TARBALL)" ]; then \
		echo "Reusing $(TARBALL)"; \
	else \
		echo "Downloading $(PMA_URL)"; \
		curl -fL --retry 3 -o "$(TARBALL).partial" "$(PMA_URL)"; \
		mv "$(TARBALL).partial" "$(TARBALL)"; \
	fi
	@if [ -n "$(PMA_SHA)" ]; then \
		echo "$(PMA_SHA)  $(TARBALL)" | sha256sum -c - || \
		  (echo "SHA256 mismatch" >&2; exit 1); \
	fi

unpack: download
	$(call RESOLVE_VARS)
	@rm -rf "$(BUILD_ROOT)/phpMyAdmin-"*
	@mkdir -p $(BUILD_ROOT)
	@tar xzf "$(TARBALL)" -C $(BUILD_ROOT)
	@test -d "$(SRC_DIR)" || (echo "missing $(SRC_DIR)" >&2; exit 1)
	@echo "Unpacked $(SRC_DIR)"

# --- stage payload ---
stage: unpack
	$(call RESOLVE_VARS)
	@rm -rf $(STAGE)
	@mkdir -p $(STAGE)$(PMA_WEB)
	@mkdir -p $(STAGE)/etc/admserv/conf.d
	@# Upstream tree
	@cp -a "$(SRC_DIR)/." $(STAGE)$(PMA_WEB)/
	@# BlueOnyx SSO overlays (always win)
	@install -m 0644 $(SKEL)/config.inc.php $(STAGE)$(PMA_WEB)/config.inc.php
	@install -m 0644 $(SKEL)/signon-script-blueonyx.php $(STAGE)$(PMA_WEB)/signon-script-blueonyx.php
	@install -m 0644 $(SKEL)/etc/admserv/conf.d/phpMyAdmin.conf \
		$(STAGE)/etc/admserv/conf.d/phpMyAdmin.conf
	@# setup/ must not be world-runnable via web (conf also denies)
	@if [ -d $(STAGE)$(PMA_WEB)/setup ]; then chmod 700 $(STAGE)$(PMA_WEB)/setup; fi
	@# Drop junk
	@rm -rf $(STAGE)$(PMA_WEB)/.git $(STAGE)$(PMA_WEB)/.github 2>/dev/null || true
	@echo "Staged under $(STAGE)"

# --- RPM ---
package: stage
	$(call RESOLVE_VARS)
	@mkdir -p $(OUT_RPMS) $(OUT_SRPMS)
	@mkdir -p $(RPM_TOPDIR)/SOURCES $(RPM_TOPDIR)/SPECS $(RPM_TOPDIR)/BUILD \
		$(RPM_TOPDIR)/RPMS $(RPM_TOPDIR)/SRPMS
	@# Payload tarball for rpmbuild %setup style: rooted tree named blueonyx-phpMyAdmin-VER
	@rm -rf $(BUILD_ROOT)/blueonyx-phpMyAdmin-$(PMA_VERSION)
	@mkdir -p $(BUILD_ROOT)/blueonyx-phpMyAdmin-$(PMA_VERSION)
	@# Copy stage contents so paths are relative under the named dir for %install cp -a *
	@(cd $(STAGE) && tar cf - .) | (cd $(BUILD_ROOT)/blueonyx-phpMyAdmin-$(PMA_VERSION) && tar xf -)
	@tar -C $(BUILD_ROOT) -czf $(RPM_TOPDIR)/SOURCES/blueonyx-phpMyAdmin-$(PMA_VERSION).tar.gz \
		blueonyx-phpMyAdmin-$(PMA_VERSION)
	@sed -e 's/XXX_VERSION_XXX/$(PMA_VERSION)/g' \
	     -e 's/XXX_RELEASE_XXX/$(RELEASE)/g' \
	     $(SPEC_IN) > $(RPM_TOPDIR)/SPECS/blueonyx-phpMyAdmin.spec
	@rpmbuild -ba $(RPM_TOPDIR)/SPECS/blueonyx-phpMyAdmin.spec
	@find $(RPM_TOPDIR)/RPMS -name 'blueonyx-phpMyAdmin-$(PMA_VERSION)-$(RELEASE)*.rpm' \
		-exec cp -a {} $(OUT_RPMS)/ \;
	@find $(RPM_TOPDIR)/SRPMS -name 'blueonyx-phpMyAdmin-$(PMA_VERSION)-$(RELEASE)*.rpm' \
		-exec cp -a {} $(OUT_SRPMS)/ \; 2>/dev/null || true
	@echo "RPMs in $(OUT_RPMS):"
	@ls -la $(OUT_RPMS)/

rpm: package

clean:
	rm -rf $(BUILD_ROOT) $(OUT_RPMS) $(OUT_SRPMS)

distclean-cache: clean
	rm -rf $(TARBALL_CACHE)
