# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=admserv

VERSION=2.4.1
RELEASE=6

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=no
BUILDGLUE=yes
BUILDLOCALE=no
BUILDSRC=no

REQUIRES=httpd sausalito-cce-client sausalito-cce-server sausalito-i18n php-mysqlnd valkey valkey-compat-redis
REQUIRES_GLUE=openssl httpd mod_ssl sausalito-cce-client sausalito-cce-server sausalito-i18n mod_perl php-bcmath php-cli php-common php-dba php-dbg php-devel php-enchant php-fpm php-gd php-gmp php-intl php-json php-ldap php-mbstring php-mysqlnd php-odbc php-pdo php-pear php-pecl-apcu php-pecl-apcu-devel php-pecl-zip php-pgsql php-process php-snmp php-soap php-xml python3-mod_wsgi admserv-php

# We need these RPMs as well, but the EL9 repo doesn't have them yet:
# php-embedded php-mcrypt php-recode php-xmlrpc php-imap

OBSOLETES=php-mysql

include /usr/sausalito/devel/module.mk
