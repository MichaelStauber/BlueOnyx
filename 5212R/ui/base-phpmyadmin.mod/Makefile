# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=phpmyadmin

VERSION=2.3.0
RELEASE=1

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=no

# blueonyx-phpMyAdmin is built from src/blueonyx-phpMyAdmin (make rpm there)
REQUIRES = blueonyx-phpMyAdmin

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk

.PHONY: pma-rpm pma-clean

# Build/install helper for the upstream+SSO package
pma-rpm:
	$(MAKE) -C src/blueonyx-phpMyAdmin rpm

pma-clean:
	$(MAKE) -C src/blueonyx-phpMyAdmin clean
