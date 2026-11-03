#!/usr/bin/bash
# $Id: yum-update.sh

export TERM=dumb

# Configurable delay (in seconds)
DELAY_MIN=10
DELAY_MAX=300
SKIP_DELAY=0

# Check if --now is passed
if [[ "$1" == "--now" ]]; then
    SKIP_DELAY=1
fi

# Random delay if inside Incus and not overridden
if [[ -e /dev/incus/sock && $SKIP_DELAY -eq 0 ]]; then
    DELAY=$(( RANDOM % (DELAY_MAX - DELAY_MIN + 1) + DELAY_MIN ))
    sleep $DELAY
fi

/bin/touch /var/log/yum.log
/bin/chmod 644 /var/log/yum.log
/bin/touch /tmp/yum.updating
/bin/rm -f /tmp/yum.check-update

# Fake Sauce::Service restart event for /gui/service (pending yum update).
# Atomic publish (Greg Kuhnert PR #4): temp + rename so sauce_serviced never
# sees a truncated file and deletes it as "older than allowed".
QUEUE_DIR=/usr/sausalito/services
if [ -d "$QUEUE_DIR" ]; then
    TMPFILE=$(mktemp "$QUEUE_DIR/.yum.XXXXXXXX")
    if [ -n "$TMPFILE" ]; then
        if printf 'restart\n%s\n' "$(/bin/date +%s)" > "$TMPFILE"; then
            /bin/chmod 600 "$TMPFILE"
            /bin/mv -f "$TMPFILE" "$QUEUE_DIR/yum"
        else
            /bin/rm -f "$TMPFILE"
        fi
    fi
fi

/usr/bin/dnf clean all &>/dev/null || :
LC_ALL=C /usr/bin/dnf -y update > /tmp/yum.update

if [ -f /etc/yumgui.conf ]; then
    source /etc/yumgui.conf
    EMAILRECIPIENT=$MAILTO

    grep -q "Nothing to do." /tmp/yum.update
    if [ $? -gt 0 ]; then
        /bin/cat /tmp/yum.update | /bin/sed 's/\r//' | /bin/mail -s "`/bin/hostname` Yum Update output for `/bin/date +\%m`-`/bin/date +\%d`-`/bin/date +\%y`" $EMAILRECIPIENT
    fi
fi

/usr/bin/dnf --exclude=base-maillist* --exclude=majordomo --exclude=mailman --exclude base-mailman* -y groupinstall blueonyx &>/dev/null || :

LC_ALL=C /usr/bin/dnf check-update > /tmp/yum.check-update
/bin/rm -f /tmp/yum.updating
/bin/rm -f /usr/sausalito/services/yum

# Various permission fixes:
/bin/chmod 644 /var/log/yum.log
# /var/lib/php/session: OS default 0770 root:apache via hotfixes.sh / tmpfiles (Ken Marcus)
# Do NOT chmod 777 here.

exit 0

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2006 Brian N. Smith, NuOnce Networks, Inc.
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
