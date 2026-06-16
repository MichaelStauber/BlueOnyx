#!/usr/bin/sh

# Externalized base-blueonyx-glue POST-INSTALL script:

# Make sure that this install has a unique /etc/machine-id:
rm -f /etc/machine-id
/usr/bin/systemd-machine-id-setup &>/dev/null || :

# update procmailrc
if [ ! -f /etc/procmailrc ] || [ ! -f /etc/sysconfig/bxmbox ]; then
  /usr/sausalito/scripts/initProcmail.sh &>/dev/null || :
fi

if [ -f /etc/sysconfig/saslauthd ]; then
  /usr/bin/perl -pi -e 's|^MECH=shadow|MECH=pam|g' /etc/sysconfig/saslauthd
else
  echo "# Directory in which to place saslauthd's listening socket, pid file, and so" > /etc/sysconfig/saslauthd
  echo '# on.  This directory must already exist.' >> /etc/sysconfig/saslauthd
  echo 'SOCKETDIR=/run/saslauthd' >> /etc/sysconfig/saslauthd
  echo '' >> /etc/sysconfig/saslauthd
  echo '# Mechanism to use when checking passwords.  Run "saslauthd -v" to get a list' >> /etc/sysconfig/saslauthd
  echo '# of which mechanism your installation was compiled with the ablity to use.' >> /etc/sysconfig/saslauthd
  echo 'MECH=shadow' >> /etc/sysconfig/saslauthd
  echo '' >> /etc/sysconfig/saslauthd
  echo '# Additional flags to pass to saslauthd on the command line.  See saslauthd(8)' >> /etc/sysconfig/saslauthd
  echo '# for the list of accepted flags.' >> /etc/sysconfig/saslauthd
  echo 'FLAGS=' >> /etc/sysconfig/saslauthd
  echo '' >> /etc/sysconfig/saslauthd
fi

if [ -f /usr/share/ssl/certs/sendmail-2048.dh ];then
  chmod 0600 /usr/share/ssl/certs/sendmail-2048.dh
fi

if [ -f /var/lib/dovecot/ssl-parameters.dat ];then
  chmod 0644 /var/lib/dovecot/ssl-parameters.dat
fi

AUTH=$(cat /etc/sysconfig/blueonyx|grep pwdb|wc -l)
if [ "$AUTH" -gt "0" ];then
  ln -sf /usr/sausalito/scripts/pwdb2shadow.sh /usr/sausalito/runonce/pwdb2shadow.sh
  /usr/sausalito/scripts/pwdb2shadow.sh &>/dev/null || :
else 
  if [ -f /etc/sysconfig/saslauthd ];then
    /bin/sed -i -e 's@MECH=pam@MECH=shadow@' /etc/sysconfig/saslauthd
  fi
  if [ -f /etc/nsswitch.conf ];then
    /bin/sed -i -e "s@'db files'@files@" /etc/nsswitch.conf
  fi
fi

# Fix php.ini:
if [ -f /etc/php.ini ]; then
    /usr/bin/perl -pi -e 's|^error_reporting  =  E_ALL|error_reporting  =  E_ALL & ~E_NOTICE|g' /etc/php.ini
    /usr/bin/perl -pi -e 's|^short_open_tag = Off|short_open_tag = On|g' /etc/php.ini
fi

# Fix /etc/logwatch/conf/ignore.conf if we're running in a VPS:
if [ -f /proc/user_beancounters ]; then
    if [ -f /etc/logwatch/conf/ignore.conf ]; then
        echo "###### REGULAR EXPRESSIONS IN THIS FILE WILL BE TRIMMED FROM REPORT OUTPUT #####" > /etc/logwatch/conf/ignore.conf
        echo "ERROR: failed to open PAM security session" >> /etc/logwatch/conf/ignore.conf
        echo "ERROR: cannot set security context" >> /etc/logwatch/conf/ignore.conf
        echo "crond.*: System error" >> /etc/logwatch/conf/ignore.conf
    fi
fi

# Fix Dovecot v2.0 configs:
if [ -f /etc/dovecot/conf.d/10-auth.conf ]; then
  perl -pi -e 's|^#disable_plaintext_auth = (.*)|disable_plaintext_auth = no|g' /etc/dovecot/conf.d/10-auth.conf
fi
if [ -f /etc/dovecot/conf.d/10-mail.conf ]; then
  perl -pi -e 's|^#mail_location =(.*)|mail_location = mbox:~/mail/:INBOX=mbox|g' /etc/dovecot/conf.d/10-mail.conf
fi

exit