#!/usr/bin/bash
export DEBUG=1
export LANG=en_US
export LC_ALL=en_US.UTF-8
export LINGUAS="en_US ja da_DK de_DE"
exec=/usr/sausalito/sbin/swatch.sh
FIND=`which find`
XARGS=`which xargs`
TOUCH=`which touch`
REM=`which rm`
CCEDUP=`/usr/sausalito/bin/check_cce.pl`
lockdir=/var/lock/subsys/swatch.lock
cce_lockfile='/var/lock/subsys/cce_construct.lock';

SKIP_DELAY=0
if [[ "$1" == "--no-delay" ]]; then
    SKIP_DELAY=1
fi

function debug {
    if [ $DEBUG -gt 0 ]; then
        /usr/bin/logger "***** swatch: $1"
    fi
}

if [ -f $cce_lockfile ];then
    debug "CCE Constructors are still running. Exiting for now. Trying again later."
    exit
fi

# Locking mechanism
if mkdir "$lockdir" 2>/dev/null; then
    trap 'rmdir "$lockdir"' EXIT
else
    debug "Another instance of the script is running. Exiting."
    exit 1
fi

# Run fix_syslog.sh:
debug "Running fix_syslog.sh"
/usr/sausalito/sbin/fix_syslog.sh

# Wait for CODB to be created on first boot if required
# We will assume we need a dozen objects or more
debug "Checking that CODB exists"
if [ -e /usr/sausalito/codb ]; then
    OBJCOUNT=`ls /usr/sausalito/codb/objects/ | wc -l`
    if [ $OBJCOUNT -gt 5 ]; then
        debug "Running check_cce.pl"
        if [ "$CCEDUP" != "SUCCESS" ];then
            debug "Running cced_unstuck.sh"
            /usr/sausalito/bin/cced_unstuck.sh >/dev/null 2>&1
            sleep 5
        fi
    fi
fi

# Pause to wait for constructors to stop running - max of 5 minutes
debug "Waiting for constructors to finish"
TIMEOUT=300
WAITFOR=cce_construct
pgrep -f $WAITFOR > /dev/null
while [ $? -eq 0 -a $TIMEOUT -gt 0 ]; do
    debug "Sleeping"
    sleep 1
    ((TIMEOUT--))
    pgrep -f $WAITFOR > /dev/null
done
debug "Constructors all finished"

# Only on Incus: Introduce a random delay of 0-180 seconds before running hotfixes.sh and swatch:
if [ -e /dev/.incus-mounts -a $SKIP_DELAY -eq 0 ]; then
    # Maximum delay in seconds
    MAX_DELAY=180

    # Generate a random delay between 0 and MAX_DELAY seconds
    RANDOM_DELAY=$(( RANDOM % MAX_DELAY ))

    # Inform the user about the delay
    echo "Introducing a random delay of $RANDOM_DELAY seconds before executing ..."

    # Pause execution for the random duration
    sleep $RANDOM_DELAY
fi

# Run hotfix script:
debug "Running hotfixes"
/usr/sausalito/sbin/hotfixes.sh

debug "Running Swatch"
/usr/sbin/swatch -c /etc/swatch.conf >/dev/null 2>&1

# Enable Swatch service
if [ -f /usr/bin/systemctl ]; then 
  if [ ! -f /usr/lib/systemd/system/swatch.service ];then 
    cp /usr/sausalito/swatch/swatch.service /usr/lib/systemd/system/swatch.service 
    systemctl daemon-reload >/dev/null 2>&1 || : 
    systemctl enable swatch.service >/dev/null 2>&1 || : 
  fi 
fi 

debug "Swatch run complete"
exit
