#!/bin/bash
#
# BlueOnyx Chorizo / CodeIgniter 4 overlay restore
# ================================================
#
# After composer/vendor or stock project-file upgrades, restore the BlueOnyx
# "sacred" app overlays from the *.bx / Filters.php.csrf sidecars shipped in
# this tree. Sidecars must always match the known-good live overlays
# (cookie bx_session, runtime Routes + module discovery, registerModules
# Autoload, vendor system Paths, App baseURL constructor, CSRF filters).
#
# Do NOT point sidecars at stock CI defaults. A stale Session.php.bx with
# ci_session will break the GUI after "successful" upgrades.
#
# Optional:
#   BX_CI4_ROOT=/path  - install prefix (default: /usr/sausalito/ui/chorizo/ci4)
#   BX_CI4_FIXER_DRY=1 - print actions only
#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#
# 1. Redistributions of source code must retain the above copyright
#    notice, this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#    notice, this list of conditions and the following disclaimer in
#    the documentation and/or other materials provided with the
#    distribution.
#
# 3. Neither the name of the copyright holder nor the names of its
#    contributors may be used to endorse or promote products derived
#    from this software without specific prior written permission.
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

set -euo pipefail

CI4_ROOT="${BX_CI4_ROOT:-/usr/sausalito/ui/chorizo/ci4}"
CFG="${CI4_ROOT}/app/Config"
VIEWS_ERR="${CI4_ROOT}/app/Views/errors/html"
DRY="${BX_CI4_FIXER_DRY:-0}"

restore_file() {
	# restore_file <sidecar> <target>
	local src="$1"
	local dst="$2"
	if [ ! -f "$src" ]; then
		echo "bx_ci4_fixer: skip (missing sidecar): $src" >&2
		return 0
	fi
	if [ "$DRY" = "1" ]; then
		echo "bx_ci4_fixer: DRY would restore $src -> $dst"
		return 0
	fi
	cp -f "$src" "$dst"
	echo "bx_ci4_fixer: restored $(basename "$dst") from $(basename "$src")"
}

if [ ! -d "$CFG" ]; then
	echo "bx_ci4_fixer: Config dir not found: $CFG" >&2
	exit 1
fi

# Sacred Config overlays (runtime routing stays in Routes.php + module Routes)
restore_file "${CFG}/Autoload.php.bx"   "${CFG}/Autoload.php"
restore_file "${CFG}/Routes.php.bx"     "${CFG}/Routes.php"
restore_file "${CFG}/Session.php.bx"    "${CFG}/Session.php"
restore_file "${CFG}/App.php.bx"        "${CFG}/App.php"
restore_file "${CFG}/Paths.php.bx"      "${CFG}/Paths.php"

# Default CSRF-on filter set (Filters.php.nocsrf is the alternate, not auto-applied)
restore_file "${CFG}/Filters.php.csrf"  "${CFG}/Filters.php"

# Production error view (BX branded / quiet)
restore_file "${VIEWS_ERR}/production.php.bx" "${VIEWS_ERR}/production.php"

# Historical note: system Autoloader.php vendor patch is no longer required
# when Autoload.php.bx carries registerModules().

# Sanity: session cookie must remain bx_session after restore
if [ -f "${CFG}/Session.php" ] && ! grep -q "cookieName = 'bx_session'" "${CFG}/Session.php"; then
	echo "bx_ci4_fixer: WARNING: Session.php cookieName is not bx_session after restore" >&2
	exit 2
fi

exit 0
