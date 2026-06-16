<?php
/**
 * I18n.php
 *
 * BlueOnyx I18n for Codeigniter
 *
 * @package   I18n
 * @author    Michael Stauber, Kevin K.M. Chiu
 * @link      http://www.blueonyx.it
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   3.0
 *
 * Notes:     This Class reads in the locales from  /usr/share/locale/<language>/LC_MESSAGES/
 *            and given the 'msgid' it returns the 'msgstr' that this locale provides. We face
 *            several problems here which also have to do with PHP oddities:
 *            - File format of our *.po locale files is UTF-8.
 *            - Character set defined in out *.po locale files is UTF-8.
 *
 *            Still: I18n treats the locales as if they were in ISO-8859-1 format if php.ini
 *            (/etc/admserv/phi.ini) doesn't have the following set:
 *
 *            default_charset = "utf-8"
 *            exif.encode_unicode = UTF-8
 *            iconv.input_encoding = "UTF-8"
 *            iconv.internal_encoding = "UTF-8"
 *            iconv.output_encoding  = "UTF-8"
 *
 *            Even then it is hit and miss and we have to do some funky conversions.
 *            So as a work around we shove non Japanese locales through the new Class
 *            /uifc/BXEncoding.php from Sebastián Grignoli to convert the results to UTF-8 for
 *            all non-Japanese languages. For Japanese we return the Encoding as provided by
 *            mb_convert_encoding() to create results in UTF-8 charset.
 * 
 *            Note: This version checks if PHP has i18n.so and if so it uses it. If PHP doesn't
 *            have i18n.so support, then it falls back to use I18nNative.php instead.
 */

// used by encodeString
require('EncodingConv.php');
require('I18nExtension.php');
require('I18nNative.php');

//
// private class variables
//
class I18n {
    //
    // private variables
    //
    var $handle;
    var $domain;
    var $NATIVE;

    public $i18nNative;

    // Locale Cache (Experimental - may be slower and is therefore disabled)
    private $cacheDir = '/usr/sausalito/capcache/i18nCache';
    private $cacheEnabled = false;  // Set to true to enable locale caching
    private $cacheExpiry = 4 * 60 * 60; // Expire cache files after four hours

    // description: constructor
    // param: domain: a string that describes the domain
    // param: langs: an optional string that contains a comma separated list
    //     of preferred locale. Most important locales appears first.
    //     e.g. "en_US, en_AU, zh, de_DE"
    //function I18nMain($domain = "", $langs = "") {
    public function __construct($domain = "base-alpine", $langs = "en_US") {
        if (!is_array($langs)) {
            $my_lang[] = $langs;
        }

        $known_languages = array(
            'da_DK',
            'de_DE',
            'en_US',
            'es_ES',
            'fr_FR',
            'it_IT',
            'ja_JP',
            'pt_PT',
            'nl_NL'
        );

        $foundlang = '0';
        foreach ($my_lang as $key) {
            if (in_array($key, $known_languages)) {
                $foundlang = '1';
            }
        }

        if ($foundlang != '1') {
            $langs = "en_US";
        }

        // Ensure that the cache directory exists
        if (!file_exists($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
                // Handle the case where the directory creation failed
                die('Failed to create cache directory ' . $this->cacheDir);
            }
        }

        if (function_exists('i18n_new')) {
            $this->setNative(false);
            $this->i18nNative = new I18nExtension();
        }
        else {
            $this->setNative(true);
            $this->i18nNative = new I18nNative();
        }
        $this->handle = $this->i18nNative->i18n_new($domain, $langs);
        $this->domain = $domain;
    }

    //
    // public methods
    //
    function setNative($CM) {
        $this->NATIVE = $CM;
    }

    function getNative() {
        return $this->NATIVE;
    }

    /**
     *
     *  Simple function to detect if a string is UTF-8 or not.
     *
     */

    function detectUTF8($string) {
        return preg_match('%(?:
          [\xC2-\xDF][\x80-\xBF]                # non-overlong 2-byte
          |\xE0[\xA0-\xBF][\x80-\xBF]           # excluding overlongs
          |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}    # straight 3-byte
          |\xED[\x80-\x9F][\x80-\xBF]           # excluding surrogates
          |\xF0[\x90-\xBF][\x80-\xBF]{2}        # planes 1-3
          |[\xF1-\xF3][\x80-\xBF]{3}            # planes 4-15
          |\xF4[\x80-\x8F][\x80-\xBF]{2}        # plane 16
          )+%xs', $string);
    }

    function Utf8Encode($text) {
        if (mb_detect_encoding($text, "JIS, UTF-8, EUC-JP, ISO-8859-1, ISO-8859-15, windows-1252") == "EUC-JP") {
            $text = mb_convert_encoding($text, "UTF-8", "EUC-JP");
        }
        if (I18n::detectUTF8($text) == "1") {
            return $text;
        }
        return BXEncoding::toUTF8($text);
    }

    // description: get a localized string
    // param: tag: the tag of the string. Identical to the msgid string in the
    //     .po file
    // param: domain: the domain of the string in string. Identical to the
    //     .po/.mo file name without the extension. Optional. If not supplied,
    //     the one supplied to the I18n constructor is used
    // param: vars: a hash of variable key strings to value strings. Optional.
    //     If the hash contains "name" => "Kevin" and the string in question is
    //     "My name is [[VAR.name]]", then "My name is Kevin" is returned
    // returns: a localized string if it is found or the tag otherwise

    function get($tag, $domain = "", $vars = array()) {

        // Return early on empty $tag:
        if (($tag === '') || ($tag === '_help')) {
            return '';
        }

        // Check if caching is enabled
        if ($this->cacheEnabled) {
            // Clear expired cache files before fetching the translation
            $this->clearExpiredCache();
        }

        if ($this->cacheEnabled) {
            // Generate a unique cache key for this translation
            $cacheKey = $this->getCacheKey($tag, $domain, $vars);

            // Attempt to get translation from cache
            $cachedTranslations = $this->getCachedTranslation($cacheKey);

            if ($cachedTranslations !== false) {
                return $cachedTranslations;
            }
        }

        // If not found in cache, fetch the translation
        if (preg_match('/\[\[_swupdate:(.*)+\.(name|nameTag)\]\]/', $tag, $matches)) {

            //
            //-- NewLinQ PKGs only have en_US:
            //

            // Get current (desired) language:
            $gui_lang = $this->i18nNative->getLanguage();

            // Switch to 'en_US':
            $this->i18nNative->SetLanguage('en_US');

            // Translate to 'en_US':
            $out_txt = $this->i18nNative->i18n_get($tag, $domain, $vars);

            // Reset to desired language:
            $this->i18nNative->SetLanguage($gui_lang);
        }
        else {
            // Blindly translate to desired language:
            $out_txt = $this->i18nNative->i18n_get($tag, $domain, $vars);
        }

        if (mb_check_encoding($out_txt, "utf8") == TRUE ){
            return $out_txt;
        }

        $ini_langs = $this->initializeLanguagesIfNeeded();
        if ((detectUTF8($out_txt) == "1") || ($ini_langs['locale'] == "ja_JP")) {
            $out_txt_clean = mb_convert_encoding($out_txt, "UTF-8", "EUC-JP");
        }
        else {
            $out_txt_clean = BXEncoding::toUTF8($out_txt);
        }

        // Cache the translation for future use
        if ($this->cacheEnabled) {
            $this->cacheTranslation($cacheKey, $out_txt);
        }

        return $out_txt_clean;
    }

    // Mostly used for Tooltips. Returns a wrapped and htmlsave localization string.
    // Note: Cannot and will not wrap Japanese.
    function getWrapped($tag, $domain = "", $vars = array()) {

        // Return early on empty $tag:
        if (($tag === '') || ($tag === '_help')) {
            return '';
        }

        // Shortcut: getWrapped is mostly used for Tooltips and Elmer auto-wraps tooltips.
        // Worse: This function also adds <br> tags, so we straight up redirect calls to
        // getWrapped() to a simple get() instead if Elmer is used:
        $CI = &get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        if ($BX_SESSION['gui_theme'] === 'elmer') {
            return $this->get($tag, $domain, $vars);
        }

        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        $out_txt = $this->i18nNative->i18n_get($tag, $domain, $vars);
        if (mb_check_encoding($out_txt, "utf8") == true) {
            $out_txt_clean = $out_txt;
        }
        else {
            if ((detectUTF8($out_txt) == "1") || ($ini_langs['locale'] == "ja_JP")) {
                $out_txt_clean = mb_convert_encoding($out_txt, "UTF-8", "EUC-JP");
            }
            else {
                $out_txt_clean = BXEncoding::toUTF8($out_txt);
            }
        }

        // If it's already short enough, then we don't need to wrap it:
        if (strlen($out_txt_clean) <= 75) {
            return $out_txt_clean;
        }

        if ($ini_langs['locale'] == "ja_JP") {
            // We can't word wrap Japanese without creating some undesired results.
            // Se we simply don't word wrap it and just replace hard returns:
            $folded = ja_wordwrap($out_txt_clean, 75);
            $transwebbed = str_replace("\n", "<br>", $folded);
            $transwebbed = str_replace('"', "'", $transwebbed);
            return $transwebbed;
        }
        else {
            $out_txt_clean = str_replace('"', "'", $out_txt_clean);
            $translated = @htmlentities(html_entity_decode(htmlspecialchars_decode($out_txt_clean, ENT_QUOTES) , ENT_QUOTES) , ENT_QUOTES, $ini_langs['localecharset']);
            $transwebbed = word_wrap($translated, 75);
            $transwrapped = str_replace("\n", "<br>", $transwebbed);
            return $transwrapped;
        }
    }

    // Used for places where we need an unwrapped localization where all erronous HTML code
    // is transformed into something formsafe. For example for pulldowns and similar.
    function getClean($tag, $domain = "", $vars = array()) {

        // Return early on empty $tag:
        if (($tag === '') || ($tag === '_help')) {
            return '';
        }

        $out_text = $this->i18nNative->i18n_get($tag, $domain, $vars);
        $out_text_clean = html_entity_decode(htmlspecialchars_decode($out_text, ENT_QUOTES) , ENT_QUOTES);
        if (mb_check_encoding($out_text_clean, "utf8") == true) {
            return $out_text_clean;
        }

        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if ((detectUTF8($out_text_clean) == "1") || ($ini_langs['locale'] == "ja_JP")) {
            return mb_convert_encoding($out_text_clean, "UTF-8", "EUC-JP");
        }
        else {
            return BXEncoding::toUTF8($out_text_clean);
        }
    }

    // description: get a localized string and encode it into Javascript friendly
    //     encoding
    // param: domain: the domain of the string in string. Identical to the
    //     .po/.mo file name without the extension. Optional. If not supplied,
    //     the one supplied to the I18n constructor is used
    // param: vars: a hash of variable key strings to value strings. Optional.
    //     If the hash contains "name" => "Kevin" and the string in question is
    //     "My name is [[VAR.name]]", then "My name is Kevin" is returned
    // returns: a Javacsript friendly localized string if it is found or the tag
    //     otherwise
    function getJs($tag, $domain = "", $vars = array()) {

        // Return early on empty $tag:
        if (($tag === '') || ($tag === '_help')) {
            return '';
        }

        $out_text = $this->i18nNative->i18n_get_js($tag, $domain, $vars);
        $out_text_clean = html_entity_decode(htmlspecialchars_decode($out_text, ENT_QUOTES) , ENT_QUOTES);
        if (mb_check_encoding($out_text_clean, "utf8") == true) {
            return $out_text_clean;
        }
        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if ((detectUTF8($out_text_clean) == "1") || ($ini_langs['locale'] == "ja_JP")) {
            return mb_convert_encoding($out_text_clean, "UTF-8", "EUC-JP");
        }
        else {
            return BXEncoding::toUTF8($out_text_clean);
        }
    }

    // description: get a localized string and encode it into HTML friendly
    //     encoding
    // param: tag: the tag of the string. Identical to the msgid string in the
    //     .po file
    // param: domain: the domain of the string in string. Identical to the
    //     .po/.mo file name without the extension. Optional. If not supplied,
    //     the one supplied to the I18n constructor is used
    // param: vars: a hash of variable key strings to value strings. Optional.
    //     If the hash contains "name" => "Kevin" and the string in question is
    //     "My name is [[VAR.name]]", then "My name is Kevin" is returned
    // returns: a HTML friendly localized string if it is found or the tag
    //     otherwise
    function getHtml($tag, $domain = "", $vars = array()) {

        // Return early on empty $tag:
        if (($tag === '') || ($tag === '_help')) {
            return '';
        }

        $out_txt = $this->i18nNative->i18n_get_html($tag, $domain, $vars);
        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if (mb_check_encoding($out_txt, "utf8") == true) {
            $out_txt_clean = $out_txt;
        }
        else {
            if ((detectUTF8($out_txt) == "1") || ($ini_langs['locale'] == "ja_JP")) {
                $out_txt_clean = mb_convert_encoding($out_txt, "UTF-8", "EUC-JP");
            }
            else {
                $out_txt_clean = BXEncoding::toUTF8($out_txt);
            }
        }

        // New: This is outright stuuuuuuuuuuupid! i18n_get_html() doesn't return HTML. Far from it!
        // Additionally we may have single quotes and/or HTML escaped entities in our locales. That
        // totally messes up tooltips if we present them escaped in single quotes. So we do the insane
        // thing of passing the return of i18n_get_html() first through htmlspecialchars_decode(),
        // then through html_entity_decode() and finally through htmlentities() to get all the crap
        // properly escaped in correct HTML code.
        // return htmlentities(html_entity_decode(htmlspecialchars_decode($out_txt_clean, ENT_QUOTES) , ENT_QUOTES) , ENT_QUOTES | ENT_IGNORE, $ini_langs['localecharset']);
        return htmlentities(htmlspecialchars_decode($out_txt_clean, ENT_QUOTES), ENT_QUOTES | ENT_IGNORE, $ini_langs['localecharset']);

    }

    // description: get a localized string out of a fully qualified tag
    // param: magicstr: the fully qualified tag of format
    //     "[[" . <domain> . "." . <tag> (. "," . <key> . "=" . <value>)* . "]]"
    // param: vars: a hash of variable key strings to value strings. Optional
    // returns: a localized string or magicstr if interpolation failed
    function interpolate($magicstr, $vars = array()) {
        $out_text = $this->i18nNative->i18n_interpolate($magicstr, $vars);
        $out_text_clean = html_entity_decode(htmlspecialchars_decode($out_text, ENT_QUOTES) , ENT_QUOTES);
        if (mb_check_encoding($out_text_clean, "utf8") == true) {
            return $out_text_clean;
        }
        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if ((detectUTF8($out_text_clean) == "1") || ($ini_langs['locale'] == "ja_JP")) {
            return mb_convert_encoding($out_text_clean, "UTF-8", "EUC-JP");
        }
        else {
            return BXEncoding::toUTF8($out_text_clean);
        }
    }

    // description: get a localized string out of a fully qualified tag and
    //     encode it into Javascript friendly encoding
    // param: magicstr: the fully qualified tag of format
    //     "[[" . <domain> . "." . <tag> (. "," . <key> . "=" . <value>)* . "]]"
    // param: vars: a hash of variable key strings to value strings. Optional
    // returns: a Javacsript friendly localized string or magicstr if
    //     interpolation failed
    function interpolateJs($magicstr, $vars = array()) {
        $out_text = $this->i18nNative->i18n_interpolate_js($magicstr, $vars);
        $out_text_clean = html_entity_decode(htmlspecialchars_decode($out_text, ENT_QUOTES) , ENT_QUOTES);
        if (mb_check_encoding($out_text_clean, "utf8") == true) {
            return $out_text_clean;
        }
        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if ((detectUTF8($out_text_clean) == "1") || ($ini_langs['locale'] == "ja_JP")) {
            return mb_convert_encoding($out_text_clean, "UTF-8", "EUC-JP");
        }
        else {
            return BXEncoding::toUTF8($out_text_clean);
        }
    }

    // description: get a localized string out of a fully qualified tag and
    //     encode it into HTML friendly encoding
    // param: magicstr: the fully qualified tag of format
    //     "[[" . <domain> . "." . <tag> (. "," . <key> . "=" . <value>)* . "]]"
    // param: vars: a hash of variable key strings to value strings. Optional
    // returns: a HTMl friendly localized string or magicstr if
    //     interpolation failed
    function interpolateHtml($magicstr, $vars = array()) {
        $out_text = $this->i18nNative->i18n_interpolate_html($magicstr, $vars);
        $ini_langs = $this->initializeLanguagesIfNeeded();
        if (($ini_langs['locale'] == '') || ($ini_langs['charset'] == '')) {
            $ini_langs = initialize_languages(false);
        }
        if (mb_check_encoding($out_text, "utf8") == true) {
            $out_txt_clean = $out_text;
        }
        else {
            if ((detectUTF8($out_text) == "1") || ($ini_langs['locale'] == "ja_JP")) {
                $out_txt_clean = mb_convert_encoding($out_text, "UTF-8", "EUC-JP");
            }
            else {
                $out_txt_clean = BXEncoding::toUTF8($out_text);
            }
        }

        // New: This is outright stuuuuuuuuuuupid! i18n_get_html() doesn't return HTML. Far from it!
        // Additionally we may have single quotes and/or HTML escaped entities in our locales. That
        // totally messes up tooltips if we present them escaped in single quotes. So we do the insane
        // thing of passing the return of i18n_get_html() first through htmlspecialchars_decode(),
        // then through html_entity_decode() and finally through htmlentities() to get all the crap
        // properly escaped in correct HTML code.
        return htmlentities(html_entity_decode(htmlspecialchars_decode($out_txt_clean, ENT_QUOTES) , ENT_QUOTES) , ENT_QUOTES | ENT_IGNORE, $ini_langs['localecharset']);
    }

    // description: get a property value from the property file
    //     /usr/share/locale/<locale>/<domain>.prop. Properties are defined as
    //     "<name>: <value>\n" in the file. One line for each property. Comments
    //     starts with "#"
    // param: property: the name of the property in string
    // param: domain: the domain of the property in string. Optional. If not
    //     supplied, the one supplied to I18n constructor is used.
    // param: langs: an optional string that contains a comma separated list
    //     of preferred locale. Most important locales appears first.
    //     e.g. "en_US, en_AU, zh, de_DE". Optional. If not supplied, the one
    //     supplied to I18n constructor is used.
    function getProperty($property, $domain = "", $lang = "en_US") {
        if (function_exists('i18n_new')) {
            $ret = new I18nExtension();
            $ret->i18n_new($domain, $lang ?: 'en_US');
        }
        else {
            $ret = new I18nNative();
        }
        $out = $ret->i18n_get_property($property, $domain, $lang);
        return $out;
    }

    // descrption: get the path of the file of the most suitable locale
    //     For example, if "/logo.gif" is supplied, locale "ja" is preferred and
    //     "/logo.gif", "/logo.gif.en" and "/logo.gif.ja" are available,
    //     "/logo.gif.ja" is returned
    // param: file: the full path of the file in question
    // returns: the full path of the file of the most suitable locale
    function getFile($file) {
        return $file;
    }

    // description: get a list of available locales for a domain or everything
    //     on the system
    // param: domain: i18n domain in string. Optional
    // returns: an array of locale strings
    function getAvailableLocales($domain = "") {
        // Same shit as getLocales() one function below:
        return i18nNative::i18n_locales($domain);
    }

    // description: get a list of negotiated locales
    // param: domain: i18n domain in string. Optional
    // returns: an array of locale strings
    //     The first one being to most important and so on
    function getLocales($domain = "") {
        if (function_exists('i18n_new')) {
            $ret = new I18nExtension();
            $ret->i18n_new($domain, $this->i18nNative->getLanguage());
        }
        else {
            $ret = new I18nNative();
        }
        $out = $ret->i18n_locales($domain);
        return $out;
    }

    // description: wrapper to strftime()
    // param: format: the format parameter to strftime()
    // param: time: the epochal time
    // returns: a strftime() formatted string
    function strftime($format = "", $time = 0) {
        return i18n_strftime($this->handle, $format, $time);
    }

    /*
    * Encode a string properly based on the current locale.
    * args:
    *  string to encode
    *  To encoding (optional).  Encoding to convert string to.
    *  From encoding (optional).  Assume the passed in string is in
    *  this encoding rather than determining the encoding
    *  automatically.
    *  locale (optional) to encode for.  If not specified, the locale
    *  of the I18n object is used.
    *  is used.
    
    *
    * returns:
    *  The encoded string if successful.
    *  boolean false if there is an error.
    */
    function encodeString($string, $toEncoding = '', $fromEncoding = '', $locale = '') {
        if ($locale == '') {
            $locales = $this->getLocales();
            if ($locales[0] == '') {
                return false;
            }
            $locale = $locales[0];
        }

        // this is kind of a hack, but at least it hides the hack here.
        if (preg_match("/^ja/i", $locale)) {
            $encConv = new EncodingConv($string, 'japanese', $fromEncoding);
            if ($toEncoding == '') {
                return $encConv->toEUC();
            }
            else {
                return $encConv->doJapanese($string, $toEncoding, $fromEncoding);
            }
        }
        else {
            /*
             * okay, so this is only japanese for now, but one day
             * this could be useful for unicode
            */
            return $string;
        }
    }

    // Initialize the languages just once:
    private function initializeLanguagesIfNeeded() {
        $CI = &get_instance();
        $ini_langs = $CI->getBX_ini_langs();

        if ($ini_langs['locale'] == '' || $ini_langs['charset'] == '') {
            $ini_langs = initialize_languages(false);
        }

        return $ini_langs;
    }

    // Use a unique key for each set of translations (considering different locales, domains, etc.)
    private function getCacheKey($tag, $domain, $vars) {
        // Create a unique key based on the tag, domain, and variable values
        return md5(json_encode([$tag, $domain, $vars]));
    }

    // Fetch a matching cached translation if there is one:
    private function getCachedTranslation($cacheKey) {
        $cacheFile = $this->cacheDir . '/' . $cacheKey;
        if (file_exists($cacheFile)) {
            $cachedData = file_get_contents($cacheFile);
            $translations = unserialize($cachedData);
            return $translations;
        }
        return false;
    }

    // Cache translation:
    private function cacheTranslation($cacheKey, $translations) {
        $cacheFile = $this->cacheDir . '/' . $cacheKey;
        $cachedData = serialize($translations);
        file_put_contents($cacheFile, $cachedData);
    }

    // Method to remove cache files older than four hours
    private function clearExpiredCache() {
        $cacheFiles = glob($this->cacheDir . '/*');

        foreach ($cacheFiles as $cacheFile) {
            if (time() - filemtime($cacheFile) >= $this->cacheExpiry) {
                // Cache file is older than four hours, remove it
                unlink($cacheFile);
            }
        }
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
Copyright (c) 2003 Sun Microsystems, Inc. 
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
