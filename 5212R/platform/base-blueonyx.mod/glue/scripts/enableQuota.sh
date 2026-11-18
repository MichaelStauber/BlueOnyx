#!/bin/sh
#
# enableQuota.sh - enable user/group quota on /home (or /) via /etc/fstab
#
# Install paths that all call this (sometimes more than once, sometimes
# overlapping): ISO kickstart %post, initServices.sh, bx_runonce, glue %post
# side effects, manual DNF install on Alma, Incus/distrobuilder images.
#
# Contract:
#   - Safe to run any number of times (idempotent fstab rewrite).
#   - Concurrent invocations serialize on an exclusive flock; a second
#     waiter runs after the first and no-ops if already done.
#   - Never replace /etc/fstab with an empty/missing temp file.
#   - If fstab would be left empty after a failed replace, restore .bak.
#   - Do not umount -l or mount -a (unsafe in Anaconda %post / half-booted
#     systems and was a candidate for collateral damage). Live activation
#     is a best-effort remount + quotaon only when the system is "live".
#   - Containers (OpenVZ beancounters, Incus sock): fstab text only if
#     present; skip mount/quotaon (no real block quota there).
#

fstab='/etc/fstab'
bak='/etc/fstab.bak'
lockfile='/var/lock/blueonyx-enableQuota.lock'
# Prefer /run/lock when it exists and is writable (tmpfs, boots early).
if [ -d /run/lock ] && [ -w /run/lock ]; then
    lockfile='/run/lock/blueonyx-enableQuota.lock'
elif [ -d /var/lock ] && [ -w /var/lock ]; then
    lockfile='/var/lock/blueonyx-enableQuota.lock'
else
    lockfile='/etc/.blueonyx-enableQuota.lock'
fi

# --- exclusive lock (util-linux flock on fd 9; no env re-exec bypass) ---
lockdir=$(dirname "$lockfile")
if [ ! -d "$lockdir" ]; then
    mkdir -p "$lockdir" 2>/dev/null || true
fi
if command -v flock >/dev/null 2>&1; then
    # Hold fd 9 for the whole process lifetime. Waiters block up to 120s
    # so kickstart %post and runonce serialize instead of interleaving.
    exec 9>"$lockfile" || {
        echo "enableQuota.sh: cannot open $lockfile" >&2
        exit 1
    }
    if ! flock -x -w 120 9; then
        echo "enableQuota.sh: could not obtain lock $lockfile within 120s" >&2
        exit 1
    fi
else
    # Fail closed: without serialization the brief is not met.
    echo "enableQuota.sh: flock not found; refusing to rewrite fstab unlocked" >&2
    exit 1
fi

log() {
    echo "enableQuota.sh: $*" >&2
}

# --- environment guards ------------------------------------------------
is_container() {
    [ -e /proc/user_beancounters ] && return 0
    [ -e /dev/incus/sock ] && return 0
    [ -d /run/systemd/container ] && return 0
    if command -v systemd-detect-virt >/dev/null 2>&1; then
        systemd-detect-virt --quiet --container 2>/dev/null && return 0
    fi
    return 1
}

# Anaconda installer, image build, or system not really multi-user yet:
# rewriting fstab is OK; mount surgery is not.
is_install_or_offline() {
    [ -d /run/install ] && return 0
    [ -f /.buildstamp ] && [ ! -d /run/systemd/system ] && return 0
    # chroot without a real systemd (DNF %post on a target root often has this)
    if [ -x /usr/bin/systemctl ]; then
        state=$(systemctl is-system-running 2>/dev/null || echo offline)
        case "$state" in
            running|degraded|maintenance) return 1 ;;
            *) return 0 ;;
        esac
    fi
    return 0
}

if [ ! -s "$fstab" ]; then
    if [ -s "$bak" ]; then
        log "$fstab missing or empty; restoring from $bak"
        if ! cp -p "$bak" "$fstab"; then
            log "restore from $bak failed"
            exit 1
        fi
        if [ ! -s "$fstab" ]; then
            log "restore left $fstab empty; abort"
            exit 1
        fi
    else
        log "$fstab missing or empty and no usable $bak, skipping."
        exit 0
    fi
fi

# Target mount: prefer /home when it is a real fstab mount point (field 2).
dir='/'
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        ''|\#*) continue ;;
    esac
    mntpnt=$(echo "$line" | awk '{ print $2 }')
    if [ "$mntpnt" = "/home" ]; then
        dir='/home'
        break
    fi
done < "$fstab"

# Filesystem family from fstab (xfs vs ext*).
fs_is_xfs=0
if grep -E -q '[[:space:]]xfs[[:space:]]' "$fstab"; then
    fs_is_xfs=1
fi

if [ "$fs_is_xfs" -eq 1 ]; then
    q1=uquota
    q2=gquota
    fs_label=XFS
else
    q1=usrquota
    q2=grpquota
    fs_label=EXT
fi

# --- idempotent: already has both quota options on $dir? ---------------
need_rewrite=1
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        ''|\#*) continue ;;
    esac
    mntpnt=$(echo "$line" | awk '{ print $2 }')
    if [ "$mntpnt" = "$dir" ]; then
        if echo "$line" | grep -q "$q1" && echo "$line" | grep -q "$q2"; then
            need_rewrite=0
        fi
        break
    fi
done < "$fstab"

rewrite_fstab() {
    # Unique temp in /etc so rename is same-filesystem atomic.
    tmpfile=$(mktemp "$fstab.tmp.XXXXXX") || {
        log "mktemp failed, aborting rewrite"
        return 1
    }
    # shellcheck disable=SC2064
    trap 'rm -f "$tmpfile"' 0

    while IFS= read -r line || [ -n "$line" ]; do
        mntpnt=$(echo "$line" | awk '{ print $2 }')
        if [ "$mntpnt" = "$dir" ]; then
            for qtype in $q1 $q2; do
                if ! echo "$line" | grep -q -e "$qtype"; then
                    # Only inject into a defaults options field when present.
                    if echo "$line" | grep -q '[[:space:]]defaults'; then
                        # Capture the whitespace; do NOT put [[:space:]] in the
                        # replacement (sed would insert that text literally).
                        line=$(echo "$line" | sed -e "s|\([[:space:]]\)defaults|\1defaults,$qtype|")
                    elif echo "$line" | awk '{ print $4 }' | grep -q .; then
                        # defaults missing: append to options field (col 4).
                        # Keep original FS (do not force OFS=tab).
                        line=$(echo "$line" | awk -v q="$qtype" '{
                            if (NF >= 4) $4 = $4 "," q
                            print
                        }')
                    fi
                fi
            done
        fi
        if ! printf '%s\n' "$line" >> "$tmpfile"; then
            log "failed writing $tmpfile (disk full?); leaving $fstab in place"
            rm -f "$tmpfile"
            trap - 0
            return 1
        fi
    done < "$fstab"

    if [ ! -s "$tmpfile" ]; then
        log "rewrite produced empty temp, leaving $fstab in place"
        rm -f "$tmpfile"
        trap - 0
        return 1
    fi

    # Same number of lines as source (truncated write / short read guard).
    src_lines=$(wc -l < "$fstab" | tr -d ' ')
    tmp_lines=$(wc -l < "$tmpfile" | tr -d ' ')
    # Allow +0 only: we always printf one line per input line including final
    # non-newline-terminated line (read -r || [ -n ]).
    if [ "$src_lines" -gt 0 ] && [ "$tmp_lines" -lt "$src_lines" ]; then
        log "temp has fewer lines than $fstab ($tmp_lines < $src_lines); abort"
        rm -f "$tmpfile"
        trap - 0
        return 1
    fi

    # Byte-identical to current fstab: nothing to commit.
    if cmp -s "$fstab" "$tmpfile" 2>/dev/null; then
        log "fstab already up to date for $dir ($q1,$q2)"
        rm -f "$tmpfile"
        trap - 0
        return 0
    fi

    # Backup only when we have a usable replacement.
    cp -p "$fstab" "$bak" || {
        log "failed to write $bak"
        rm -f "$tmpfile"
        trap - 0
        return 1
    }

    if ! mv -f "$tmpfile" "$fstab"; then
        log "mv temp -> $fstab failed; restoring from $bak if needed"
        rm -f "$tmpfile"
        if [ ! -s "$fstab" ] && [ -s "$bak" ]; then
            cp -p "$bak" "$fstab" || true
        fi
        trap - 0
        return 1
    fi
    trap - 0

    # Post-condition: never leave an empty fstab behind.
    if [ ! -s "$fstab" ]; then
        log "CRITICAL: $fstab empty after replace; restoring $bak"
        if [ -s "$bak" ]; then
            cp -p "$bak" "$fstab" || true
        fi
        return 1
    fi
    return 0
}

activate_quota() {
    # Text-only during install, image build, or containers.
    if is_container; then
        log "container detected: skipping mount/quotaon"
        return 0
    fi
    if is_install_or_offline; then
        log "install/offline environment: fstab updated, skipping mount/quotaon"
        return 0
    fi

    # Best-effort live path only. No umount -l, no mount -a.
    /bin/mount -o "remount,$q1,$q2" "$dir" >/dev/null 2>&1 \
        || /bin/mount -o remount "$dir" >/dev/null 2>&1 \
        || true
    if [ "$fs_is_xfs" -eq 0 ]; then
        /sbin/quotacheck -cugm "$dir" >/dev/null 2>&1 || true
    fi
    /sbin/quotaon -ug "$dir" >/dev/null 2>&1 || true
    return 0
}

if [ "$need_rewrite" -eq 1 ]; then
    rewrite_fstab || exit 1
else
    log "quota options already present on $dir, rewrite skipped"
fi

activate_quota
log "done ($fs_label, dir=$dir)."
exit 0
