# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=time

VERSION=1.3.2
RELEASE=4

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=yes

# NTP: Not available as SRPM
# ntpsec: Doesn't build yet due to OpenSSL-3.0.0 issues
# Disabled requirement for now:
#REQUIRES=ntp

# New require for 5211R: chrony
REQUIRES=chrony

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
