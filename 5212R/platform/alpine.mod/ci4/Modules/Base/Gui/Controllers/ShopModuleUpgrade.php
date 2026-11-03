<?php

namespace Gui\Controllers;

use App\Controllers\BaseController;

/**
 * Placeholder page for shop modules whose ionCube Routes.php were skipped
 * (encode_81 / license-bound builds on AdmServ PHP 8.2+).
 */
class ShopModuleUpgrade extends BaseController
{
    public function __construct()
    {
    }

    public function index()
    {
        helper(['form']);

        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'] ?? 'en_US';
        $localization = $BX_SESSION['localization'] ?? 'en_US';
        $charset = $BX_SESSION['charset'] ?? 'UTF-8';

        $uri = '';
        try {
            $uri = (string) uri_string();
        } catch (\Throwable $e) {
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        }

        $meta = null;
        if (function_exists('bx_module_routes_placeholder_meta')) {
            $meta = bx_module_routes_placeholder_meta($uri);
        }
        if ($meta === null && function_exists('bx_module_routes_placeholder_meta')) {
            // REQUEST_URI fallback
            $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $meta = bx_module_routes_placeholder_meta($req);
        }

        $moduleLabel = '';
        if (is_array($meta)) {
            $moduleLabel = (string) ($meta['module'] ?? '');
            if ($moduleLabel === '' && isset($meta['label'])) {
                $moduleLabel = (string) $meta['label'];
            }
        }

        // Prefer palette/base-alpine i18n when present; fall back to fixed EN.
        $title = 'Module update required';
        $heading = 'Module update required';
        $text = 'Please upgrade this module by installing the latest version from the BlueOnyx Shop.';
        if ($moduleLabel !== '') {
            $text .= ' (' . $moduleLabel . ')';
        }

        if (function_exists('PoorMansBabelFish')) {
            $tTitle = PoorMansBabelFish('shop_module_upgrade_title', $locale, 'base-alpine');
            $tText = PoorMansBabelFish('shop_module_upgrade_text', $locale, 'base-alpine');
            // PoorMansBabelFish often returns the key if missing — only use real strings.
            if (is_string($tTitle) && $tTitle !== '' && $tTitle !== 'shop_module_upgrade_title' && strpos($tTitle, 'shop_module_upgrade_') === false) {
                $title = $tTitle;
                $heading = $tTitle;
            }
            if (is_string($tText) && $tText !== '' && $tText !== 'shop_module_upgrade_text' && strpos($tText, 'shop_module_upgrade_') === false) {
                $text = $tText;
                if ($moduleLabel !== '') {
                    $text .= ' (' . $moduleLabel . ')';
                }
            }
        }

        $this->response->setStatusCode(200);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');

        $page_variables = [
            'localization'   => $localization,
            'charset'        => $charset,
            'page_title'     => $title,
            'bx_logo_color'  => '#000000',
            'elmer_style_css'=> '/.elm/dist/css/style.css',
            'extra_headers'  => '',
            'heading'        => $heading,
            'text'           => $text,
            'extra_footers'  => '',
        ];

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
    }
}
