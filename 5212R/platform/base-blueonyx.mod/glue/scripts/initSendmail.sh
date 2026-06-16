#!/bin/sh

CONFDIR='/usr/sausalito/configs/sendmail'
if [ ! -f /etc/mail/aliases ]; then
  if [ -f /etc/mail/aliases.rpmsave ]; then
    mv /etc/mail/aliases.rpmsave /etc/mail/aliases
  fi

  # Move misplaced alias databases - if present:
  if [ -f /etc/aliases ]; then
    /bin/cp /etc/aliases /etc/mail/aliases
    /bin/cp /etc/aliases.db /etc/mail/aliases.db
  fi
fi

# Handle case where we have neither and a stock Sendmail config:
CFGDONE=$(cat /etc/mail/sendmail.mc|grep 'setup for BlueOnyx'|wc -l)
if [ "$CFGDONE" -eq '0' ];then
    echo "Need to replace sendmail.mc"
    if [ -f $CONFDIR/sendmail.mc ];then
        cp -p $CONFDIR/sendmail.mc /etc/mail/sendmail.mc
        echo "Copied $CONFDIR/sendmail.mc over to /etc/mail/sendmail.mc"
    else 
        echo "Did not find a good sendmail.mc to copy!"
    fi
else 
    echo "No need to replace sendmail.mc"
fi

cp -p $CONFDIR/popauth.m4 /usr/share/sendmail-cf/hack/popauth.m4

if [ -f /usr/sausalito/constructor/base/email/syncEmailService.pl ];then
    /usr/sausalito/constructor/base/email/syncEmailService.pl
fi

if [ -f /usr/sausalito/constructor/solarspeed/av_spam/aa_initial_inst.pl ];then
    /usr/sausalito/constructor/solarspeed/av_spam/aa_initial_inst.pl
fi

m4 /usr/share/sendmail-cf/m4/cf.m4 /etc/mail/sendmail.mc > /etc/mail/sendmail.cf

touch /etc/mail/virthosts
chmod 0600 /etc/mail/virthosts
chown root:root /etc/mail/virthosts

touch /var/log/mail/statistics

# remove unwanted aliases that keep users from using these as mail-adresses:
_UWALIASES='support marketing news sales webmaster'
for _UWALIAS in $_UWALIASES; do
    /bin/sed -i -e "/^${_UWALIAS}:/d" /etc/mail/aliases
done

# route nobody to /dev/null so that admin does not receive a copy of every ml msg
/bin/sed -i -e s"/^nobody:.*$/nobody:\t\t\/dev\/null/" /etc/mail/aliases

# Redirect 'root' emails to 'admin':
grep '^root:' /etc/mail/aliases > /dev/null 2>&1
if [ $? = 1 ]; then
  echo 'root:   admin' >> /etc/mail/aliases
fi
/usr/bin/newaliases

