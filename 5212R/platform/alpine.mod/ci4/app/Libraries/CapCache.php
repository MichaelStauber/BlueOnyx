<?php

/**
 * CapCache.php
 *
 * Read/write/validate layer for the per-user capability-group cache in
 * /usr/sausalito/capcache.
 *
 * Historically the cache was a bare JSON map of capability groups: no
 * timestamp, no invalidation. A snapshot written while the CCEd constructors
 * were still creating the CapabilityGroups therefore stayed "valid" forever,
 * even though it was missing groups that appeared a few seconds later. If
 * "adminUser" is missing from such a snapshot, the GUI's menu logic denies
 * administrative access and bounces the operator between /wizard and
 * /user/personalAccount - an endless redirect loop on a fresh install.
 *
 * The format is now:
 *
 *   { "ts": <unixtime>, "oids": [<int>, ...], "groups": { "<name>": {...}, ... } }
 *
 * A cache is only accepted when the stamp is fresh AND the cached OID set still
 * equals the OIDs that currently exist in CCE. Everything else is rebuilt.
 */

class CapCache {

    const DIRECTORY = '/usr/sausalito/capcache';
    const TTL       = 900;

    // Bumped whenever the meaning of a cached snapshot changes. Snapshots
    // written by an older build are rejected instead of trusted - the
    // previous versions could store a partial group list next to the full
    // OID list, which passed every other check.
    const FORMAT    = 2;

    /**
     * Memoised result of setupWindow() for this request.
     *
     * @var bool|null
     */
    private static $_setupWindow = NULL;

    /**
     * Absolute path of the cache file for a given login name.
     */
    public static function path($loginName) {
        return self::DIRECTORY . '/' . $loginName . '_capabilityGroups';
    }

    /**
     * Removes the cache file for a login name, if it exists.
     */
    public static function drop($loginName) {
        $file = self::path($loginName);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * TRUE while the CCEd constructors are still building the objects we need:
     *
     *   (a) the System object does not exist yet, or has no isLicenseAccepted,
     *   (b) the license has not been accepted yet (web based setup pending).
     *
     * Both states mean a snapshot of the CapabilityGroups cannot be trusted and
     * must neither be read nor written. Condition (c) - "adminUser still cannot
     * be resolved" - is a property of the data and is checked by the caller
     * before writing.
     */
    public static function setupWindow($cce) {
        if (self::$_setupWindow !== NULL) {
            return self::$_setupWindow;
        }

        $system = NULL;
        try {
            $system = $cce->getObject('System');
        }
        catch (\Throwable $e) {
            $system = NULL;
        }

        if (!is_array($system) || count($system) == 0 || !isset($system['isLicenseAccepted'])) {
            self::$_setupWindow = TRUE;
            return TRUE;
        }

        self::$_setupWindow = ($system['isLicenseAccepted'] == '0');
        return self::$_setupWindow;
    }

    /**
     * Returns the cached groups, or FALSE when the cache must be rebuilt.
     *
     * @param string $loginName
     * @param array  $currentOids OIDs of the CapabilityGroups that exist now
     *
     * @return array|false
     */
    public static function load($loginName, $currentOids) {
        $file = self::path($loginName);

        if (!is_file($file)) {
            return FALSE;
        }

        $raw = @file_get_contents($file);
        if (($raw === FALSE) || ($raw === '')) {
            self::drop($loginName);
            return FALSE;
        }

        $data = json_decode($raw, TRUE);

        // Legacy format (bare map, no stamp), an older format version or a
        // garbled file: rebuild. The version check is what invalidates the
        // partial snapshots older builds may have written.
        if (!is_array($data) || !isset($data['v']) || ((int)$data['v'] !== self::FORMAT) || !isset($data['ts']) || !isset($data['oids']) || !isset($data['groups']) || !is_array($data['oids']) || !is_array($data['groups'])) {
            self::drop($loginName);
            return FALSE;
        }

        if ((time() - (int)$data['ts']) > self::TTL) {
            return FALSE;
        }

        $cached = array_map('intval', array_values($data['oids']));
        $now    = array_map('intval', array_values((array)$currentOids));
        sort($cached);
        sort($now);

        if ($cached !== $now) {
            return FALSE;
        }

        if (count($data['groups']) == 0) {
            return FALSE;
        }

        return $data['groups'];
    }

    /**
     * Writes the snapshot atomically (temp file + rename), so a concurrent
     * reader never sees a half written file.
     *
     * @return bool
     */
    public static function save($loginName, $groups, $currentOids) {
        if (!is_array($groups) || count($groups) == 0) {
            return FALSE;
        }

        $file = self::path($loginName);

        $payload = json_encode(array(
            'v'      => self::FORMAT,
            'ts'     => time(),
            'oids'   => array_map('intval', array_values((array)$currentOids)),
            'groups' => $groups,
        ));

        if ($payload === FALSE) {
            return FALSE;
        }

        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload) === FALSE) {
            @unlink($tmp);
            return FALSE;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return FALSE;
        }

        return TRUE;
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>