# $Id: Makefile 259 2004-01-03 06:28:40Z shibuya $
#
# Makefile to install handlers.  Right now, it's a bit perl-specific,
# but it can be easily generalized.
#
# supported targets:
#		all -- does nothing
#		install -- installs everything
#		install_links -- creates links instead of copies of files (for dbging)
#		rpm -- rolls an rpm for this stuff

ifdef CCETOPDIR
include $(CCETOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

INSTALL = /usr/bin/install -m 0755 -o root 

SBIN_SCRIPTS = ddns-client.pl

install: install_sbin

install_sbin:
	@mkdir -p $(CCEDIR)/sbin
	cd sbin; for file in $(SBIN_SCRIPTS); do \
		$(INSTALL) $$file $(CCEDIR)/sbin; \
	done

