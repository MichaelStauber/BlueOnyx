#!/bin/sh

# Default Mailbox format for BlueOnyx: MBOX

if [ -f /etc/sysconfig/bxmbox ];then
    # Default Mailbox format is already set. Do nothing and exit.
    exit
fi

if [ ! -f /etc/sysconfig/bxmbox ];then
    echo "MAILBOX=MBOX" > /etc/sysconfig/bxmbox
fi

if [ ! -f /etc/procmailrc ];then
    echo 'ORGMAIL=$HOME/mbox' > /etc/procmailrc
    echo 'DEFAULT=$ORGMAIL' >> /etc/procmailrc
fi
