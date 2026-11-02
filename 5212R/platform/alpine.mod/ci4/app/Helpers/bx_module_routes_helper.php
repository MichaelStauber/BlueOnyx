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
 * - Skipped modules: register placeholder routes from chorizo menu XML so clicks
 *   show an upgrade notice instead of a bare 404.
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

if (! function_exists('bx_module_routes_registry')) {
    /**
     * Request-scoped registry of skipped shop modules and placeholder URLs.
     *
     * @return array{
     *   modules: array<string, array{vendor:string,module:string,routes_php:string,label:string}>,
     *   urls: array<string, array{vendor:string,module:string,label:string,menu_id:string}>
     * }
     */
    function &bx_module_routes_registry(): array
    {
        static $reg = [
            'modules' => [],
            'urls'    => [],
        ];

        return $reg;
    }
}

if (! function_exists('bx_module_routes_parse_module_path')) {
    /**
     * Modules/Solarspeed/Firewall/Config/Routes.php -> vendor/module.
     *
     * @return array{vendor:string,module:string}|null
     */
    function bx_module_routes_parse_module_path(string $pathname): ?array
    {
        $norm = str_replace('\\', '/', $pathname);
        if (! preg_match('#/Modules/([^/]+)/([^/]+)/Config/Routes\.php$#i', $norm, $m)) {
            return null;
        }

        return [
            'vendor' => $m[1],
            'module' => $m[2],
        ];
    }
}

if (! function_exists('bx_module_routes_menu_roots')) {
    /**
     * @return list<string>
     */
    function bx_module_routes_menu_roots(): array
    {
        $roots = [
            '/usr/sausalito/ui/chorizo/menu',
            '/usr/sausalito/ui/menu',
        ];
        if (defined('ROOTPATH')) {
            $cand = rtrim(str_replace('\\', '/', (string) ROOTPATH), '/') . '/../menu';
            $real = realpath($cand);
            if ($real !== false) {
                array_unshift($roots, $real);
            }
        }

        return array_values(array_unique(array_filter($roots, 'is_dir')));
    }
}

if (! function_exists('bx_module_routes_menu_dirs_for')) {
    /**
     * Likely chorizo menu directories for a skipped CI module.
     *
     * @return list<string> absolute dirs that exist
     */
    function bx_module_routes_menu_dirs_for(string $vendor, string $module): array
    {
        $v = strtolower($vendor);
        $m = strtolower($module);
        // Easy_backup -> easy_backup; Fail2ban -> fail2ban
        $m_us = str_replace('-', '_', $m);
        $m_dash = str_replace('_', '-', $m);

        $candidates = array_unique([
            $v . '/' . $m,
            $v . '/' . $m_us,
            $v . '/' . $m_dash,
            // Compass-base style sometimes nests differently
            $v . '/' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $module)),
        ]);

        $dirs = [];
        foreach (bx_module_routes_menu_roots() as $root) {
            foreach ($candidates as $rel) {
                $path = $root . '/' . $rel;
                if (is_dir($path)) {
                    $dirs[] = $path;
                }
            }
        }

        return array_values(array_unique($dirs));
    }
}

if (! function_exists('bx_module_routes_collect_menu_urls')) {
    /**
     * Parse url="..." (and url='...') from all XML files under menu dirs.
     *
     * @param list<string> $menuDirs
     * @return array<string, array{label:string,menu_id:string}> path => meta
     */
    function bx_module_routes_collect_menu_urls(array $menuDirs): array
    {
        $out = [];
        foreach ($menuDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
            } catch (Throwable $e) {
                continue;
            }
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (! preg_match('/\.xml$/i', $name)) {
                    continue;
                }
                $raw = @file_get_contents($file->getPathname());
                if ($raw === false || $raw === '') {
                    continue;
                }
                // id / label / url on <item ...>
                if (! preg_match_all('/<item\b([^>]*)>/is', $raw, $items, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($items as $item) {
                    $attrs = $item[1];
                    $url = '';
                    if (preg_match('/\burl\s*=\s*"([^"]+)"/i', $attrs, $um)) {
                        $url = trim($um[1]);
                    } elseif (preg_match("/\burl\s*=\s*'([^']+)'/i", $attrs, $um)) {
                        $url = trim($um[1]);
                    }
                    if ($url === '' || $url === '#' || stripos($url, 'javascript:') === 0) {
                        continue;
                    }
                    // external http(s) links are not Chorizo controllers
                    if (preg_match('#^https?://#i', $url)) {
                        continue;
                    }
                    $path = bx_module_routes_normalize_uri($url);
                    if ($path === '' || $path === '/') {
                        continue;
                    }
                    $label = '';
                    if (preg_match('/\blabel\s*=\s*"([^"]*)"/i', $attrs, $lm)) {
                        $label = $lm[1];
                    }
                    $menuId = '';
                    if (preg_match('/\bid\s*=\s*"([^"]*)"/i', $attrs, $im)) {
                        $menuId = $im[1];
                    }
                    if (! isset($out[$path])) {
                        $out[$path] = [
                            'label'   => $label,
                            'menu_id' => $menuId,
                        ];
                    }
                }
            }
        }

        return $out;
    }
}

if (! function_exists('bx_module_routes_normalize_uri')) {
    /**
     * /firewall/firewallconfig?x=1 -> firewall/firewallconfig (no leading slash; CI route style)
     */
    function bx_module_routes_normalize_uri(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // strip scheme/host if somehow present
        if (preg_match('#^https?://[^/]+(/.*)$#i', $url, $m)) {
            $url = $m[1];
        }
        $parts = parse_url($url);
        $path = isset($parts['path']) ? (string) $parts['path'] : $url;
        $path = rawurldecode($path);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        // collapse //
        $path = preg_replace('#/+#', '/', $path);
        // CI $routes->add() typically without leading slash
        return trim($path, '/');
    }
}

if (! function_exists('bx_module_routes_record_skip')) {
    /**
     * Remember a skipped Routes.php and harvest menu URLs for placeholders.
     */
    function bx_module_routes_record_skip(string $pathname): void
    {
        $parsed = bx_module_routes_parse_module_path($pathname);
        if ($parsed === null) {
            return;
        }
        $vendor = $parsed['vendor'];
        $module = $parsed['module'];
        $key = $vendor . '/' . $module;
        $reg = &bx_module_routes_registry();

        $label = $module;
        $menuDirs = bx_module_routes_menu_dirs_for($vendor, $module);
        $urls = bx_module_routes_collect_menu_urls($menuDirs);

        // If convention missed, scan vendor menu tree for urls that look related
        if ($urls === [] && $menuDirs === []) {
            foreach (bx_module_routes_menu_roots() as $root) {
                $vendorDir = $root . '/' . strtolower($vendor);
                if (is_dir($vendorDir)) {
                    $urls = array_merge($urls, bx_module_routes_collect_menu_urls([$vendorDir]));
                }
            }
            // keep only paths whose first segment resembles module name
            $modSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $module));
            $filtered = [];
            foreach ($urls as $path => $meta) {
                $first = strtolower(explode('/', $path, 2)[0] ?? '');
                $firstSlug = preg_replace('/[^a-z0-9]+/i', '', $first);
                if ($firstSlug !== '' && ($firstSlug === $modSlug || str_contains($modSlug, $firstSlug) || str_contains($firstSlug, $modSlug))) {
                    $filtered[$path] = $meta;
                }
            }
            $urls = $filtered;
        }

        foreach ($urls as $path => $meta) {
            if ($meta['label'] !== '') {
                $label = $meta['label'];
                break;
            }
        }

        $reg['modules'][$key] = [
            'vendor'     => $vendor,
            'module'     => $module,
            'routes_php' => $pathname,
            'label'      => $label,
        ];

        foreach ($urls as $path => $meta) {
            $reg['urls'][$path] = [
                'vendor'  => $vendor,
                'module'  => $module,
                'label'   => $meta['label'] !== '' ? $meta['label'] : $label,
                'menu_id' => $meta['menu_id'],
            ];
        }
    }
}

if (! function_exists('bx_module_routes_require_safe')) {
    /**
     * require() a module Routes.php or skip with a single log line + registry.
     */
    function bx_module_routes_require_safe(string $pathname): bool
    {
        if (bx_module_routes_should_skip($pathname)) {
            // One line for operators; do not break GUI boot.
            error_log(
                'alpine: skipping ionCube Routes.php incompatible with PHP '
                . PHP_VERSION . ': ' . $pathname
            );
            bx_module_routes_record_skip($pathname);

            return false;
        }

        require $pathname;

        return true;
    }
}

if (! function_exists('bx_module_routes_is_placeholder_uri')) {
    /**
     * @param string $uri path with or without leading slash, query stripped by caller
     */
    function bx_module_routes_is_placeholder_uri(string $uri): bool
    {
        $path = bx_module_routes_normalize_uri($uri);
        $reg = &bx_module_routes_registry();

        return $path !== '' && isset($reg['urls'][$path]);
    }
}

if (! function_exists('bx_module_routes_placeholder_meta')) {
    /**
     * @return array{vendor:string,module:string,label:string,menu_id:string}|null
     */
    function bx_module_routes_placeholder_meta(string $uri): ?array
    {
        $path = bx_module_routes_normalize_uri($uri);
        $reg = &bx_module_routes_registry();

        return $reg['urls'][$path] ?? null;
    }
}

if (! function_exists('bx_module_routes_register_placeholders')) {
    /**
     * Map skipped module menu URLs to the upgrade-notice controller.
     *
     * @param object $routes CodeIgniter RouteCollection
     */
    function bx_module_routes_register_placeholders($routes): void
    {
        $reg = &bx_module_routes_registry();
        if ($reg['urls'] === []) {
            return;
        }

        $handler = 'Gui\Controllers\ShopModuleUpgrade::index';
        foreach (array_keys($reg['urls']) as $path) {
            // Exact menu targets (modules often have many XMLs / many URLs).
            $routes->add($path, $handler);
            // Common trailing-slash variant
            $routes->add($path . '/', $handler);
        }
    }
}
