#!/bin/sh
# test the CPU / I/O-wait state
# Tim Hockin
# Extended: distinguish CPU-bound load from I/O wait (D-state inflates loadavg).

. /usr/sausalito/swatch/statecodes

# bc needs C numeric locale
LC_NUMERIC=C
export LC_NUMERIC

# set defaults (CCE properties are exported as ENV by swatch)
if [ -z "$rThreshhold" ]; then
	rThreshhold="6.0"
fi
if [ -z "$yThreshhold" ]; then
	yThreshhold="4.0"
fi
if [ -z "$ioYellow" ]; then
	ioYellow="25"
fi
if [ -z "$ioRed" ]; then
	ioRed="40"
fi

# acquire load averages
read AVG1 AVG5 AVG15 REST < /proc/loadavg

# Two /proc/stat samples, 1s apart: user nice system idle iowait irq softirq steal
cpu_fields() {
	awk '/^cpu / {
		printf "%s %s %s %s %s %s %s %s\n", $2+0,$3+0,$4+0,$5+0,$6+0,$7+0,$8+0,$9+0
		exit
	}' /proc/stat
}

set -- $(cpu_fields)
U1=$1 N1=$2 SY1=$3 ID1=$4 WA1=$5 HI1=$6 SI1=$7 ST1=$8
sleep 1
set -- $(cpu_fields)
U2=$1 N2=$2 SY2=$3 ID2=$4 WA2=$5 HI2=$6 SI2=$7 ST2=$8

# Percentages from the 1s delta
eval $(awk -v u1="$U1" -v n1="$N1" -v sy1="$SY1" -v id1="$ID1" -v wa1="$WA1" \
	-v hi1="$HI1" -v si1="$SI1" -v st1="$ST1" \
	-v u2="$U2" -v n2="$N2" -v sy2="$SY2" -v id2="$ID2" -v wa2="$WA2" \
	-v hi2="$HI2" -v si2="$SI2" -v st2="$ST2" 'BEGIN {
	du=u2-u1; dn=n2-n1; dsy=sy2-sy1; did=id2-id1; dwa=wa2-wa1
	dhi=hi2-hi1; dsi=si2-si1; dst=st2-st1
	if (du<0) du=0; if (dn<0) dn=0; if (dsy<0) dsy=0; if (did<0) did=0
	if (dwa<0) dwa=0; if (dhi<0) dhi=0; if (dsi<0) dsi=0; if (dst<0) dst=0
	tot=du+dn+dsy+did+dwa+dhi+dsi+dst
	if (tot<1) tot=1
	printf "US=%.1f\nSY=%.1f\nID=%.1f\nWA=%.1f\n",
		(du+dn)*100/tot, (dsy+dhi+dsi)*100/tot, did*100/tot, dwa*100/tot
}')

bc_ge() {
	# $1 >= $2 ? 1 : 0
	echo "$1 >= $2" | bc -l
}

LOAD_RED=0
LOAD_YELLOW=0
if [ "$(bc_ge "$AVG15" "$rThreshhold")" = "1" -o \
     "$(bc_ge "$AVG1" "$(echo "$rThreshhold * 2" | bc -l)")" = "1" ]; then
	LOAD_RED=1
elif [ "$(bc_ge "$AVG15" "$yThreshhold")" = "1" -o \
       "$(bc_ge "$AVG1" "$(echo "$yThreshhold * 2" | bc -l)")" = "1" ]; then
	LOAD_YELLOW=1
fi

if [ "$LOAD_RED" = "0" -a "$LOAD_YELLOW" = "0" ]; then
	echo -ne "[[base-am.amCPUWarning_light]]"
	exit $AM_STATE_GREEN
fi

CPU_BUSY=$(echo "$US + $SY" | bc -l)
IO_YEL=$(bc_ge "$WA" "$ioYellow")
IO_RED=$(bc_ge "$WA" "$ioRed")
WA_GE_CPU=$(bc_ge "$WA" "$CPU_BUSY")
CPU_GE_20=$(bc_ge "$CPU_BUSY" "20")

# I/O-bound: wait dominates CPU time. Mixed: both I/O wait and CPU are material.
CAUSE="cpu"
if [ "$IO_RED" = "1" ]; then
	# Severe iowait dominates the diagnosis even if CPU is also busy.
	CAUSE="iowait"
elif [ "$IO_YEL" = "1" -a "$WA_GE_CPU" = "1" ]; then
	CAUSE="iowait"
elif [ "$IO_YEL" = "1" -a "$CPU_GE_20" = "1" ]; then
	CAUSE="mixed"
fi

if [ "$LOAD_RED" = "1" ]; then
	if [ "$CAUSE" = "iowait" ]; then
		echo -ne "[[base-am.amCPUWarning_heavy_iowait]]"
	elif [ "$CAUSE" = "mixed" ]; then
		echo -ne "[[base-am.amCPUWarning_heavy_mixed]]"
	else
		echo -ne "[[base-am.amCPUWarning_heavy]]"
	fi
	exit $AM_STATE_RED
fi

if [ "$CAUSE" = "iowait" ]; then
	echo -ne "[[base-am.amCPUWarning_moderate_iowait]]"
elif [ "$CAUSE" = "mixed" ]; then
	echo -ne "[[base-am.amCPUWarning_moderate_mixed]]"
else
	echo -ne "[[base-am.amCPUWarning_moderate]]"
fi
exit $AM_STATE_YELLOW
# Copyright (c) 2003 Sun Microsystems, Inc. All  Rights Reserved.
# Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET / Team BlueOnyx
# 
# Redistribution and use in source and binary forms, with or without 
# modification, are permitted provided that the following conditions are met:
# 
# -Redistribution of source code must retain the above copyright notice, 
# this list of conditions and the following disclaimer.
# 
# -Redistribution in binary form must reproduce the above copyright notice, 
# this list of conditions and the following disclaimer in the documentation  
# and/or other materials provided with the distribution.
# 
# Neither the name of Sun Microsystems, Inc. or the names of contributors may 
# be used to endorse or promote products derived from this software without 
# specific prior written permission.
# 
# This software is provided "AS IS," without a warranty of any kind. ALL EXPRESS OR IMPLIED CONDITIONS, REPRESENTATIONS AND WARRANTIES, INCLUDING ANY IMPLIED WARRANTY OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE OR NON-INFRINGEMENT, ARE HEREBY EXCLUDED. SUN MICROSYSTEMS, INC. ("SUN") AND ITS LICENSORS SHALL NOT BE LIABLE FOR ANY DAMAGES SUFFERED BY LICENSEE AS A RESULT OF USING, MODIFYING OR DISTRIBUTING THIS SOFTWARE OR ITS DERIVATIVES. IN NO EVENT WILL SUN OR ITS LICENSORS BE LIABLE FOR ANY LOST REVENUE, PROFIT OR DATA, OR FOR DIRECT, INDIRECT, SPECIAL, CONSEQUENTIAL, INCIDENTAL OR PUNITIVE DAMAGES, HOWEVER CAUSED AND REGARDLESS OF THE THEORY OF LIABILITY, ARISING OUT OF THE USE OF OR INABILITY TO USE THIS SOFTWARE, EVEN IF SUN HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.
# 
# You acknowledge that  this software is not designed or intended for use in the design, construction, operation or maintenance of any nuclear facility.
