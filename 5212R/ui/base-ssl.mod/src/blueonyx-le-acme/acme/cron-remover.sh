#!/bin/bash

CRONTHERE=$(crontab -l|grep /usr/sausalito/acme/acme.sh|wc -l)
if [ "$CRONTHERE" -eq "1" ];then
    if [ -f /root/crontabs.txt ];then
        rm -f /root/crontabs.txt
    fi
    crontab -l|grep -v /usr/sausalito/acme/acme.sh > /root/crontabs.txt
    crontab /root/crontabs.txt
fi
