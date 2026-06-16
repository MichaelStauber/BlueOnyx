#!/usr/bin/sh

# Disable some services that do not need to be on
/bin/echo "Disabling unneeded services ..."
services="smartd autofs irqbalance netfs microcode_ctl mdchk kudzu iscsid iscsi sysstat ip6tables auditd kdump lldpad fcoe atd messagebus lldpad fcoe cups netfs portreserve atd"
for service in $services; do
  systemctl disable $service.service &>/dev/null || :
done

# Remount /tmp to be non-executable!
if [ -f /etc/fstab ];then
  /bin/echo "Remount /tmp to be non-executable"
  /usr/bin/perl -pi -e "if (/\/tmp/) { s/defaults/noexec,nosuid,rw/ }" /etc/fstab
  /bin/mount -o remount /tmp >/dev/null 2>&1
fi

# Add "httpd" to /etc/passwd & /etc/shadow & /etc/group for backwards compatibility
/bin/cat /etc/passwd | /bin/grep apache | /bin/sed -e "s/apache/httpd/" >> /etc/passwd
/bin/cat /etc/shadow | /bin/grep apache | /bin/sed -e "s/apache/httpd/" >> /etc/shadow
/bin/cat /etc/group | /bin/grep apache | /bin/sed -e "s/apache/httpd/" >> /etc/group

# Fix all "tmp" directories to point to /tmp
/bin/rm -Rf /var/tmp >/dev/null 2>&1
/bin/rm -Rf /home/tmp >/dev/null 2>&1
/bin/ln -s /tmp /var/tmp >/dev/null 2>&1
/bin/ln -s /tmp /home/tmp >/dev/null 2>&1

/bin/echo "Fixing /etc/profile and /root/.bashrc"
# Add some aliases, and fix the ones on the box
HAVEALIASES=$(cat /etc/profile|grep "pico"|wc -l)
if [ "$HAVEALIASES" -eq "0" ];then
  /bin/echo "alias rm=\"rm -f\"" >> /etc/profile
  /bin/echo "alias lsd=\"ls -ld */\"" >> /etc/profile
  /bin/echo "alias pico=\"pico -w\"" >> /etc/profile
fi

# Rehash CCEd:
/usr/sausalito/sbin/cced.init rehash &>/dev/null || :

if [ -d /usr/sausalito/codb/objects ];then
  PRESETUPDONE=$(find /usr/sausalito/codb/objects/ -name .isLicenseAccepted| wc -l)
  if [ "$PRESETUPDONE" -eq "1" ];then
    SETUPDONE=$(find /usr/sausalito/codb/objects/ -name .isLicenseAccepted|xargs cat )
    if [ "$SETUPDONE" -eq "0" ];then
      echo "Initial setup has not been finished yet! Editing /root/.bashrc"

      # Not in OpenVZ CTs!
      if [ ! -e /proc/user_beancounters ] && [ ! -e /dev/incus/sock ];then
        HAVEBASHRC=$(cat /root/.bashrc|grep "network_settings.sh"|wc -l)
        if [ "$HAVEBASHRC" -eq "0" ];then
          /bin/echo "# Added by initServices.sh" > /root/.bashrc
          /bin/echo "# Source global definitions" >> /root/.bashrc
          /bin/echo "if [ -f /etc/bashrc ]; then" >> /root/.bashrc
          /bin/echo "        . /etc/bashrc" >> /root/.bashrc
          /bin/echo "fi" >> /root/.bashrc

          # Not in Incus CTs!
          if [ ! -e /dev/incus/sock ]; then
            /bin/echo "/bin/echo \"\"" >> /root/.bashrc
            /bin/echo "/bin/echo \"To change your network settings from the command line, run\"" >> /root/.bashrc
            /bin/echo "/bin/echo \"the command /root/network_settings.sh\"" >> /root/.bashrc
            /bin/echo "/bin/echo \"\"" >> /root/.bashrc
            /bin/echo "/bin/echo \"To remove this notice, edit /root/.bashrc\"" >> /root/.bashrc
            /bin/echo "/bin/echo \"\"" >> /root/.bashrc
            /bin/echo "/root/network_settings.sh" >> /root/.bashrc
          fi
        fi
      fi
    else
      echo "Initial setup has already been finished. Not editing /root/.bashrc"
    fi
  fi
else
  # The CODB setting .isLicenseAccepted doesn't exist (yet). So this is a fresh install and we
  # are editing /root/.bashrc no matter what:
  echo "Initial setup has not been finished yet! Editing /root/.bashrc"

  # Not in OpenVZ CTs!
  if [ ! -e /proc/user_beancounters ] && [ ! -e /dev/incus/sock ];then
    HAVEBASHRC=$(cat /root/.bashrc|grep "network_settings.sh"|wc -l)
    if [ "$HAVEBASHRC" -eq "0" ];then
      /bin/echo "# Added by initServices.sh" > /root/.bashrc
      /bin/echo "# Source global definitions" >> /root/.bashrc
      /bin/echo "if [ -f /etc/bashrc ]; then" >> /root/.bashrc
      /bin/echo "        . /etc/bashrc" >> /root/.bashrc
      /bin/echo "fi" >> /root/.bashrc

      # Not in Incus CTs!
      if [ ! -e /dev/incus/sock ]; then
        # Tell people how to reconfigure network via the CLI
        /bin/echo "/bin/echo \"\"" >> /root/.bashrc
        /bin/echo "/bin/echo \"To change your network settings from the command line, run\"" >> /root/.bashrc
        /bin/echo "/bin/echo \"the command /root/network_settings.sh\"" >> /root/.bashrc
        /bin/echo "/bin/echo \"\"" >> /root/.bashrc
        /bin/echo "/bin/echo \"To remove this notice, edit /root/.bashrc\"" >> /root/.bashrc
        /bin/echo "/bin/echo \"\"" >> /root/.bashrc
        /bin/echo "/root/network_settings.sh" >> /root/.bashrc
      fi
    fi
  fi
fi

# Change MAIL environment variable
/bin/echo "Setting Mailbox environment in /etc/profile"
/usr/bin/perl -pi -e 's/MAIL=.*/MAIL=\"\$HOME\/mbox\"/' /etc/profile

/bin/echo "Applying various post-install fixes"
# Fix a small networking problem.  If you don't enable this, you get route for 169.254/16 network
echo "NOZEROCONF=yes" >> /etc/sysconfig/network

# Turn off IPV6:
/bin/echo "alias net-pf-10 off" >> /etc/modprobe.d/net-pf-10.conf

# Allow logins via the serial interface for root
/bin/echo "ttyS0" >> /etc/securetty

# Make new pkg install directory, if it doesn't exist!
if [ ! -e /home/.pkg_install_tmp ]; then mkdir -p /home/.pkg_install_tmp; fi

# Make /usr/sausalito/yumcce, if it doesn't exist!
if [ ! -e /usr/sausalito/yumcce ]; then mkdir -p /usr/sausalito/yumcce; fi

# Allow locate to run:
if [ -f /etc/updatedb.conf ]; then
  /usr/bin/perl -pi -e "s/DAILY_UPDATE=no/DAILY_UPDATE=yes/" /etc/updatedb.conf
fi

# Lets make the crontab file more like the Cobalt use to be!
/bin/mkdir -p /etc/cron.half-hourly
/bin/mkdir -p /etc/cron.quarter-hourly
/bin/mkdir -p /etc/cron.quarter-daily
echo "04,34 * * * * root run-parts /etc/cron.half-hourly" >> /etc/crontab
echo "03,18,33,48 * * * * root run-parts /etc/cron.quarter-hourly" >> /etc/crontab
echo "05 0,6,12,18 * * * root run-parts /etc/cron.quarter-daily" >> /etc/crontab

## Fix /etc/ld.so.conf:
LIB=/usr/sausalito/lib
/bin/cp /etc/ld.so.conf /etc/ld.so.conf.bak
/bin/grep "^$LIB[[:space:]]*$" /etc/ld.so.conf > /dev/null || /bin/echo $LIB >> /etc/ld.so.conf
/sbin/ldconfig

## Enable all needed services:
/bin/echo "Enabling all needed services ..."
onservices="cced.init httpd admserv admserv-init.service admserv-php-fpm.service xinetd mariadb named-chroot network saslauthd"
for service in $services; do
  systemctl enable $service.service > /dev/null 2>&1
done

# Change MySQL database store to /home if it is a separate partition:
/bin/echo "Setting up MariaDB ..."
chmod 755 /var/lib/mysql
HAVEHOME=$(mount|grep "/home"|wc -l)
if [ "$HAVEHOME" -eq "1" ];then
  systemctl stop mariadb.service &>/dev/null || :
  /bin/rm -Rf /var/lib/mysql >/dev/null 2>&1
  /bin/mkdir -p /home/mysql
  /bin/ln -s /home/mysql/ /var/lib/mysql
  /usr/bin/mysql_install_db >/dev/null 2>&1
  /bin/chown mysql:mysql -Rf /home/mysql
  chmod 755 /home/mysql
fi

# Fix MariaDB logfile:
touch /var/log/mariadb/mariadb.log
chown mysql:mysql /var/log/mariadb/mariadb.log

/bin/echo "Applying network related configurations ..."
GATEWAY=`ip r | grep default | cut -d ' ' -f 3`
GATEWAYPRESENT=`ip r | grep default | cut -d ' ' -f 3|wc -l`

if [ "$GATEWAYPRESENT" == "1" ]; then
  # IF we have a gateway already, then we stored it:
  echo "GATEWAY=$GATEWAY" >> /etc/sysconfig/network
fi

### Directory permissions/ownership fix for ISO install:

if [ ! -d /usr/sausalito/license ];then 
  /bin/mkdir /usr/sausalito/license 
fi

if [ -d /usr/sausalito/license ];then 
  /bin/chmod 700 /usr/sausalito/license/ 
  /bin/chown admserv:admserv /usr/sausalito/license
fi

if [ ! -d /usr/sausalito/capcache ];then 
    /bin/mkdir /usr/sausalito/capcache 
fi
if [ -d /usr/sausalito/capcache ];then 
    /bin/chmod 700 /usr/sausalito/capcache/ 
    /bin/chown admserv:admserv /usr/sausalito/capcache
fi

if [ ! -d /usr/sausalito/sessions ];then 
    /bin/mkdir /usr/sausalito/sessions 
fi

if [ -d /usr/sausalito/sessions ];then
    /bin/chmod 700 /usr/sausalito/sessions/ 
    /bin/chown admserv:admserv /usr/sausalito/sessions
fi

if [ -d /usr/sausalito/ui/chorizo/ci4/writable ];then
  /bin/chown -R admserv:admserv /usr/sausalito/ui/chorizo/ci4/writable
fi

if [ ! -f /var/log/gui-debug.log ];then
  touch /var/log/gui-debug.log
fi

if [ -f /var/log/gui-debug.log ];then
  chown admserv:admserv /var/log/gui-debug.log
fi

### Firstboot setup:

# Do we have an already configured and active eth0?
NMRUNNING=$(systemctl is-active NetworkManager|grep ^active|wc -l)
if [ "$NMRUNNING" -gt "0" ];then
  # We *must* have NetworkManager running for this stuff:

  # Configure the network:
  /usr/sausalito/sbin/network_apply_settings.pl > /tmp/network_debug.log
fi

# Not in OpenVZ CTs!
if [ ! -e /proc/user_beancounters ] && [ ! -e /dev/incus/sock ];then
  # Show login information in the "issue"
  /bin/echo "BlueOnyx 5212R on \S" > /etc/issue
  /bin/echo "Kernel \r on an \m" >> /etc/issue
  /bin/echo "" >> /etc/issue
  /bin/echo "Welcome to your new server ... " >> /etc/issue
  /bin/echo "  To finish setup, simply login in as \"root\" with password \"blueonyx\"" >> /etc/issue
  /bin/echo "" >> /etc/issue
fi

# Only mess with Grub if we're NOT in an OpenVZ or Incus Container:
if [ ! -e /proc/user_beancounters ] && [ ! -e /dev/incus/sock ]; then

  /bin/echo "Applying changes to Grub configuration ..."

  # Set grub to noisy and remove 'rhgb quiet':
  if [ -f /etc/default/grub ];then
    /bin/sed -i -e 's@ rhgb quiet@@' /etc/default/grub

    # Remove these, but add them again later:
    /bin/sed -i -e 's@ biosdevname=0@@' /etc/default/grub
    /bin/sed -i -e 's@ net.ifnames=0@@' /etc/default/grub
    /bin/sed -i -e 's@ rootflags=uquota,grpquota@@' /etc/default/grub
  fi
  if [ -f /boot/grub2/grub.cfg ];then
    /bin/sed -i -e 's@ rhgb quiet@@' /boot/grub2/grub.cfg
  fi
  if [ -f /etc/grub2.cfg ];then
    /bin/sed -i -e 's@ rhgb quiet@@' /etc/grub2.cfg
  fi
  /bin/echo "Grub options 'rhgb quiet' have been removed."


  if [ ! -L /etc/mtab ];then
      cp /etc/mtab /etc/mtab.bak
      /bin/rm -f /etc/mtab
      ln -s /proc/self/mounts /etc/mtab
  fi

  if [ ! -L /etc/grub2.cfg ];then
    if [ -f /boot/grub2/grub.cfg ];then
      cp /boot/grub2/grub.cfg /boot/grub2/grub.cfg.bak
      mv /etc/grub2.cfg /boot/grub2/grub.cfg
      ln -s /boot/grub2/grub.cfg /etc/grub2.cfg
    fi
  fi

  # Do we have XFS filesystem?
  HAVEXFS=$(mount|grep "type xfs"|wc -l)

  # Fix Grub for real and deploy it:
  if [ -f /etc/default/grub ];then
          NEEDFIX=`cat /etc/default/grub | grep ^GRUB_CMDLINE_LINUX|grep name|wc -l`
          CURRARGS=`cat /etc/default/grub | grep ^GRUB_CMDLINE_LINUX|cut -d \" -f2`
          if [ "$HAVEXFS" -gt "0" ];then
            NEWARGS="$CURRARGS net.ifnames=0 biosdevname=0 selinux=0 rootflags=uquota,grpquota"
            /bin/echo "Grub options 'biosdevname=0', 'net.ifnames=0', 'selinux=0' and 'rootflags=uquota,pquota,grpquota' have been set."
          else
            NEWARGS="$CURRARGS net.ifnames=0 biosdevname=0 selinux=0"
            /bin/echo "Grub options 'biosdevname=0', 'net.ifnames=0' and 'selinux=0' have been set."
          fi
          if [ $NEEDFIX -eq 0 ]; then
                  # Copy cleaned file:
                  cat /etc/default/grub | grep -v ^GRUB_CMDLINE_LINUX > /tmp/grub.clean
                  # Append fixed option:
                  echo "GRUB_CMDLINE_LINUX=\"$NEWARGS\"" >> /tmp/grub.clean
                  # Move:
                  mv /tmp/grub.clean /etc/default/grub
          fi
  else
          if [ "$HAVEXFS" -gt "0" ];then
            NEWARGS="$CURRARGS net.ifnames=0 biosdevname=0 selinux=0 rootflags=uquota,grpquota"
            /bin/echo "Grub options 'biosdevname=0', 'net.ifnames=0', 'selinux=0' and 'rootflags=uquota,pquota,grpquota' have been set."
          else
            NEWARGS="$CURRARGS net.ifnames=0 biosdevname=0 selinux=0"
            /bin/echo "Grub options 'biosdevname=0', 'selinux=0' and 'net.ifnames=0' have been set."
          fi
          touch /etc/default/grub
          echo "GRUB_CMDLINE_LINUX=\"$NEWARGS\"" >> /etc/default/grub
  fi

  # Deploy Grub:
  if [ -d /sys/firmware/efi ];then
          # For EFI:
          efibootmgr -c -d /dev/sda -p 1 -L "BlueOnyx 5212R" -l '\EFI\centos\shim.efi'
          if [ -f /usr/sbin/grub2-mkconfig ];then
            grub2-mkconfig -o /boot/efi/EFI/centos/grub.cfg
          fi
  else
          # Non-EFI:
          # Run grub2-mkconfig -o /boot/grub2/grub.cfg
          if [ -f /usr/sbin/grub2-mkconfig ];then
            grub2-mkconfig -o /boot/grub2/grub.cfg
          fi
  fi
fi

###

# CentOS and their bloody appstreams break /etc/httpd/conf.d/php.conf.
PHPBROKEN=`cat /etc/httpd/conf.d/php.conf | grep "#    Require all denied" | wc -l`
if [ $PHPBROKEN = "0" ]; then
  perl -0777 -i.bak -pe 's/#<Files ".user.ini">\n    Require all denied\n#<\/Files>/#<Files ".user.ini">\n#    Require all denied\n#<\/Files>/igs' /etc/httpd/conf.d/php.conf
fi

if [ -f /usr/sausalito/sbin/hotfixes.sh ];then
  /bin/echo "Applying Swatch hotfixes ..."
  /usr/sausalito/sbin/hotfixes.sh &>/dev/null || :
fi

if [ -d /usr/sausalito/codb ] && [ ! -d /home/.users/admin ];then
  # This could bit us in the ass. Hopefully not.
  # At the end of the YUM groupinstall blueonyx we want to remove
  # the CODB database completly. Because the RPM install could already
  # have caused a partial CODB population from POST-install scriptlets.
  # BUT: We never, never - ever - want to delete this directory once the
  # server has finisted initial setup. So we check for the presence of the
  # 'admin' accounts home directory. If 'admin' is absent, it should be safe
  # to wipe CODB.
  /bin/echo "Resetting CODB database ..."
  systemctl daemon-reload &>/dev/null || :
  systemctl stop cced.construct.service &>/dev/null || :
  systemctl stop cced.init.service &>/dev/null || :
  rm -Rf /usr/sausalito/codb
  systemctl enable cced.construct.service &>/dev/null || :
  systemctl enable cced.init.service &>/dev/null || :
fi

if [ ! -f /usr/share/ssl/certs/sendmail-2048.dh ];then
  /bin/echo "Creating 2048 Bit Diffie Hellman Parameter file."
  /bin/echo "Please note: This *will* take time. It *may* take several minutes."
  /usr/bin/openssl dhparam -out /usr/share/ssl/certs/sendmail-2048.dh 2048
  /bin/echo "Created /usr/share/ssl/certs/sendmail-2048.dh - moving on."
fi

# Not in OpenVZ CTs!
if [ ! -f /proc/user_beancounters ];then
  /bin/echo "Enabling NetworkManager ..."
  systemctl enable NetworkManager

  # We run this only once!
  if [ ! -f /tmp/network_debug.log ];then
    /usr/sausalito/sbin/network_apply_settings.pl > /tmp/network_debug.log
  fi
fi

if [ -f /usr/sausalito/scripts/enableQuota.sh ] && [ ! -e /dev/incus/sock ];then
  /usr/sausalito/scripts/enableQuota.sh &>/tmp/enableQuota.log || :
fi

# Create a file in /tmp to show us that we did run:
touch /tmp/initServices.sh.hasrun

/bin/echo ""
# Not in OpenVZ CTs!
if [ ! -e /proc/user_beancounters ];then
  /bin/echo "All changes applied. Please revise your network settings "
else
  /bin/echo "All changes applied. Please revise your network settings "
fi
/bin/echo "to see if it is still configured correctly. After you have done so please reboot"
/bin/echo "and then login as 'root' via SSH or the command line to finish the setup."
