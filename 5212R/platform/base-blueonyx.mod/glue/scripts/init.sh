#!/usr/bin/sh

# Externalized base-blueonyx-glue POST-INSTALL script:

# ---------------------------------------------------------------------------
# /etc/machine-id  (NewLinQ serialNumber / license identity)
#
# Intent: every *new* install must get a unique machine-id (firstboot /
# missing id). It must NOT be regenerated on routine package updates:
# serialNumber is derived from machine-id, and rotating it breaks NewLinQ
# association and can leave surplus rescue initramfs images in /boot.
# Never "rm -f /etc/machine-id" here. Admin-driven rotation stays manual.
# ---------------------------------------------------------------------------
if [ ! -s /etc/machine-id ]; then
  /usr/bin/systemd-machine-id-setup &>/dev/null || :
fi

# ---------------------------------------------------------------------------
# Surplus rescue kernels in /boot
#
# Earlier glue updates regenerated machine-id on every %post. Each new id
# caused a later kernel update to drop another vmlinuz/initramfs-0-rescue-*
# without removing the old ones, filling small /boot partitions.
#
# One-shot cleanup: only when more than one rescue kernel is present.
# Prefer the rescue image matching the current machine-id; otherwise keep
# the newest by mtime. Rebuild grub.cfg only if we actually deleted files.
# No-op on hosts with 0 or 1 rescue image (normal steady state).
# ---------------------------------------------------------------------------
if [ -d /boot ]; then
  _bx_rescue_count=0
  for _bx_f in /boot/vmlinuz-0-rescue-*; do
    [ -e "$_bx_f" ] || continue
    _bx_rescue_count=$((_bx_rescue_count + 1))
  done
  if [ "$_bx_rescue_count" -gt 1 ]; then
    _bx_keep=""
    if [ -s /etc/machine-id ]; then
      _bx_mid=$(tr -d ' \n\r\t' < /etc/machine-id)
      if [ -n "$_bx_mid" ] && [ -f "/boot/vmlinuz-0-rescue-$_bx_mid" ]; then
        _bx_keep="$_bx_mid"
      fi
    fi
    if [ -z "$_bx_keep" ]; then
      # newest by mtime
      _bx_keep=$(ls -1t /boot/vmlinuz-0-rescue-* 2>/dev/null | head -1 | sed 's#.*/vmlinuz-0-rescue-##')
    fi
    _bx_cleaned=0
    if [ -n "$_bx_keep" ]; then
      for _bx_f in /boot/vmlinuz-0-rescue-*; do
        [ -e "$_bx_f" ] || continue
        _bx_id=$(echo "$_bx_f" | sed 's#.*/vmlinuz-0-rescue-##')
        if [ "$_bx_id" != "$_bx_keep" ]; then
          rm -f "/boot/vmlinuz-0-rescue-$_bx_id" \
                "/boot/initramfs-0-rescue-$_bx_id.img" \
                "/boot/.vmlinuz-0-rescue-$_bx_id.hmac" \
                "/boot/System.map-0-rescue-$_bx_id" \
                "/boot/initrd-0-rescue-$_bx_id.img" 2>/dev/null || :
          _bx_cleaned=1
        fi
      done
    fi
    if [ "$_bx_cleaned" = "1" ] && [ -x /usr/sbin/grub2-mkconfig ] && [ -d /boot/grub2 ]; then
      # Always write /boot/grub2/grub.cfg (also on EFI); ESP grub.cfg is a stub.
      /usr/sbin/grub2-mkconfig -o /boot/grub2/grub.cfg >/dev/null 2>&1 || :
    fi
  fi
  unset _bx_rescue_count _bx_f _bx_keep _bx_mid _bx_id _bx_cleaned 2>/dev/null || :
fi

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
