# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx and Bluapp AB'
SERVICE=email

VERSION=2.2.8
RELEASE=3

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=new
BUILDGLUE=yes
BUILDLOCALE=yes
BUILDSRC=yes

#OLDREQUIRES = procmail sendmail cyrus-sasl-plain dovecot >= 2.2.15 poprelayd base-blueonyx-glue perl-MIME-Lite >= 3.023-2 perl-Email-Date-Format >= 1.002-1 base-email-am blueonyx-postfix hostname blueonyx-z-push-common blueonyx-z-push blueonyx-z-push-config-apache blueonyx-z-push-ipc-sharedmemory blueonyx-z-push-backend-imap php-awl
REQUIRES = procmail sendmail cyrus-sasl-plain dovecot >= 2.2.15 poprelayd base-blueonyx-glue perl-MIME-Lite >= 3.023-2 perl-Email-Date-Format >= 1.002-1 base-email-am blueonyx-postfix hostname php-awl opendkim opendkim-tools sendmail-milter

#########
# some useful defines
INSTALL=install
INSTALL_BIN=$(INSTALL) -m 755
INSTALL_OTH=$(INSTALL) -m 644
TMPDIR=/tmp

include /usr/sausalito/devel/module.mk
