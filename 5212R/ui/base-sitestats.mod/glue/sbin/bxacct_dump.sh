#!/bin/bash
#
# Dump nft table inet bxacct for the Sitestats Network GUI.
# Must run as root (via ServerScriptHelper).
#
export LC_ALL=C
export LANG=C
NFT=/usr/sbin/nft
if [ ! -x "$NFT" ]; then
    NFT=$(command -v nft) || exit 0
fi
$NFT list table inet bxacct 2>/dev/null || true
exit 0
