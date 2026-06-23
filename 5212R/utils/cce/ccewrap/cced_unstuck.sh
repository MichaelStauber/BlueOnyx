#!/usr/bin/bash
# This shell script is used to kill off and restart
# a stuck CCEd instance fast and more or less relieably.
# At least as best is possible.
/usr/bin/killall -9 /usr/sausalito/sbin/cced &>/dev/null || :
/usr/bin/killall -9 pperld &>/dev/null || :
/usr/bin/killall -9 cced.init &>/dev/null || :
/usr/bin/killall -9 cced &>/dev/null || :
/bin/ps aux | /bin/grep cced | /bin/grep -v cced_unstuck | /bin/grep -v kill | /bin/grep -v grep | /bin/awk '{print $2}' | /usr/bin/xargs -I {} kill -9 {} >/dev/null 2>&1
/usr/sausalito/sbin/cced.init rehash &>/dev/null || :
#systemctl restart cced.init.service &>/dev/null || :

# Also restart cced-api. When CCEd was stuck or restarted, cced-api may
# have crashed and systemd may have given up restarting it. A fresh
# restart here ensures the GUI's HTTP/JSON proxy to CCEd is available
# again, which is essential for getAll() and other API-dependent calls.
/usr/bin/systemctl restart cced-api.service &>/dev/null || :

exit 0
