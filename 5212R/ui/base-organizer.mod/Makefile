# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Team BlueOnyx'
SERVICE=organizer

VERSION=1.1.0
RELEASE=8

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=yes

BUILD_VERSION := $(shell grep -E '5211R|5212R' /etc/build | wc -l)
ifeq ($(BUILD_VERSION),1)
# For 5211R and 5212R:
XREQUIRES=base-organizer-am python3-radicale3 radicale3-httpd radicale3-selinux radicale3 mod_authnz_external
else
# For 5210R:
XREQUIRES=base-organizer-am radicale3-httpd radicale3-selinux radicale3 python38-requests mod_authnz_external
endif

REQUIRES = $(XREQUIRES)

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
