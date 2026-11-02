# phpsysinfo - download upstream phpSysInfo, BlueOnyx cookie gate, RPM
#
# Usage (build host):
#   make rpm
#   make clean
#
# Tarball cache: /tmp/phpsysinfo-src/ (survives clean, not in SVN)
# Install path:  /usr/sausalito/ui/web/base/phpsysinfo/.phpsysinfo
#
# GUI sets cookie sysinfoAccess; skel injects check into index.php + js.php.
# base-phpsysinfo.mod Requires: phpsysinfo

TOP := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
BUILD_ROOT := $(TOP)/build
STAGE := $(BUILD_ROOT)/dest
OUT_RPMS := $(TOP)/as_rpms
OUT_SRPMS := $(TOP)/as_srpms

TARBALL_CACHE ?= /tmp/phpsysinfo-src
RELEASE ?= 1

RESOLVE := $(TOP)/scripts/resolve-psi-version.sh
SKEL := $(TOP)/skel
SPEC_IN := $(TOP)/templates/phpsysinfo.spec.in
COOKIE_INC := $(SKEL)/index-cookie-auth.php.inc

# Historic install layout (hidden dir under GUI web root)
PSI_WEB := /usr/sausalito/ui/web/base/phpsysinfo/.phpsysinfo
# Parent for empty marker path if needed
PSI_PARENT := /usr/sausalito/ui/web/base/phpsysinfo

RPM_TOPDIR := $(shell rpm --eval '%{_topdir}' 2>/dev/null || echo $(HOME)/rpmbuild)

# Optional BX ini from module glue (preferred over bare upstream .new)
MOD_GLUE_INI := $(TOP)/../../glue/phpsysinfo/phpsysinfo.ini.org

.PHONY: all help rpm install clean distclean-cache download unpack stage package

all: rpm
install: rpm

help:
	@echo "Targets: make rpm | clean | distclean-cache"
	@echo "Cache:   $(TARBALL_CACHE)"
	@echo "Auth:    cookie sysinfoAccess on index.php + js.php"

define RESOLVE_VARS
$(eval _R := $(shell chmod +x $(RESOLVE) >/dev/null 2>&1; $(RESOLVE)))
$(eval PSI_VERSION := $(word 1,$(_R)))
$(eval PSI_URL := $(word 2,$(_R)))
$(eval PSI_SHA := $(word 3,$(_R)))
$(eval TARBALL := $(TARBALL_CACHE)/phpsysinfo-v$(PSI_VERSION).tar.gz)
$(eval SRC_DIR := $(BUILD_ROOT)/phpsysinfo-$(PSI_VERSION))
endef

download:
	$(call RESOLVE_VARS)
	@echo "phpSysInfo $(PSI_VERSION)"
	@mkdir -p $(TARBALL_CACHE)
	@if [ -f "$(TARBALL)" ]; then \
		echo "Reusing $(TARBALL)"; \
	else \
		echo "Downloading $(PSI_URL)"; \
		curl -fL --retry 3 -o "$(TARBALL).partial" "$(PSI_URL)"; \
		mv "$(TARBALL).partial" "$(TARBALL)"; \
	fi
	@if [ -n "$(PSI_SHA)" ]; then \
		echo "$(PSI_SHA)  $(TARBALL)" | sha256sum -c - || \
		  (echo "SHA256 mismatch" >&2; exit 1); \
	fi

unpack: download
	$(call RESOLVE_VARS)
	@rm -rf "$(BUILD_ROOT)/phpsysinfo-"*
	@mkdir -p $(BUILD_ROOT)
	@tar xzf "$(TARBALL)" -C $(BUILD_ROOT)
	@# GitHub archive is phpsysinfo-VERSION
	@if [ ! -d "$(SRC_DIR)" ]; then \
		alt=$$(ls -d $(BUILD_ROOT)/phpsysinfo-* 2>/dev/null | head -1); \
		test -n "$$alt" && test -d "$$alt" || (echo "missing unpack dir" >&2; exit 1); \
		mv "$$alt" "$(SRC_DIR)"; \
	fi
	@test -f "$(SRC_DIR)/index.php" || (echo "missing index.php" >&2; exit 1)
	@echo "Unpacked $(SRC_DIR)"

stage: unpack
	$(call RESOLVE_VARS)
	@rm -rf $(STAGE)
	@mkdir -p $(STAGE)$(PSI_WEB)
	@# Upstream tree into historic .phpsysinfo path
	@cp -a "$(SRC_DIR)/." $(STAGE)$(PSI_WEB)/
	@rm -rf $(STAGE)$(PSI_WEB)/.git $(STAGE)$(PSI_WEB)/.github 2>/dev/null || true
	@# Cookie gate (BlueOnyx GUI iframe)
	@test -f "$(COOKIE_INC)"
	@chmod +x $(TOP)/scripts/inject-cookie-auth.sh
	@$(TOP)/scripts/inject-cookie-auth.sh "$(STAGE)$(PSI_WEB)" "$(COOKIE_INC)"
	@# Config: BX glue ini as .org (constructor/installfixer + %post)
	@if [ -f "$(MOD_GLUE_INI)" ]; then \
		install -m 0644 "$(MOD_GLUE_INI)" $(STAGE)$(PSI_WEB)/phpsysinfo.ini.org; \
		install -m 0644 "$(MOD_GLUE_INI)" $(STAGE)$(PSI_WEB)/phpsysinfo.ini; \
	elif [ -f $(STAGE)$(PSI_WEB)/phpsysinfo.ini.new ]; then \
		cp -a $(STAGE)$(PSI_WEB)/phpsysinfo.ini.new $(STAGE)$(PSI_WEB)/phpsysinfo.ini; \
	fi
	@echo "Staged under $(STAGE)"

package: stage
	$(call RESOLVE_VARS)
	@mkdir -p $(OUT_RPMS) $(OUT_SRPMS)
	@mkdir -p $(RPM_TOPDIR)/SOURCES $(RPM_TOPDIR)/SPECS $(RPM_TOPDIR)/BUILD \
		$(RPM_TOPDIR)/RPMS $(RPM_TOPDIR)/SRPMS
	@rm -rf $(BUILD_ROOT)/phpsysinfo-$(PSI_VERSION)-pkg
	@mkdir -p $(BUILD_ROOT)/phpsysinfo-$(PSI_VERSION)
	@# %setup -n phpsysinfo-VERSION expects that directory name
	@(cd $(STAGE) && tar cf - .) | (cd $(BUILD_ROOT)/phpsysinfo-$(PSI_VERSION) && tar xf -)
	@tar -C $(BUILD_ROOT) -czf $(RPM_TOPDIR)/SOURCES/phpsysinfo-$(PSI_VERSION).tar.gz \
		phpsysinfo-$(PSI_VERSION)
	@sed -e 's/XXX_VERSION_XXX/$(PSI_VERSION)/g' \
	     -e 's/XXX_RELEASE_XXX/$(RELEASE)/g' \
	     $(SPEC_IN) > $(RPM_TOPDIR)/SPECS/phpsysinfo.spec
	@rpmbuild -ba $(RPM_TOPDIR)/SPECS/phpsysinfo.spec
	@find $(RPM_TOPDIR)/RPMS -name 'phpsysinfo-$(PSI_VERSION)-$(RELEASE)*.rpm' \
		-exec cp -a {} $(OUT_RPMS)/ \;
	@find $(RPM_TOPDIR)/SRPMS -name 'phpsysinfo-$(PSI_VERSION)-$(RELEASE)*.rpm' \
		-exec cp -a {} $(OUT_SRPMS)/ \; 2>/dev/null || true
	@echo "RPMs in $(OUT_RPMS):"
	@ls -la $(OUT_RPMS)/

rpm: package

clean:
	rm -rf $(BUILD_ROOT) $(OUT_RPMS) $(OUT_SRPMS)

distclean-cache: clean
	rm -rf $(TARBALL_CACHE)
