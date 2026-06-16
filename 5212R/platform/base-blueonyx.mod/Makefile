# specify the following variables:
VENDOR=base

# if the VENDOR field is an alias, this should be the real name.
# otherwise, set it to VENDOR.
VENDORNAME='Project BlueOnyx'
SERVICE=blueonyx

SUBVER=6
BUILD=$(shell date --rfc-3339=date | sed s/-//g)$(SUBVER)

VERSION=$(shell if [ `uname -m` = "x86_64" ]; then  echo -n "5212R"; else  echo -n "5212R"; fi)
RELEASE=8.$(BUILD)

# add a buildarch if desired
BUILDARCH=noarch

# locale exclude pattern
#XLOCALEPAT=

BUILDUI=no
BUILDGLUE=yes
BUILDLOCALE=no
BUILDSRC=no

REQUIRES=httpd php iptables base-alpine-capstone swatch cyrus-sasl sendmail-cf m4 sendmail cronie crontabs perl perl-Switch perl-CGI sed dialog sausalito-cce-server sausalito-cce-client quota xfsprogs net-tools selinux-policy which rsyslog logrotate zip patch unzip bzip2 elfutils rsync xml-common libtar diffutils jailkit blueonyx-postfix fuse-sshfs perl-String-ShortHostname
REQUIRES_GLUE=base-email-capstone base-time-capstone base-user-capstone at dovecot php base-admserv-capstone base-support-capstone base-network-capstone whois traceroute procps-ng pv blueonyx-postfix

# Requirements from EL8 that we don't yet have in EL9:
#  drpm /sbin/chkconfig <- naturally

include /usr/sausalito/devel/module.mk
