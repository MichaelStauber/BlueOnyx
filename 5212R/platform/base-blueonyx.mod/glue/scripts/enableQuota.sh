#!/usr/bin/sh

# Rewrite /etc/fstab to enable quota on /home (or /). Never replace
# fstab unless the rewrite produced a non-empty file. An empty or
# missing fstab (Anaconda %post race) must be left alone — the old
# mv-then-mv path deleted fstab when the tmpfile was never created.

tmpfile='/etc/fstab.tmp'
fstab='/etc/fstab'

if [ ! -s "$fstab" ]; then
    echo "enableQuota.sh: $fstab missing or empty, skipping." >&2
    exit 0
fi

rewrite_fstab() {
    dir="$1"
    q1="$2"
    q2="$3"
    rm -f "$tmpfile"
    # Keep the last line even if it has no trailing newline.
    while IFS= read -r line || [ -n "$line" ]
    do
        mntpnt=`echo "$line" | awk '{ print $2 }'`
        if [ "$mntpnt" = "$dir" ]
        then
            for qtype in $q1 $q2
            do
                if ! echo "$line" | grep -q -e "$qtype"
                then
                    line=`echo "$line" | sed -e "s|defaults|defaults,$qtype|"`
                fi
            done
        fi
        echo "$line" >> "$tmpfile"
    done < "$fstab"

    if [ ! -s "$tmpfile" ]; then
        echo "enableQuota.sh: rewrite produced empty $tmpfile, leaving $fstab in place." >&2
        rm -f "$tmpfile"
        return 1
    fi
    # Backup only after a usable replacement exists.
    cp -p "$fstab" /etc/fstab.bak || return 1
    mv -f "$tmpfile" "$fstab" || return 1
    return 0
}

activate_quota() {
    dir="$1"
    if [ "$dir" = "/home" ]; then
        umount -l "$dir" > /dev/null 2>&1
    fi
    /bin/mount -o remount "$dir" > /dev/null 2>&1
    /bin/mount -a > /dev/null 2>&1
    /sbin/quotacheck -cugm "$dir" >/dev/null 2>&1
    /sbin/quotaon -ug "$dir" >/dev/null 2>&1
}

if grep -q '/home' "$fstab"
then
    dir='/home'
else
    dir='/'
fi

if grep -q xfs "$fstab"
then
    rewrite_fstab "$dir" uquota gquota || exit 1
    activate_quota "$dir"
    echo "Filesystem is XFS."
    exit 0
fi

rewrite_fstab "$dir" grpquota usrquota || exit 1
activate_quota "$dir"
echo "Filesystem is EXT4."
