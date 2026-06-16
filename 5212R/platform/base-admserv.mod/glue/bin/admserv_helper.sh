#!/bin/bash

# hack to make sure apache has locale information set
# i18n needs to be fixed
if [ -f /etc/sysconfig/i18n ]; then
        . /etc/sysconfig/i18n
fi

export LANG LC_ALL LINGUAS
export PHPRC="/etc/admserv"
export PERL5LIB="/usr/sausalito/perl"
