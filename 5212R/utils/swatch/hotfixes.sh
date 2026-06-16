#!/usr/bin/bash

rm -f /etc/.pwd.lock
rm -f /etc/group.lock

# Check if 'root-admin' password is empty. If so, get the hash of the 'root' password and set it:
PWPROBLEM=$(LC_ALL=C /usr/bin/passwd -S root-admin|/usr/bin/grep "Empty password"|/usr/bin/wc -l)
if [ "$PWPROBLEM" -eq "1" ];then
    /usr/bin/getent shadow|/usr/bin/grep root:|/usr/bin/cut -d : -f1-2|/usr/bin/sed "s@^root:@root-admin:@g"|/usr/sbin/chpasswd -e 
fi

# Remove double entries from /etc/crontab:
CRONISSUE=$(cat /etc/crontab|grep /etc/cron.quarter-daily|wc -l)
if [ "$CRONISSUE" -gt "1" ];then
    echo 'SHELL=/bin/bash' > /etc/crontab.new
    echo 'PATH=/sbin:/bin:/usr/sbin:/usr/bin' >> /etc/crontab.new
    echo 'MAILTO=root' >> /etc/crontab.new
    echo '' >> /etc/crontab.new
    echo '# For details see man 4 crontabs' >> /etc/crontab.new
    echo '' >> /etc/crontab.new
    echo '# Example of job definition:' >> /etc/crontab.new
    echo '# .---------------- minute (0 - 59)' >> /etc/crontab.new
    echo '# |  .------------- hour (0 - 23)' >> /etc/crontab.new
    echo '# |  |  .---------- day of month (1 - 31)' >> /etc/crontab.new
    echo '# |  |  |  .------- month (1 - 12) OR jan,feb,mar,apr ...' >> /etc/crontab.new
    echo '# |  |  |  |  .---- day of week (0 - 6) (Sunday=0 or 7) OR sun,mon,tue,wed,thu,fri,sat' >> /etc/crontab.new
    echo '# |  |  |  |  |' >> /etc/crontab.new
    echo '# *  *  *  *  * user-name  command to be executed' >> /etc/crontab.new
    echo '' >> /etc/crontab.new
    cat /etc/crontab|grep -v ^#|grep -e ^[0-9]|sort -u >> /etc/crontab.new
    echo '' >> /etc/crontab.new
    mv /etc/crontab.new /etc/crontab
    /usr/bin/systemctl restart crond
fi

# Fix PHP Session dir GID if needed:
if [ -d "/var/lib/php/session" ];then
    SESSPERMS=`ls -la /var/lib/php|grep session|awk '{print $1}'`
    if [ $SESSPERMS != "drwxrwxrwx" ];then
        chmod 777 /var/lib/php/session
    fi
fi

# Fix RC-Local permissions:
if [ -f "/etc/rc.d/rc.local" ];then
    RCPERMS=`ls -la /etc/rc.d/rc.local|grep rc.local|awk '{print $1}'`
    if [ $RCPERMS != "-rwxr-xr-x." ] || [ $RCPERMS != "-rwxr-xr-x" ];then
        chmod +x /etc/rc.d/rc.local
    fi
fi

# Fix potential license directory issues:
if [ ! -d "/usr/sausalito/license" ];then
    mkdir -p /usr/sausalito/license
    chmod 0700 /usr/sausalito/license
    chown admserv:admserv /usr/sausalito/license
else
    LICOWNER=`ls -la /usr/sausalito/|grep license|grep admserv|wc -l`
    if [ $LICOWNER -eq 0 ];then
        chmod 0700 /usr/sausalito/license
        chown admserv:admserv /usr/sausalito/license
    fi
fi

# Fix potential sessions directory issues:
if [ ! -d "/usr/sausalito/sessions" ];then
    mkdir -p /usr/sausalito/sessions
    chmod 0700 /usr/sausalito/sessions
    chown admserv:admserv /usr/sausalito/sessions
else
    CAPOWNER=`ls -la /usr/sausalito/|grep sessions|grep admserv|wc -l`
    if [ $CAPOWNER -eq 0 ];then
        chmod 0700 /usr/sausalito/sessions
        chown admserv:admserv /usr/sausalito/sessions
    fi
fi

# Fix potential CI4 writable directory issues:
if [ ! -d "/usr/sausalito/ui/chorizo/ci4/writable" ];then
    mkdir -p /usr/sausalito/ui/chorizo/ci4/writable
    chown -R admserv:admserv /usr/sausalito/ui/chorizo/ci4/writable
else
    CAPOWNER=`ls -la /usr/sausalito/ui/chorizo/ci4|grep writable|grep admserv|wc -l`
    if [ $CAPOWNER -eq 0 ];then
        chown -R admserv:admserv /usr/sausalito/ui/chorizo/ci4/writable
    fi
fi

# Fix gui-debug.log:
if [ ! -f /var/log/gui-debug.log ];then
  touch /var/log/gui-debug.log
fi

if [ -f /var/log/gui-debug.log ];then
    CAPOWNER=`ls -la /var/log/gui-debug.log|grep admserv|wc -l`
    if [ $CAPOWNER -eq 0 ];then
        chown admserv:admserv /var/log/gui-debug.log
    fi
fi

# The CD-Installer of BlueOnyx brings /usr/bin/fix-httpd-log-dir aboard, but
# it might not be executable:
if [ -f /usr/bin/fix-httpd-log-dir ]; then
    chmod 755 /usr/bin/fix-httpd-log-dir
fi

# While we are at it, delete the default Apache welcome page:
if [ -f /etc/httpd/conf.d/welcome.conf ]; then
    /bin/rm -f /etc/httpd/conf.d/welcome.conf
fi

# Also delete /etc/httpd/conf.d/manual.conf:
if [ -f /etc/httpd/conf.d/manual.conf ]; then
    /bin/rm -f /etc/httpd/conf.d/manual.conf
fi

# Also remove server.conf
if [ -f /etc/httpd/conf.d/server.conf ]; then
    /bin/rm -f /etc/httpd/conf.d/server.conf
fi

# Really, RedHat? I think you forgot something!
if [ ! -d /run/php-fpm ];then
    mkdir -p /run/php-fpm
fi

# Run Passive Monitor Support Account watcher (if present):
if [ -f /usr/sausalito/swatch/bin/am_support.pl ]; then
    /usr/sausalito/swatch/bin/am_support.pl
fi

# Create Dovecot SSL parameters (DH-stuff) if it's missing:
if [ ! -f /var/lib/dovecot/ssl-parameters.dat ]; then
    /usr/libexec/dovecot/ssl-params &>/dev/null
fi

# Make Dovecot listen only on address families that are available:
DOVECOT_CONF="/etc/dovecot/dovecot.conf"
if [ -f "$DOVECOT_CONF" ]; then
    DOVECOT_LISTEN=""

    if [ -d /proc/sys/net/ipv4 ]; then
        DOVECOT_LISTEN="*"
    fi

    # IPv6 - much more reliable detection
    if [ -f /proc/net/if_inet6 ] && grep -qE '^[0-9a-f]+' /proc/net/if_inet6 2>/dev/null; then
        # Also double-check that IPv6 isn't disabled via sysctl
        if [ "$(/usr/sbin/sysctl -n net.ipv6.conf.all.disable_ipv6 2>/dev/null || echo 0)" -eq 0 ] && \
           [ "$(/usr/sbin/sysctl -n net.ipv6.conf.default.disable_ipv6 2>/dev/null || echo 0)" -eq 0 ]; then
            if [ -n "$DOVECOT_LISTEN" ]; then
                DOVECOT_LISTEN="$DOVECOT_LISTEN, ::"
            else
                DOVECOT_LISTEN="::"
            fi
        fi
    fi

    if [ -n "$DOVECOT_LISTEN" ]; then
        DOVECOT_TMP=$(mktemp)
        DOVECOT_LISTEN_COUNT=$(grep -c '^[[:space:]]*listen[[:space:]]*=' "$DOVECOT_CONF")

        if [ "$DOVECOT_LISTEN_COUNT" -gt 0 ]; then
            if awk -v want="$DOVECOT_LISTEN" '
                BEGIN {
                    changed = 0
                    seen_active = 0
                }
                /^[[:space:]]*listen[[:space:]]*=/ {
                    if (!seen_active) {
                        print "listen = " want
                        seen_active = 1
                        if ($0 != "listen = " want) {
                            changed = 1
                        }
                    } else {
                        changed = 1
                    }
                    next
                }
                {
                    print
                }
                END {
                    exit changed ? 10 : 0
                }
            ' "$DOVECOT_CONF" > "$DOVECOT_TMP"; then
                rm -f "$DOVECOT_TMP"
            else
                AWK_STATUS=$?
                if [ "$AWK_STATUS" = "10" ]; then
                    cat "$DOVECOT_TMP" > "$DOVECOT_CONF"
                    /usr/bin/systemctl condrestart dovecot &>/dev/null || :
                fi
                rm -f "$DOVECOT_TMP"
            fi
        else
            if awk -v want="$DOVECOT_LISTEN" '
                BEGIN {
                    changed = 0
                    inserted = 0
                    marker = "# A comma separated list of IPs or hosts where to listen in for connections."
                }
                {
                    print
                    if (!inserted && index($0, marker) == 1) {
                        print "listen = " want
                        inserted = 1
                        changed = 1
                    }
                }
                END {
                    if (!inserted) {
                        print "listen = " want
                        changed = 1
                    }
                    exit changed ? 10 : 0
                }
            ' "$DOVECOT_CONF" > "$DOVECOT_TMP"; then
                rm -f "$DOVECOT_TMP"
            else
                AWK_STATUS=$?
                if [ "$AWK_STATUS" = "10" ]; then
                    cat "$DOVECOT_TMP" > "$DOVECOT_CONF"
                    /usr/bin/systemctl condrestart dovecot &>/dev/null || :
                fi
                rm -f "$DOVECOT_TMP"
            fi
        fi
    fi
fi

#
### Sendmail related:
#

ALIASESDONE=$(cat /etc/mail/sendmail.mc |grep ALIAS_FILE|grep /etc/aliases|wc -l)
if [ "$ALIASESDONE" -eq '1' ];then
    # sendmail.mc has incorrect alias association. Fix it!
    if [ -f /usr/sausalito/scripts/initSendmail.sh ];then
        /usr/sausalito/scripts/initSendmail.sh
    fi
fi

SENDMAILPID=$(cat /usr/lib/systemd/system/sendmail.service |grep '^PIDFile=/var/run/sendmail.pid'|wc -l)
if [ "$SENDMAILPID" -eq '1' ];then
    # Sendmail Systemd Unit-File has old style PID setting. Fix it!
    sed -i 's|^PIDFile=/var/run/sendmail.pid|PIDFile=/run/sendmail.pid|g' /usr/lib/systemd/system/sendmail.service
    /usr/bin/systemctl daemon-reload &>/dev/null || :
fi

#
### Postfix related:
#

POSTFIXRESTART=0

# Fix alias location in /usr/libexec/postfix/aliasesdb:
if [ -f /usr/libexec/postfix/aliasesdb ];then
    POSTALIAS=`cat /usr/libexec/postfix/aliasesdb |grep /etc/mail/aliases|wc -l`
    # We need to apply the fix:
    if [ $POSTALIAS -eq 0 ]; then
        sed -i -e 's@/etc/aliases@/etc/mail/aliases@g' /usr/libexec/postfix/aliasesdb
        POSTFIXRESTART=1
    fi
fi

# Fix Postfix Unit-File:
if [ -f /usr/lib/systemd/system/postfix.service ];then
    POSTUNIT=`cat /usr/lib/systemd/system/postfix.service |grep blueonyx-postfix|wc -l`
    # We need to apply the fix:
    if [ $POSTUNIT -eq 0 ]; then
        echo '[Unit]' > /usr/lib/systemd/system/postfix.service
        echo 'Description=Postfix Mail Transport Agent' >> /usr/lib/systemd/system/postfix.service
        echo 'After=syslog.target network.target' >> /usr/lib/systemd/system/postfix.service
        echo 'Conflicts=sendmail.service exim.service' >> /usr/lib/systemd/system/postfix.service
        echo '' >> /usr/lib/systemd/system/postfix.service
        echo '[Service]' >> /usr/lib/systemd/system/postfix.service
        echo 'Type=forking' >> /usr/lib/systemd/system/postfix.service
        echo 'PIDFile=/var/spool/postfix/pid/master.pid' >> /usr/lib/systemd/system/postfix.service
        echo 'EnvironmentFile=-/etc/sysconfig/network' >> /usr/lib/systemd/system/postfix.service
        echo 'PrivateTmp=true' >> /usr/lib/systemd/system/postfix.service
        echo 'CapabilityBoundingSet=~ CAP_NET_ADMIN CAP_SYS_ADMIN CAP_SYS_BOOT CAP_SYS_MODULE' >> /usr/lib/systemd/system/postfix.service
        echo 'ProtectSystem=true' >> /usr/lib/systemd/system/postfix.service
        echo 'PrivateDevices=true' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecStartPre=-/usr/sausalito/bin/blueonyx-postfix' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecStartPre=-/usr/libexec/postfix/aliasesdb' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecStartPre=-/usr/libexec/postfix/chroot-update' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecStart=/usr/sbin/postfix start' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecReload=/usr/sbin/postfix reload' >> /usr/lib/systemd/system/postfix.service
        echo 'ExecStop=/usr/sbin/postfix stop' >> /usr/lib/systemd/system/postfix.service
        echo '' >> /usr/lib/systemd/system/postfix.service
        echo '[Install]' >> /usr/lib/systemd/system/postfix.service
        echo 'WantedBy=multi-user.target' >> /usr/lib/systemd/system/postfix.service
        echo '' >> /usr/lib/systemd/system/postfix.service
        /usr/bin/systemctl daemon-reload
        POSTFIXRESTART=1
    fi
fi

# If Postfix is enabled check if we need to restart it:
if [ -f /etc/sysconfig/bxmta ];then
    POSTFIXENABLED=`cat /etc/sysconfig/bxmta|grep ^MTA=POSTFIX|wc -l`
    if [ $POSTFIXENABLED = "1" ]; then
        if [ "$POSTFIXRESTART" = "1" ];then
            /usr/bin/systemctl restart postfix.service
        fi
    fi
fi

# Epel:
# 
# People don't learn. If Epel is present, we disable it. Am sick of fixing broken boxes:
#
if [ -f /etc/yum.repos.d/epel.repo ];then
    sed -i -e 's|enabled=1|enabled=0|' /etc/yum.repos.d/epel.repo
fi

# Directory permissions/ownership assurance for base-alpine dirs:

if [ ! -d /usr/sausalito/license ];then 
  mkdir /usr/sausalito/license 
fi

if [ -d /usr/sausalito/license ];then 
  chmod 700 /usr/sausalito/license/ 
  chown admserv:admserv /usr/sausalito/license
fi

if [ ! -d /usr/sausalito/capcache ];then 
    mkdir /usr/sausalito/capcache 
fi
if [ -d /usr/sausalito/capcache ];then 
    chmod 700 /usr/sausalito/capcache/ 
    chown admserv:admserv /usr/sausalito/capcache
fi

if [ ! -d /usr/sausalito/sessions ];then 
    mkdir /usr/sausalito/sessions 
fi

if [ -d /usr/sausalito/sessions ];then
    chmod 700 /usr/sausalito/sessions/ 
    chown admserv:admserv /usr/sausalito/sessions
fi

if [ -d /usr/sausalito/ui/chorizo/ci4/writable ];then
  chown -R admserv:admserv /usr/sausalito/ui/chorizo/ci4/writable
fi

if [ ! -f /var/log/gui-debug.log ];then
  touch /var/log/gui-debug.log
fi

if [ -f /var/log/gui-debug.log ];then
  chown admserv:admserv /var/log/gui-debug.log
fi

# Create and/or fix Login page brute force registry file:
if [ ! -f /usr/sausalito/sessions/.gui-invalid-login-attempts ];then
    touch /usr/sausalito/sessions/.gui-invalid-login-attempts
    chown admserv:admserv /usr/sausalito/sessions/.gui-invalid-login-attempts
    chmod 660 /usr/sausalito/sessions/.gui-invalid-login-attempts
fi
if [ -f /usr/sausalito/sessions/.gui-invalid-login-attempts ];then
    chown admserv:admserv /usr/sausalito/sessions/.gui-invalid-login-attempts
    chmod 660 /usr/sausalito/sessions/.gui-invalid-login-attempts
fi

# Fix /etc/httpd/conf.d/blueonyx.conf if needed (remove /login redirects):
CONF_FILE="/etc/httpd/conf.d/blueonyx.conf"
if grep -q "^Rewrite" "$CONF_FILE"; then
    # Create backup:
    cp "$CONF_FILE" "$CONF_FILE.bak"
    sed -i '/^Rewrite/d' "$CONF_FILE"
    /usr/bin/systemctl reload httpd &>/dev/null || :
fi

# Fix /etc/httpd/conf/vhosts/preview if needed (remove /login redirects):
PRECONF_FILE="/etc/httpd/conf/vhosts/preview"
if [ -f /etc/httpd/conf/vhosts/preview ];then
    if grep -q "^Rewrite" "$PRECONF_FILE"; then
        # Create backup:
        cp "$PRECONF_FILE" "$PRECONF_FILE.bak"
        sed -i '/^Rewrite/d' "$PRECONF_FILE"
        /usr/bin/systemctl reload httpd &>/dev/null || :
    fi
fi

# Tell NetworkManager to leave the frigging server name as it is:
NMCONF_FILE="/etc/NetworkManager/NetworkManager.conf"
MAIN_SECTION="[main]"
HOSTNAME_MODE="hostname-mode=preserve"
# Check if the file contains the [main] section
if grep -q "$MAIN_SECTION" "$NMCONF_FILE"; then
    # Check if hostname-mode=preserve is already set
    if ! grep -q "$HOSTNAME_MODE" "$NMCONF_FILE"; then
        # It's not present; we need to add it
        # Create a backup before modifying
        cp "$NMCONF_FILE" "$NMCONF_FILE.bak"
        # Use awk to add hostname-mode=preserve right after [main] section
        awk -v line="$HOSTNAME_MODE" '/^\[main\]/ { print; print line; next }1' "$NMCONF_FILE" > temp_file && mv temp_file "$NMCONF_FILE"
        /usr/bin/systemctl reload NetworkManager &>/dev/null || :
    fi
fi

#
### Fixing "GLIBC Vulnerability on Servers Serving PHP" (CVE-2024-2961) via Hotfix:
#

# Check for the presence of ISO-2022-CN-EXT in the list of encodings
encoding_count=$(iconv -l | grep -E 'CN-?EXT' | wc -l)

if [ "$encoding_count" -gt 0 ]; then
    echo "Fixing CVE-2024-2961 'GLIBC Vulnerability on Servers Serving PHP'"

    # Specify the file path
    file_path="/usr/lib64/gconv/gconv-modules.d/gconv-modules-extra.conf"

    # Check if the file exists
    if [ -f "$file_path" ]; then

        # Using sed to delete lines containing 'ISO-2022-CN-EXT'
        sed -i '/ISO-2022-CN-EXT/d' "$file_path"

        # Save the current directory
        original_dir=$(pwd)

        # Change to the directory where gconv-modules are located
        cd /usr/lib64/gconv
        
        # Run iconvconfig:
        /usr/sbin/iconvconfig

        # Change back to the original directory
        cd "$original_dir"
    fi
fi

# =============================================
# CVE-2026-31431 (copy.fail) – Layered Mitigation
# Persistent: grubby initcall_blacklist=af_alg_init
# Immediate:  eBPF socket filter (from blueonyx-cve-2026-31431-ebpf RPM)
# Fully idempotent – safe for every 15-min Active Monitor run
# =============================================
#
# NOTE on kernel version dependency:
# The xfrm-ESP page-cache write vulnerability exploited by Dirty Frag and
# Copy Fail 2 requires MSG_SPLICE_PAGES UDP support (kernel >= 6.5) AND
# the ESP no-COW fast path (cac2661c53f3, kernel >= 4.10). Systems running
# kernel < 6.5 (AlmaLinux 9, RHEL 9) are NOT affected by the xfrm-ESP path.
# The RxRPC path of Dirty Frag does not apply on AlmaLinux (no rxrpc.ko).
# The mitigation below is harmless on unaffected kernels (modules won't load
# anyway), but the grubby args are still applied for pre-protection after
# kernel upgrades.

AF_FIX="initcall_blacklist=af_alg_init"

IS_CONTAINER=0
if command -v systemd-detect-virt >/dev/null 2>&1 && systemd-detect-virt --container --quiet; then
    IS_CONTAINER=1
elif grep -qaE 'container=|lxc|incus' /proc/1/environ /proc/1/cgroup 2>/dev/null; then
    IS_CONTAINER=1
fi

# --- 1. Persistent fix: only meaningful on real host/VM ---
if [ "$IS_CONTAINER" -eq 0 ]; then
    if command -v grubby >/dev/null 2>&1; then
        if ! grep -q "$AF_FIX" /proc/cmdline 2>/dev/null && \
           ! grubby --info=ALL 2>/dev/null | grep -q "$AF_FIX"; then

            echo "Applying persistent CVE-2026-31431 mitigation..."
            if grubby --update-kernel=ALL --args="$AF_FIX"; then
                logger -t cve-2026-31431-hotfix "initcall_blacklist=af_alg_init applied to all kernels"
                echo "✅ Persistent AF_ALG block staged (reboot required for full effect)" >&2
            else
                logger -t cve-2026-31431-hotfix "ERROR: failed to apply grubby mitigation"
                echo "ERROR: Failed to apply persistent AF_ALG block" >&2
            fi
        fi
    else
        logger -t cve-2026-31431-hotfix "grubby not found; persistent mitigation skipped"
    fi
else
    # Avoid cron spam inside Incus/LXC containers.
    :
fi

# --- 2. Immediate eBPF protection: skip in containers ---
if [ "$IS_CONTAINER" -eq 0 ]; then
    if command -v /usr/sbin/cve-2026-31431-ebpf >/dev/null 2>&1; then
        if ! /usr/sbin/cve-2026-31431-ebpf status 2>/dev/null | grep -q "Active"; then
            echo "Applying immediate eBPF AF_ALG blocker..."

            if /usr/sbin/cve-2026-31431-ebpf load; then
                logger -t cve-2026-31431-hotfix "eBPF AF_ALG socket filter loaded"
                echo "✅ Immediate eBPF protection activated (AF_ALG is now blocked)" >&2
            else
                logger -t cve-2026-31431-hotfix "ERROR: failed to load eBPF AF_ALG socket filter"
                echo "ERROR: Failed to activate immediate eBPF protection" >&2
            fi
        fi
    else
        echo "Warning: RPM blueonyx-cve-2026-31431-ebpf package not installed" >&2
    fi
fi

# =============================================
# CVE-2026-XXXXX (Dirty Frag / Copy Fail 2) – esp4/esp6 block
# The xfrm-ESP page-cache write (CVE-2026-XXXXX) is exploited by:
#   - Dirty Frag (chains xfrm-ESP + RxRPC page-cache writes)
#   - Copy Fail 2 (xfrm ESP-in-UDP MSG_SPLICE_PAGES no-COW fast path)
# Both require esp4.ko / esp6.ko auto-loaded by netlink from a user namespace.
# Blacklisting these modules prevents the exploit even with userns enabled.
# Fully idempotent – safe for every 15-min Active Monitor run.
# =============================================

LCE2_CONF="/etc/modprobe.d/blueonyx-cve-lce.conf"
LCE2_VAR="modprobe.blacklist=esp4,esp6"

if [ "$IS_CONTAINER" -eq 0 ]; then

    # --- 1. Immediate modprobe.d block: prevent autoload from user namespace ---
    if [ ! -f "$LCE2_CONF" ] || ! grep -q "install esp4 /bin/false" "$LCE2_CONF" 2>/dev/null; then
        printf 'install esp4 /bin/false\ninstall esp6 /bin/false\n' > "$LCE2_CONF"
        logger -t cve-lce-hotfix "esp4/esp6 modprobe blacklist installed"
    fi

    # Unload if currently loaded (idempotent; normally not loaded on BlueOnyx)
    rmmod esp4 esp6 2>/dev/null || true

    # --- 2. Persistent grubby block: survives reboot and kernel updates ---
    if command -v grubby >/dev/null 2>&1; then
        if ! grep -q "modprobe.blacklist=esp4" /proc/cmdline 2>/dev/null && \
           ! grubby --info=ALL 2>/dev/null | grep -q "$LCE2_VAR"; then

            echo "Applying persistent Dirty Frag / Copy Fail 2 mitigation..."
            if grubby --update-kernel=ALL --args="$LCE2_VAR"; then
                logger -t cve-lce-hotfix "modprobe.blacklist=esp4,esp6 applied to all kernels"
                echo "Persistent esp4/esp6 block staged (reboot required for full effect)" >&2
            else
                logger -t cve-lce-hotfix "ERROR: failed to apply grubby mitigation for esp4/esp6"
                echo "ERROR: Failed to apply persistent esp4/esp6 block" >&2
            fi
        fi
    else
        logger -t cve-lce-hotfix "grubby not found; persistent esp4/esp6 mitigation skipped"
    fi
else
    # Inside Incus/LXC containers: module blacklist is managed by host, skip.
    :
fi

#
### ScriptAlias /cgi-bin/ fix in httpd.conf:
#

# Define the path to the configuration file
config_file="/etc/httpd/conf/httpd.conf"

# Define the line to search for
search_line="    ScriptAlias /cgi-bin/ \"/var/www/cgi-bin/\""

# Check if the line exists and is not already commented out
if grep -q "^${search_line}" "$config_file"; then
    sed -i "s|^${search_line}|#&|" "$config_file"
fi

#
### Delete debugbar files created by GUI set to 'development':
#

rm -f /usr/sausalito/ui/chorizo/ci4/writable/debugbar/debugbar_*.json

#
### Hostname fixer:
#

if [ -f /usr/sausalito/sbin/sync_hostname.pl ];then
    /usr/sausalito/sbin/sync_hostname.pl &>/dev/null || :
fi

#
### AV-SPAM SpamHaus disabler:
#

# Define the directory and file paths
SA_DIR="/etc/mail/spamassassin"
SA_FILE="$SA_DIR/zz_disable_spamhaus.cf"

# Check if the directory exists and the file does not exist
if [[ -d "$SA_DIR" && ! -f "$SA_FILE" ]]; then
    # Create the file with the specified permissions and content
    cat <<EOL > "$SA_FILE"
score RCVD_IN_VALIDITY_CERTIFIED_BLOCKED 0.0
score RCVD_IN_VALIDITY_RPBL_BLOCKED 0.0
score RCVD_IN_ZEN_BLOCKED_OPENDNS 0.0
score URIBL_DBL_BLOCKED_OPENDNS 0.0
score URIBL_ZEN_BLOCKED_OPENDNS 0.0
score URIBL_SBLXBL 0.0
score SBLXBL_SPAM 0.0
score URIBL_DBL 0.0
EOL

    # Set the permissions to 644
    chmod 644 "$SA_FILE"

    # Restart the necessary services
    /usr/bin/systemctl restart spamassassin spamass-milter
fi

#
### Last rites before exit:
#

# Check if restart or rehash files exists and are older than 38 minutes
restart_file="/usr/sausalito/yumcce/restart"
rehash_file="/usr/sausalito/yumcce/rehash"
if [ -f "$restart_file" ] && [ $(find "$restart_file" -mmin +38) ]; then
    echo "Restart file exists and is older than 38 minutes"
    # Restart CCEd:
    /usr/sausalito/sbin/cced.init restart
    rm -f /usr/sausalito/yumcce/restart
    rm -f /usr/sausalito/yumcce/rehash
elif [ -f "$rehash_file" ] && [ $(find "$rehash_file" -mmin +38) ]; then
    echo "Rehash file exists and is older than 38 minutes"
    # Rehash CCEd:
    /usr/sausalito/sbin/cced.init rehash
    rm -f /usr/sausalito/yumcce/rehash
fi

# End:
exit

#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#
# 1. Redistributions of source code must retain the above copyright
#   notice, this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#   notice, this list of conditions and the following disclaimer in
#   the documentation and/or other materials provided with the
#   distribution.
#
# 3. Neither the name of the copyright holder nor the names of its
#   contributors may be used to endorse or promote products derived
#   from this software without specific prior written permission.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.
#
# You acknowledge that this software is not designed or intended for
# use in the design, construction, operation or maintenance of any
# nuclear facility.
#
