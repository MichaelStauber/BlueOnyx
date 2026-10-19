#!/bin/bash

if [ -f /etc/resolvconf/resolv.conf.d/base ];then
     cp /etc/resolvconf/resolv.conf.d/base /etc/resolv.conf
fi

# Post-Install clean up of /etc/issue: one writer only. bx_update_issue.sh
# carries the address and the factory credentials, and skips the rewrite
# when the bytes are unchanged.
if [ -x /usr/sausalito/sbin/bx_update_issue.sh ]; then
    /usr/sausalito/sbin/bx_update_issue.sh || :
fi

# Post-Install clean up of /root/.bashrc:
HAVEBASHRC=$(cat /root/.bashrc|grep "network_settings.sh"|wc -l)
if [ "$HAVEBASHRC" -gt "0" ];then
    /bin/echo "# Source global definitions" > /root/.bashrc
    /bin/echo "if [ -f /etc/bashrc ]; then" >> /root/.bashrc
    /bin/echo "        . /etc/bashrc" >> /root/.bashrc
    /bin/echo "fi" >> /root/.bashrc
fi

exit
