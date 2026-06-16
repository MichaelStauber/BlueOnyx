#!/bin/bash

if [ -f /etc/resolvconf/resolv.conf.d/base ];then
     cp /etc/resolvconf/resolv.conf.d/base /etc/resolv.conf
fi

# Post-Install clean up of /etc/issue:
HAVEISSUE=$(cat /etc/issue|grep "Welcome to your new server"|wc -l)
if [ "$HAVEISSUE" -gt "0" ];then
	/bin/echo "BlueOnyx 5210R on \S" > /etc/issue
	/bin/echo "Kernel \r on an \m" >> /etc/issue
	/bin/echo "" >> /etc/issue
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
