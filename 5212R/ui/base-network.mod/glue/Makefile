ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

# include defines.mk here before checking to see which target make is doing
include $(CCETOPDIR)/devel/defines.mk

# check PREFIX, because if it is defined we are 'make rpm' and CCETOPDIR
# needs to be redefined appropriately
ifdef PREFIX
CCETOPDIR = $(PREFIX)/usr/sausalito
NMCFGDIR = $(PREFIX)/etc/systemd/system/NetworkManager.service.d
endif

all:

install:
	mkdir -p $(CCETOPDIR)/sbin/
	mkdir -p $(NMCFGDIR)
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/dhcp_enable.pl $(CCETOPDIR)/sbin/
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/get_nmcli_uuid.pl $(CCETOPDIR)/sbin/
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/network_apply_settings.pl $(CCETOPDIR)/sbin/
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/set_system_bridge.pl $(CCETOPDIR)/sbin/
	$(INSTALL) $(INSTALL_SCRIPTFLAGS) sbin/sync_hostname.pl $(CCETOPDIR)/sbin/
	$(INSTALL) $(INSTALL_HEADERFLAGS) sbin/nm_ignore-restarts.conf $(NMCFGDIR)/ignore-restarts.conf

rpm:
	
