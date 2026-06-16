#!/usr/bin/sh

tmpfile='/etc/fstab.tmp'

# Do we have XFS filesystem?
HAVEXFS=$(cat /etc/fstab|grep xfs|wc -l)
if [ "$HAVEXFS" -gt "0" ];then
  grep '/home' /etc/fstab > /dev/null 2>&1
  if [ $? -eq 0 ];
  then
    dir='/home'
  else
    dir='/'
  fi
  rm -f $tmpfile
  cat /etc/fstab | while read line
  do
    mntpnt=`echo $line | awk '{ print $2 }'`
    fstype=`echo $line | awk '{ print $3 }'`
    if [ "$mntpnt" = "$dir" ]
    then
      for qtype in uquota gquota
      do
        if ! (echo "$line" | grep -q -e "$qtype")
        then
          line=`echo "$line" | sed -e "s|defaults|defaults,$qtype|"`
        fi
      done
    fi
    echo "$line" >> $tmpfile
  done
  mv /etc/fstab /etc/fstab.bak
  mv $tmpfile /etc/fstab
  if [ "$dir" == "/home" ];then
    umount -l $dir > /dev/null 2>&1
  fi
  /bin/mount -o remount $dir > /dev/null 2>&1
  /bin/mount -a > /dev/null 2>&1
  /sbin/quotacheck -cugm $dir >/dev/null 2>&1
  /sbin/quotaon -ug $dir > /dev/null 2>&1
  echo "Filesystem is XFS."
  exit
fi

grep '/home' /etc/fstab > /dev/null 2>&1
if [ $? -eq 0 ];
then
  dir='/home'
else
  dir='/'
fi

rm -f $tmpfile
cat /etc/fstab | while read line
do
  mntpnt=`echo $line | awk '{ print $2 }'`
  fstype=`echo $line | awk '{ print $3 }'`
  if [ "$mntpnt" = "$dir" ]
  then
    for qtype in grpquota usrquota
    do
      if ! (echo "$line" | grep -q -e "$qtype")
      then
        line=`echo "$line" | sed -e "s|defaults|defaults,$qtype|"`
      fi
    done
  fi
  echo "$line" >> $tmpfile
done
mv /etc/fstab /etc/fstab.bak
mv $tmpfile /etc/fstab
if [ "$dir" == "/home" ];then
  umount -l $dir > /dev/null 2>&1
fi
/bin/mount -o remount $dir > /dev/null 2>&1
/bin/mount -a > /dev/null 2>&1
/sbin/quotacheck -cugm $dir >/dev/null 2>&1
/sbin/quotaon -ug $dir > /dev/null 2>&1
echo "Filesystem is EXT4."
