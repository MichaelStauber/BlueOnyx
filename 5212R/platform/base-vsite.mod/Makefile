# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=vsite

VERSION=4.9
RELEASE=3

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=yes

REQUIRES= base-vsite-am base-email-capstone sausalito-i18n sausalito-cce-server base-admserv-capstone perl-handler-utils mod_suphp mod_authnz_external icu libmcrypt libmhash libssh2 aspell freetds freetds-libs libdb4 perl-LWP-Protocol-https e2fsprogs pcre2 perl-Config-INI ImageMagick solarspeed-ioncube oniguruma libwebp libc-client solarspeed-curl solarspeed-libzip libsodium libargon2 libmemcached-awesome libmemcached-awesome-tools solarspeed-libiconv blueonyx-openssl blueonyx-openssl-devel blueonyx-openssl-doc perl-Config-General easy-migrate liblqr-1 lcms2 libraqm perl-Config-INI solarspeed-libxml

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
