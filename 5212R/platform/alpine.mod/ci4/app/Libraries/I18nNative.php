<?php
/**
 * I18nNative.php
 *
 * BlueOnyx Native I18n Internationalization Class for Codeigniter
 *
 * @package   I18nNative
 * @author    Michael Stauber
 * @link      http://www.blueonyx.it
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   3.0
 *
 * Description:
 * ============
 *
 * So far BlueOnyx (and the predecessors BlueQuartz and the RaQ550) used a loadable PHP
 * Zend API module to supply PHP with capability to handle Internationalization of displayed
 * text. The reason for this is that the i18n support in PHP itself was in its infancy when
 * the Sausalito GUI was initially designed. The drawback of this is naturally that the
 * PHP module must be compatible to the PHP version that AdmServ uses and it must be compiled
 * against said PHP version. Upgrading PHP then (naturally) was out of the question as it
 * would have required a recompile of the 'i18n.so' module again.
 *
 * Now with PHP-5.4 (and later) we have the problem that the Zend API for modules has changed.
 * The current code of the i18n.so module is no longer compatible and (as is) this is a bit
 * beyond our limited capabilities of fixing.
 *
 * But these days PHP has a usable i18n engine, even if it is a bit clunky. So our parent class
 * i18n.php checks if the function i18n_new() as provided by the PHP module is present and
 * accounted for. If so, it will use it for internationalization. If it's not loaded and ready,
 * then this new I18nNative.php class will be used instead and provides (more or less) the same
 * functionality via native PHP functions.
 *
 * I say "more or less" and that's true. For now all the i18n_get*() or i18n_interpolate*()
 * functions (for returning plain text, JavaScript-escaped or HTML'ized text) simply return
 * plain text. Although this *can* be changed (and might be in the future), we don't really
 * need that. The new GUI (as far as I can tell right now) handles plain UTF-8 text just fine
 * and I don't see a specific need to change this.
 *
 * My previous attempts at this were DAAAAAAMN slow, but v3.0 and newer are now almost as fast
 * as the Zend API module was.
 *
 */

//
// private class variables
//
class I18nNative {

    private $language;
    private $domain;

    public function setLanguage($language = "en_US") {
        $this->language = $language;
    }

    public function getLanguage() {
        return $this->language ?? ($this->language = $this->getBXIniLangs());
    }

    public function setDomain($domain) {
        $this->domain = $domain ?: "palette";
    }

    public function getDomain() {
        return $this->domain;
    }

    public function i18n_new($domain = "", $langs = "en_US") {
        $this->setLanguage($langs);
        $this->setDomain($domain);
        $directory = "/usr/share/locale";

        if ($domain) {
            bindtextdomain($domain, $directory);
        }
        bind_textdomain_codeset($domain, 'UTF-8');
        textdomain($domain);
    }

    public function i18n_get($tag, $domain = "", $vars = "") {
//        $domain = $domain ?: $this->getDomain();
//        if (preg_match('/\[\[([A-Za-z0-9\._-]+(?:,[\w+=\"?(\d+)\"?]*)?)\]\]/', $tag, $matches)) {
//            return $this->i18n_interpolate($matches[1], $vars);
//        }
//        return $this->i18n_do_it($tag, $domain, $vars);
        if ($domain == "") {
          $domain = $this->getDomain();
        }
        if (preg_match('/\[\[(.*)\]\]/', $tag)) {
          return $this->i18n_interpolate($tag, $vars);
        }
        $message = $this->i18n_do_it($tag, $domain, $vars);
        return $message;
    }

    public function i18n_get_html($tag, $domain, $vars = "") {
        return $this->i18n_get($tag, $domain, $vars);
    }

    public function i18n_get_js($tag, $domain, $vars = "") {
        return $this->i18n_get($tag, $domain, $vars);
    }

    public function i18n_interpolate($magicstr, $vars) {
        $message = "";
        $found = "";
        $insideVars = "";

        //
        // Legend:
        //--------
        // domain example: base-email
        // tag example:    personalEmail
        //
        // This regular expression should find the following:
        // [[tag]]
        // [[domain.tag]]
        // [[domain.tag,var=value]]
        // [[domain.tag,var=value,var2,value2,...]]
        //
        // If it doesn't, then we have to fallbacks that kick in at the end of this.
        //
        $zpattern = '/\[\[([A-Za-z0-9\._-]{1,}\.){0,}[A-Za-z0-9_-]{1,}[,\w+=\"?(\d+)\"?]{0,}\]\]/';
        preg_match_all($zpattern, $magicstr, $found, PREG_PATTERN_ORDER);
        if (is_array($found[0])) {
            if (count($found[0]) > "0") {
                // We have found at least one [[instance]] in $magicstr:
                $insideVars = array();
                foreach ($found[0] as $key => $insideTag) {
                    // Strip the '[[' and ']]':
                    $insideTag = preg_replace("/\[\[/", "", $insideTag);
                    $insideTag = preg_replace("/\]\]/", "", $insideTag);
                    $insideTag = preg_replace("/\"/", "", $insideTag);
                    // Check if we have values attached:
                    if (preg_match('/,/', $insideTag)) {
                        $valTags = explode(',', $insideTag);
                        if (isset($valTags[0])) {
                            $foundDomYTag = $valTags[0];
                        }
                        foreach ($valTags as $VTkey => $VTvalue) {
                            if ($VTvalue != $foundDomYTag) {
                                $TMPinsideVars = explode('=', $VTvalue);
                                $insideVars[$TMPinsideVars[0]] = $TMPinsideVars[1];
                            }
                        }
                        if (is_array($insideVars)) {
                            $vars = $insideVars;
                        }
                        // Get an Array with Domain + Tag:
                        $DomainYtag = explode('.', $valTags[0]);
                        if (isset($DomainYtag[1])) {
                            // We really DO have a domain and a tag.
                            if ((isset($DomainYtag[0])) && (isset($DomainYtag[1]))) {
                                $message .= i18nNative::i18n_do_it($DomainYtag[1], $DomainYtag[0], $vars);
                            }
                        }
                        else {
                            // We just had a Tag and inside-vars, but no domain:
                            $message .= i18nNative::i18n_do_it($found[1], i18nNative::getDomain() , $vars);
                        }
                        if (count($found[0]) > "1") {
                            // We have found more than one [[instance]] in $magicstr.
                            // So we join them back together with a space:
                            $message .= " ";
                        }
                    }
                }
            }
            if ((isset($found[0])) && ($message == "")) {
                // IF we get here, we have no message yet. The $zpattern didn't trigger.
                // This could be because there are more dots in the $magicstr then we expected.
                // Se we do it the really hard way:
                $zRESpattern = '/\[\[(.*)\]\]/';
                preg_match($zRESpattern, $magicstr, $found);
                if (isset($found[1])) {
                    // We got a full [[]] enclosed tag. Check if it has a comma:
                    $zCMApattern = '/,/';
                    preg_match($zCMApattern, $found[1], $CMAfound);
                    if (isset($CMAfound[0])) {
                        // It has a comma. Split at it:
                        $comaSplit = preg_split('/,/', $found[1]);
                        if (count($comaSplit) >= "2") {
                            $DomainYtag = explode('.', $comaSplit[0]);
                            if (isset($comaSplit[1])) {
                                unset($comaSplit[0]);
                                foreach ($comaSplit as $CSkey => $CSvalue) {
                                    // Split the resulting vars at the equal sign:
                                    $equalSplit = preg_split('/=/', $CSvalue);
                                    if (isset($equalSplit[1])) {
                                        // Strip \" away as it looks weird and also single \ as well:
                                        $equalSplit[1] = preg_replace('/\\\\"/', '', $equalSplit[1]);
                                        $equalSplit[1] = preg_replace('/\\\/', '', $equalSplit[1]);
                                    }
                                    // Assemble the vars:
                                    if (isset($equalSplit[1])) {
                                        $vars[$equalSplit[0]] = $equalSplit[1];
                                    }
                                }
                            }

                            // We really DO have a domain and a tag and vars - use them:
                            if ((isset($DomainYtag[0])) && (isset($DomainYtag[1]))) {
                                $message .= i18nNative::i18n_do_it($DomainYtag[1], $DomainYtag[0], $vars);
                            }
                        }
                    }
                    else {
                        // We only have a short DomainTag such as 'palette' and no comma:
                        $DomainYtag = explode('.', $found[1]);
                        // We really DO have a domain and a tag.
                        if ((isset($DomainYtag[0])) && (isset($DomainYtag[1]))) {
                            if ($DomainYtag[0] == "VAR") {
                                // If the Domain equals with VAR, then the tag is a substitute that we need
                                // to replace. Checl if $vars has a matching tag for a replacement:
                                if (isset($vars[$DomainYtag[1]])) {
                                    // Yes, it has. Use it:
                                    $message .= $vars[$DomainYtag[1]];
                                }
                            }
                            else {
                                $message .= i18nNative::i18n_do_it($DomainYtag[1], $DomainYtag[0], $vars);
                            }
                        }
                    }
                }
            }
        }

        if ($message == "") {
            // If we got here, then [[domain.tag]] had no variable attached like [[domain.tag,var=foo]].
            // Or the regular expression at the very top of the function didn't match anything.
            // Could be a couple of things. So this is our last ditch effort to produce something:
            // Strip the '[[' and ']]' first of all:
            $insideTag = preg_replace("/\[\[/", "", $magicstr);
            $insideTag = preg_replace("/\]\]/", "", $insideTag);
            // Explode at the dot (if there is any):
            $DomainYtag = explode('.', $insideTag);

            if (isset($DomainYtag[1])) {
                // We had a dot. Cool. So we have domain and tag and use both:
                $message = i18nNative::i18n_do_it($DomainYtag[1], $DomainYtag[0], $vars);
            }
            else {
                // we had no dot. So we have a tag and no domain. We use the tag and the last
                // known domain then:
                $message = i18nNative::i18n_do_it($insideTag, i18nNative::getDomain() , $vars);
            }
        }

        // Last line of defense: If we still have nothing, then we're royally screwed. Instead of
        // returning nothing, we just return the $magicstring:
        if ($message == "") {
            $message = $magicstr;
        }

        // Close your eyes. It won't hurt:
        return $message;
    }

    public function i18n_interpolate_js($magicstr, $vars) {
        return $this->i18n_interpolate($magicstr, $vars);
    }    

    public function i18n_interpolate_html($magicstr, $vars) {
        return $this->i18n_interpolate($magicstr, $vars);
    }

    public function i18n_do_it($tag, $domain, $vars = "") {
        return $this->performTranslation($tag, $domain, $vars);
    }

    // description: get a list of negotiated locales
    // param: domain: i18n domain in string. Optional
    // returns: an array of locale strings.
    //          The first one being to most important and so on
    function i18n_locales($domain) {
        // Handle 'base-palette' case
        if ($domain === 'base-palette') {
            $domain = 'palette';
        }

        // If $domain is empty, use the default domain
        if ($domain === "") {
            $domain = i18nNative::getDomain();
        }

        // Shortcut for 'VAR'
        if ($domain === "VAR") {
            $domain = "palette";
        }

        // Directory path for locale files
        $directory = '/usr/share/locale/*/LC_MESSAGES/' . $domain . '.mo';

        // Use PHP's glob function to get an array of file paths
        $files = glob($directory);

        // Extract the actual languages from the paths
        $detected_langs = array_map(
            function ($path) {
                // Extract the language code from the path
                $parts = explode("/", $path);
                return $parts[4];
            },
            $files
        );

        // Move 'en_US' to the front if present
        $detected_langs = array_values(array_diff($detected_langs, ['en_US']));
        array_unshift($detected_langs, 'en_US');

        return $detected_langs;
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
    function i18n_get_property($property, $domain, $lang = "") {
        // Use default language if not specified
        if ($lang === "") {
            $lang = i18nNative::getLanguage();
        }

        $item = "";
        $propFile = "/usr/share/locale/$lang/$domain.prop";

        // Check if the property file exists
        if (file_exists($propFile)) {
            // Read the contents of the property file directly into an array of lines
            $itemList = file($propFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Process each line in the property file
            foreach ($itemList as $value) {
                // Skip lines starting with #
                if (strpos($value, '#') !== 0) {
                    // Split the line into key and value
                    list($key, $val) = explode(':', $value, 2);

                    // Remove leading/trailing whitespaces
                    $key = trim($key);
                    $val = trim($val);

                    // Check if the current key matches the requested property
                    if ($property === $key) {
                        // Return the value if a match is found
                        return $val;
                    }
                }
            }
        }
        // Return an empty string if the property is not found or the file doesn't exist
        return "";
    }

    private function performTranslation($tag, $domain, $vars = "") {
        $lang = $this->getLanguage();
        putenv("LANG=$lang");
        setlocale(LC_ALL, $lang);

        if ($domain == "") {
            $domain = $this->getDomain();
        }

        $directory = "/usr/share/locale";
        setlocale(LC_MESSAGES, $lang);
        bindtextdomain($domain, $directory);
        textdomain($domain);
        bind_textdomain_codeset($domain, 'UTF-8');

        $message = gettext($tag);

        // Check if the $message contains [[STUFF]] that needs to be replaced:
        $pattern = '/\[\[[a-zA-Z0-9\-\_\.]{1,99}\]\]/';
        preg_match_all($pattern, $message, $matches);
        $numCount = count($matches[0], COUNT_RECURSIVE);

        if ($numCount > 0) {
            // Do the actual replacing:
            $newVars[] = array();
            $VarReplacements = array();
            $newTag = ' ';
            $newDomain = '';
            foreach ($matches[0] as $key => $value) {
                $patterns = array();
                $patterns[0] = '/\[\[/';
                $patterns[1] = '/\]\]/';
                $value = preg_replace($patterns, "", $value);
                $segments = explode('.', $value);
                if ((isset($segments[0])) && (isset($segments[1]))) {
                    if ($segments[0] !== "VAR") {
                        if (isset($segments[2])) {
                            $newDomain = $segments[0] . "." . $segments[1];
                            $newTag = $segments[2];
                        }
                        else {
                            $newDomain = $segments[0];
                            $newTag = $segments[1];
                        }
                        $VarReplacements["$newDomain.$newTag"] = $this->i18n_interpolate($value, $vars);
                    }
                    else {
                        $id = $segments[0] . '.' . $segments[1];
                        if (isset($vars[$segments[1]])) {
                            $VarReplacements[$id] = $vars[$segments[1]];
                        }
                    }
                }
            }

            // Do the actual replacing:
            if (count($VarReplacements) > 0) {
                foreach ($VarReplacements as $key => $replacement) {
                    $xpattern[0] = '/\[\[' . $key . '\]\]/';
                    $xreplacement[0] = $replacement;
                    $message = preg_replace($xpattern, $xreplacement, $message);
                }
            }
        }
        return $message;
    }

    // Helper function to get language from BX ini...
    private function getBXIniLangs() {
        $CI = &get_instance();
        return $CI->getBX_ini_langs();
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