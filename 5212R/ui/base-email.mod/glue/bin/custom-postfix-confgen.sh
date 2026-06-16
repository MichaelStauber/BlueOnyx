#!/bin/sh
#
### Manual Postfix configuration overrides:
#
# This file will not be replaced via YUM updates.
#
# It allows you to specify your own 'postconf -e' commands to modify settings
# of Postfix after the BlueOnyx auto-configure is done with applying the GUI
# mandated settings.
#
# You can use it to change any and all Postfix settings to your liking. 
#
# So be careful what you do. If you break it, you can keep the pieces! :p

#
### Log that we have run:
#
logger "Running /usr/sausalito/bin/custom-postfix-confgen.sh"

#
### Example (uncommented it would reconfigure the 'smtpd_sender_restrictions'):
# 

# postconf -e 'smtpd_sender_restrictions = reject_unknown_sender_domain'

