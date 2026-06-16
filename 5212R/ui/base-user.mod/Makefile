# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=user

VERSION=2.5.6
RELEASE=6

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=no

BUILD_VERSION := $(shell grep -E '5210R|5211R' /etc/build | wc -l)
ifeq ($(BUILD_VERSION),1)
# For 5210R and 5211R:
XREQUIRES=imapsync base-email-capstone sausalito-cce-server base-admserv-capstone sausalito-i18n sausalito-i18n base-blueonyx-capstone perl-Unix-ConfigFile base-organizer-capstone authconfig
else
# For 5212R:
XREQUIRES=imapsync base-email-capstone sausalito-cce-server base-admserv-capstone sausalito-i18n sausalito-i18n base-blueonyx-capstone perl-Unix-ConfigFile base-organizer-capstone authselect
endif

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
