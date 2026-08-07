# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Team BlueOnyx'
SERVICE=ai

VERSION=1.0.4
RELEASE=1
MODULE_FULL_VERSION=$(VERSION)-$(RELEASE)
BO_PRODUCT=$(shell sed -n 's/.* for a \([0-9][0-9][0-9][0-9]R\).*/\1/p' /etc/build 2>/dev/null || echo -n "5210R")
PACKAGE_PRODUCT=$(BO_PRODUCT)

# add a buildarch if desired
BUILDARCH=noarch

# Requires:
REQUIRES = base-ai-knowledge base-ai-core base-ai-llama base-ai-model-SmolLM2 base-alpine-ci4-vendor

# locale exclude pattern
BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=yes

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

# Keep the module framework's normal build as the default even though the
# convenience dependency targets are declared before module.mk is included.
.DEFAULT_GOAL := all

# Canonical build-host/platform variables. These are exported so sub-builds can
# consume one shared platform view instead of each subpackage redetecting the
# host on its own.
EL_MAJOR := $(shell value=`rpm -E '%{?rhel}' 2>/dev/null`; if [ -n "$$value" ]; then echo "$$value"; else . /etc/os-release && echo "$${VERSION_ID%%.*}"; fi)
PYTHON_BIN = /usr/bin/python3
ifeq ($(EL_MAJOR),8)
PYTHON_PKG = python38
PYTHON_PIP_PKG = python38-pip
PYTHON_PIP_BOOTSTRAP = /home/ai/venv/bin/pip install -q --disable-pip-version-check 'pip==24.3.1'
PYTHON_UVICORN_VERSION = 0.33.0
PYTHON_TOKENIZERS_PIN = tokenizers==0.20.3 ; python_version < "3.9"
else
PYTHON_PKG = python3
PYTHON_PIP_PKG = python3-pip
PYTHON_PIP_BOOTSTRAP = true
PYTHON_UVICORN_VERSION = 0.34.0
PYTHON_TOKENIZERS_PIN = tokenizers==0.20.3 ; python_version < "3.9"
endif
GCC_TOOLSET_ROOT = /opt/rh/gcc-toolset-14/root/usr
ifeq ($(shell [ "$(EL_MAJOR)" -ge 10 ] 2>/dev/null && echo yes),yes)
LLAMA_TOOLSET_ROOT = /usr
LLAMA_BUILD_PACKAGES = gcc gcc-c++ binutils libstdc++-static
else
LLAMA_TOOLSET_ROOT = $(GCC_TOOLSET_ROOT)
LLAMA_BUILD_PACKAGES = \
	gcc-toolset-14-gcc \
	gcc-toolset-14-gcc-c++ \
	gcc-toolset-14-binutils \
	gcc-toolset-14-runtime \
	gcc-toolset-14-libstdc++-devel
endif
LLAMA_TOOLSET_CC = $(LLAMA_TOOLSET_ROOT)/bin/gcc
LLAMA_TOOLSET_CXX = $(LLAMA_TOOLSET_ROOT)/bin/g++

export BO_PRODUCT PACKAGE_PRODUCT EL_MAJOR
export PYTHON_BIN PYTHON_PKG PYTHON_PIP_PKG PYTHON_PIP_BOOTSTRAP PYTHON_UVICORN_VERSION PYTHON_TOKENIZERS_PIN
export GCC_TOOLSET_ROOT LLAMA_TOOLSET_ROOT LLAMA_TOOLSET_CC LLAMA_TOOLSET_CXX LLAMA_BUILD_PACKAGES

.PHONY: check-build-deps bootstrap-build-deps sync-package-metadata restore-package-metadata clean-python-cache

mod_packing_list: sync-package-metadata

sync-package-metadata:
	@/usr/bin/perl -0pi -e 's/^Version:\s+.*/Version:               $(MODULE_FULL_VERSION)/m; s/^Product:\s+.*/Product:               $(PACKAGE_PRODUCT)/m' pkg_packing_list
	@/usr/bin/perl -0pi -e 's/(msgid "version"\nmsgstr ")[^"]*(")/$${1}$(MODULE_FULL_VERSION)$${2}/' pkginfo/locale/en_US/ai_assistant.po

clean: restore-package-metadata
clean: clean-python-cache

restore-package-metadata:
	@/usr/bin/perl -0pi -e 's/^Version:\s+.*/Version:               XXX_MODULE_VERSION_XXX/m; s/^Product:\s+.*/Product:               XXX_PRODUCT_VERSION_XXX/m' pkg_packing_list
	@/usr/bin/perl -0pi -e 's/(msgid "version"\nmsgstr ")[^"]*(")/$${1}XXX_MODULE_VERSION_XXX$${2}/' pkginfo/locale/en_US/ai_assistant.po

clean-python-cache:
	@find . -type d -name '__pycache__' -prune -exec rm -rf {} +
	@find . -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

check-build-deps:
	@missing=0; \
	for package in $(LLAMA_BUILD_PACKAGES); do \
		if ! rpm -q "$$package" >/dev/null 2>&1; then \
			echo "Missing llama build dependency: $$package"; \
			missing=1; \
		fi; \
	done; \
	if [ "$$missing" -ne 0 ]; then \
		echo "Run 'make bootstrap-build-deps' as root to install them."; \
		exit 1; \
	fi; \
	echo "All llama build dependencies are installed."

bootstrap-build-deps:
	dnf install -y $(LLAMA_BUILD_PACKAGES)

include /usr/sausalito/devel/module.mk
