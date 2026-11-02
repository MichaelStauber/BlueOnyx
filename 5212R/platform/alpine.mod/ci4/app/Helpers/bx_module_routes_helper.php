<?php

/**
 * Safe loading of Chorizo module Config/Routes.php files.
 *
 * Shop UI packages were historically ionCube-encoded with the PHP 8.1 encoder
 * and optional license binding (encode_81.sh + solarspeed_generic.key).
 * AdmServ on newer lines may run PHP 8.3/8.4: requiring those Routes.php files
 * fatals the entire GUI during boot (YUM base update must not brick boxes that
 * still have old shop RPMs installed).
 *
 * Policy:
 * - Plain (non-ionCube) Routes.php: always load.
 * - ionCube with ICB0 targets including 8.3 or 8.4: load (5212 encode_83 style).
 * - ionCube 8.1-only / legacy //00xxx license-era encodes: skip when PHP >= 8.2.
 * - On PHP 8.1 AdmServ: still load 8.1 encodes (legacy behaviour).
 */

if (! function_exists('bx_module_routes_is_ioncube')) {
    /**
     * @return bool True if the file header looks ionCube-encoded.
     */
    function bx_module_routes_is_ioncube(string $head): bool
    {
        if ($head === '') {
            return false;
        }
        if (stripos($head, 'ionCube') !== false) {
            return true;
        }
        if (strpos($head, 'ICB0') !== false) {
            return true;
        }
        // Legacy short marker: "<?php //0030b" / "//0038e" etc.
        if (preg_match('/<\?php\s*\/\/00[0-9a-f]{3}\b/i', $head)) {
            return true;
        }

        return false;
    }
}

if (! function_exists('bx_module_routes_icb0_targets')) {
    /**
     * Parse ICB0 version tokens from a file header, e.g. "81:0 83:10b7" -> [81, 83].
     *
     * @return list<int>
     */
    function bx_module_routes_icb0_targets(string $head): array
    {
        if (! preg_match('/ICB0\s+([^?\r\n]+)/', $head, $m)) {
            return [];
        }
        $targets = [];
        if (preg_match_all('/\b(8[0-9]|7[0-9]|5[0-9]):/i', $m[1], $tm)) {
            foreach ($tm[1] as $maj) {
                $targets[] = (int) $maj;
            }
        }

        return array_values(array_unique($targets));
    }
}

if (! function_exists('bx_module_routes_should_skip')) {
    /**
     * Whether this module Routes.php must not be require()'d on this PHP.
     */
    function bx_module_routes_should_skip(string $pathname): bool
    {
        if (! is_readable($pathname)) {
            return true;
        }

        $fh = @fopen($pathname, 'rb');
        if ($fh === false) {
            return true;
        }
        $head = (string) fread($fh, 8192);
        fclose($fh);

        if (! bx_module_routes_is_ioncube($head)) {
            return false;
        }

        // PHP 8.1 AdmServ: keep loading historic 8.1 shop encodes.
        if (PHP_VERSION_ID < 80200) {
            return false;
        }

        $targets = bx_module_routes_icb0_targets($head);

        // Multi/newer target line (encode_83 and similar): allow if 8.2+ present.
        if ($targets !== []) {
            foreach ($targets as $t) {
                if ($t >= 82) {
                    return false;
                }
            }
            // ICB0 only lists 8.1 (or older) -> unsafe on 8.2+.
            return true;
        }

        // No ICB0 multi-target block: legacy single-generation encode
        // (typical encode_81.sh + --with-license output, "//0030b" style).
        // Those require a matching license key and 8.1 runtime - skip on 8.2+.
        return true;
    }
}

if (! function_exists('bx_module_routes_require_safe')) {
    /**
     * require() a module Routes.php or skip with a single log line.
     */
    function bx_module_routes_require_safe(string $pathname): bool
    {
        if (bx_module_routes_should_skip($pathname)) {
            // One line for operators; do not break GUI boot.
            error_log(
                'alpine: skipping ionCube Routes.php incompatible with PHP '
                . PHP_VERSION . ': ' . $pathname
            );

            return false;
        }

        require $pathname;

        return true;
    }
}
