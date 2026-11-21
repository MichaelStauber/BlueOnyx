#!/bin/sh
#
# enableQuota.sh - enable user/group quota on /home (or /) via /etc/fstab
#
# STRATEGY (mandatory after ISO first-boot failures):
#   Do NOT install a new fstab inode via mktemp+mv of the whole table.
#   Build a candidate next to fstab, verify it hard, then copy *contents*
#   into the existing fstab (or cp if missing). If anything looks wrong,
#   leave the live file alone and/or restore fstab.bak.
#
#   Observed failure mode on 5212R Complete ISO 20261121 / isotester:
#     /etc/fstab -> 0 bytes, mode 0600 (mktemp fingerprint)
#     /etc/fstab.bak -> 992 bytes good Anaconda table (no quota yet)
#   Full-table replace is treated as fatal design; line-oriented edit only.
#
# Callers (may overlap): kickstart %post, initServices.sh, bx_runonce,
# glue %post, DNF, image builds.
#
# Contract:
#   - flock exclusive; fail closed without flock
#   - empty live + good bak => restore bak contents first
#   - edit only the target mount line options (XFS uquota,gquota else
#     usrquota,grpquota); other lines copied unchanged
#   - never commit a candidate that is empty, shorter, or missing the
#     target mount line
#   - no umount -l / mount -a; live quotaon only when multi-user
#   - containers: text only
#

fstab='/etc/fstab'
bak='/etc/fstab.bak'
lockfile='/var/lock/blueonyx-enableQuota.lock'
if [ -d /run/lock ] && [ -w /run/lock ]; then
    lockfile='/run/lock/blueonyx-enableQuota.lock'
elif [ -d /var/lock ] && [ -w /var/lock ]; then
    lockfile='/var/lock/blueonyx-enableQuota.lock'
else
    lockfile='/etc/.blueonyx-enableQuota.lock'
fi

lockdir=$(dirname "$lockfile")
if [ ! -d "$lockdir" ]; then
    mkdir -p "$lockdir" 2>/dev/null || true
fi
if command -v flock >/dev/null 2>&1; then
    exec 9>"$lockfile" || {
        echo "enableQuota.sh: cannot open $lockfile" >&2
        exit 1
    }
    if ! flock -x -w 120 9; then
        echo "enableQuota.sh: could not obtain lock $lockfile within 120s" >&2
        exit 1
    fi
else
    echo "enableQuota.sh: flock not found; refusing to edit fstab unlocked" >&2
    exit 1
fi

log() {
    echo "enableQuota.sh: $*" >&2
}

# Install a *verified non-empty* source as the new fstab using same-FS
# atomic rename. Never use `cat > fstab` (truncates live file before the
# write finishes - crash/power-loss can leave 0 bytes). Never install an
# unverified mktemp. The ban is on destroying fstab with an empty/bad
# payload, not on atomic rename of a checked candidate.
install_fstab_contents() {
    src=$1
    if [ ! -s "$src" ]; then
        log "install_fstab_contents: refusing empty source"
        return 1
    fi
    src_bytes=$(wc -c < "$src" | tr -d ' ')
    if [ -z "$src_bytes" ] || [ "$src_bytes" -lt 1 ]; then
        log "install_fstab_contents: refusing zero-byte source"
        return 1
    fi

    # Stage beside fstab so rename is atomic and does not cross filesystems.
    stage=$(mktemp "${fstab}.stage.XXXXXX") || {
        log "install_fstab_contents: mktemp stage failed"
        return 1
    }
    if ! cp -p "$src" "$stage" 2>/dev/null; then
        if ! cp "$src" "$stage"; then
            log "install_fstab_contents: stage copy failed"
            rm -f "$stage"
            return 1
        fi
    fi
    if [ ! -s "$stage" ]; then
        log "install_fstab_contents: stage empty after copy"
        rm -f "$stage"
        return 1
    fi
    stage_bytes=$(wc -c < "$stage" | tr -d ' ')
    if [ "$stage_bytes" -ne "$src_bytes" ]; then
        log "install_fstab_contents: stage size mismatch ($stage_bytes != $src_bytes)"
        rm -f "$stage"
        return 1
    fi
    chmod 0644 "$stage" 2>/dev/null || true
    # Best-effort durability before rename.
    if command -v sync >/dev/null 2>&1; then
        sync "$stage" 2>/dev/null || sync 2>/dev/null || true
    fi
    if ! mv -f "$stage" "$fstab"; then
        log "install_fstab_contents: atomic mv stage -> $fstab failed"
        rm -f "$stage"
        return 1
    fi
    if [ ! -s "$fstab" ]; then
        log "install_fstab_contents: $fstab empty after rename"
        return 1
    fi
    return 0
}

restore_fstab_from_bak_if_needed() {
    if [ -s "$fstab" ]; then
        return 0
    fi
    if [ ! -s "$bak" ]; then
        log "$fstab missing or empty and no usable $bak, skipping."
        return 1
    fi
    log "$fstab missing or empty; restoring from $bak (emergency)"
    if install_fstab_contents "$bak"; then
        log "restored $fstab from $bak ($(wc -c < "$fstab" | tr -d ' ') bytes)"
        return 0
    fi
    return 1
}

is_container() {
    [ -e /proc/user_beancounters ] && return 0
    [ -e /dev/incus/sock ] && return 0
    [ -d /run/systemd/container ] && return 0
    if command -v systemd-detect-virt >/dev/null 2>&1; then
        systemd-detect-virt --quiet --container 2>/dev/null && return 0
    fi
    return 1
}

is_install_or_offline() {
    [ -d /run/install ] && return 0
    [ -f /.buildstamp ] && [ ! -d /run/systemd/system ] && return 0
    if [ -x /usr/bin/systemctl ]; then
        state=$(systemctl is-system-running 2>/dev/null || echo offline)
        case "$state" in
            running|degraded|maintenance) return 1 ;;
            *) return 0 ;;
        esac
    fi
    return 0
}

if ! restore_fstab_from_bak_if_needed; then
    exit 0
fi

if [ -s "$fstab" ] && [ ! -s "$bak" ]; then
    cp -p "$fstab" "$bak" 2>/dev/null || true
fi

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

# Stamp is advisory only. Source of truth is always the live fstab line.
# Callers stack: kickstart %post -> initServices (calls us) -> bare
# enableQuota.sh in %post -> runonce symlink. If the fix is already in
# fstab, do absolutely nothing on re-entry (no bak refresh, no candidate,
# no mount surgery).
stamp_dir='/var/lib/blueonyx'
stamp="$stamp_dir/enableQuota.done"

mark_done() {
    mkdir -p "$stamp_dir" 2>/dev/null || true
    {
        echo "dir=$dir"
        echo "q1=$q1"
        echo "q2=$q2"
        echo "fs=$fs_label"
        date -u +'utc=%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || true
    } > "$stamp" 2>/dev/null || true
}

fstab_quota_already_ok() {
    # True iff the target mount line already carries both quota tokens.
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac
        mntpnt=$(echo "$line" | awk '{ print $2 }')
        if [ "$mntpnt" = "$dir" ]; then
            echo "$line" | grep -q "$q1" || return 1
            echo "$line" | grep -q "$q2" || return 1
            return 0
        fi
    done < "$fstab"
    return 1
}

if fstab_quota_already_ok; then
    log "already done: $dir has $q1+$q2 in $fstab - no-op (safe re-entry)"
    mark_done
    exit 0
fi

# Optional fast path: stamp claims done AND fstab still matches (above
# already covers the real check). If stamp exists but fstab lost tokens,
# fall through and repair.

# Build candidate by streaming line-by-line; only the target mount line
# gets option tokens. Candidate must never be installed unless verified.
edit_fstab_line() {
    before_bytes=$(wc -c < "$fstab" | tr -d ' ')
    before_lines=$(wc -l < "$fstab" | tr -d ' ')
    if [ -z "$before_bytes" ] || [ "$before_bytes" -lt 1 ]; then
        log "refusing to edit empty $fstab"
        return 1
    fi

    # Pre-edit bak (best effort).
    cp -p "$fstab" "$bak" 2>/dev/null || true

    # Candidate lives beside fstab (same fs); never becomes the live inode.
    cand=$(mktemp "${fstab}.candidate.XXXXXX") || {
        log "mktemp candidate failed"
        return 1
    }
    # shellcheck disable=SC2064
    trap 'rm -f "$cand"' 0

    TARGET_DIR="$dir" Q1="$q1" Q2="$q2" CAND="$cand" FSTAB_PATH="$fstab" /usr/bin/perl -e '
        my $dir = $ENV{TARGET_DIR};
        my $q1  = $ENV{Q1};
        my $q2  = $ENV{Q2};
        my $out = $ENV{CAND};
        my $inpath = $ENV{FSTAB_PATH} || "/etc/fstab";
        open my $in, "<", $inpath or die "open fstab: $!";
        open my $oh, ">", $out or die "open cand: $!";
        my $touched = 0;
        while (my $line = <$in>) {
            my $nl = ($line =~ s/\n\z//) ? "\n" : "";
            if ($line =~ /^\s*#/ || $line =~ /^\s*$/) {
                print $oh $line, $nl;
                next;
            }
            my @f = split /\s+/, $line;
            if (@f >= 4 && defined $f[1] && $f[1] eq $dir) {
                my $opts = $f[3];
                for my $q ($q1, $q2) {
                    next if $opts =~ /(^|,)\Q$q\E(,|$)/;
                    # Always append tokens. Do not re-match "defaults" after the
                    # first insert (that produced defaults,gquota,uquota order
                    # and double-touched the defaults field).
                    $opts .= ",$q";
                }
                $f[3] = $opts;
                $line = join(" ", @f);
                $touched = 1;
            }
            print $oh $line, $nl;
        }
        close $in;
        close $oh or die "close cand: $!";
        exit($touched ? 0 : 2);
    '
    perl_rc=$?
    if [ "$perl_rc" -eq 2 ]; then
        log "target mount $dir not found in fstab; leaving live file untouched"
        rm -f "$cand"
        trap - 0
        return 0
    fi
    if [ "$perl_rc" -ne 0 ]; then
        log "candidate build failed (rc=$perl_rc); leaving $fstab untouched"
        rm -f "$cand"
        trap - 0
        return 1
    fi

    cand_bytes=$(wc -c < "$cand" | tr -d ' ')
    cand_lines=$(wc -l < "$cand" | tr -d ' ')

    if [ ! -s "$cand" ]; then
        log "candidate empty; leaving $fstab untouched"
        rm -f "$cand"
        trap - 0
        return 1
    fi
    if [ "$before_lines" -gt 0 ] && [ "$cand_lines" -lt "$before_lines" ]; then
        log "candidate fewer lines ($cand_lines < $before_lines); leave live"
        rm -f "$cand"
        trap - 0
        return 1
    fi
    if [ "$cand_bytes" -lt $((before_bytes / 2)) ]; then
        log "candidate shrank too much ($cand_bytes < half of $before_bytes); leave live"
        rm -f "$cand"
        trap - 0
        return 1
    fi
    # Target line must exist and carry both tokens.
    tgt=$(awk -v d="$dir" '($1 !~ /^#/ && NF >= 4 && $2 == d) { print; exit }' "$cand")
    if [ -z "$tgt" ] || ! echo "$tgt" | grep -q "$q1" || ! echo "$tgt" | grep -q "$q2"; then
        log "candidate missing quota tokens on $dir; leave live"
        rm -f "$cand"
        trap - 0
        return 1
    fi
    # No-op if identical.
    if cmp -s "$fstab" "$cand" 2>/dev/null; then
        log "fstab already up to date for $dir ($q1,$q2)"
        rm -f "$cand"
        trap - 0
        return 0
    fi

    if ! install_fstab_contents "$cand"; then
        log "install failed; attempting bak restore"
        if [ -s "$bak" ]; then
            install_fstab_contents "$bak" || true
        fi
        rm -f "$cand"
        trap - 0
        return 1
    fi
    rm -f "$cand"
    trap - 0

    # Post-condition.
    if [ ! -s "$fstab" ]; then
        log "CRITICAL: $fstab empty after install; restoring bak"
        [ -s "$bak" ] && install_fstab_contents "$bak" || true
        return 1
    fi
    cp -p "$fstab" "$bak" 2>/dev/null || true
    log "line edit committed for $dir ($q1,$q2); $(wc -c < "$fstab" | tr -d ' ') bytes"
    return 0
}

activate_quota() {
    if is_container; then
        log "container detected: skipping mount/quotaon"
        return 0
    fi
    if is_install_or_offline; then
        log "install/offline environment: fstab updated, skipping mount/quotaon"
        return 0
    fi
    /bin/mount -o "remount,$q1,$q2" "$dir" >/dev/null 2>&1 \
        || /bin/mount -o remount "$dir" >/dev/null 2>&1 \
        || true
    if [ "$fs_is_xfs" -eq 0 ]; then
        /sbin/quotacheck -cugm "$dir" >/dev/null 2>&1 || true
    fi
    /sbin/quotaon -ug "$dir" >/dev/null 2>&1 || true
    return 0
}

# Reached only when fstab still lacks quota tokens on $dir.
edit_fstab_line || exit 1
mark_done
activate_quota
log "done ($fs_label, dir=$dir)."
exit 0
