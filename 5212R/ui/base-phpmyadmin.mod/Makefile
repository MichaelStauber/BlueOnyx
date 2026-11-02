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
# yes: module.mk src_rpms runs "make -C src rpm" → blueonyx-phpMyAdmin
BUILDSRC=yes

# GUI capstone Requires the app package produced under src/
REQUIRES = blueonyx-phpMyAdmin

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
