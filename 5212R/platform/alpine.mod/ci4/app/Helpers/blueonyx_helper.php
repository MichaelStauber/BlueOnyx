<?php

/**
 * BlueOnyx Helper Library
 *
 * BlueOnyx Helper for Codeigniter
 *
 * @package   CI Blueonyx
 * @author    Michael Stauber
 * @copyright Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
 * @link      http://www.solarspeed.net
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   4.2
 */

// Making the CI instance accessible to other classes:
$CI_INSTANCE = [];  # It keeps a ref to global CI instance
function register_ci_instance(\App\Controllers\BaseController &$_ci) {
    global $CI_INSTANCE;
    $CI_INSTANCE[0] = &$_ci;
}

// Allowing other classes to get this instance:
function &get_instance(): \App\Controllers\BaseController {
    global $CI_INSTANCE;
    return $CI_INSTANCE[0];
}

function getIp() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $ip;
}

// This function is used to log 403 errors to /var/log/admserv/adm_error with the username,
// the IP of the offender, the page where it happened and the browser that was used.
// If an URL string is supplied, we will redirect to that and exit. Do NOT call this function
// with an URL unless you have said good bye to CCE first!
// 
// Example Log Entry: 
//
// User admin (IP: 186.116.135.82) triggered a 403 on page /vsite/manageAdmin?MODIFY=1&_oid=2605555 with user agent Firefox 25.0
// 
function Log403Error($url = "") {
    $CI =& get_instance();

    $BX_SESSION = $CI->getBX_SESSION();

    $loginName = $BX_SESSION['loginName'];
    $userip = getIp();

    if ($loginName == "") {
        $loginName = "-unknown or not logged in-";
    }

    $agent = 'Unknown User-Agent';
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $agent = $_SERVER['HTTP_USER_AGENT'];
    }

    $source = "unknown";
    if (isset($_SERVER['REQUEST_URI'])) {
        if ($_SERVER['REQUEST_URI']) {
            $source = $_SERVER['REQUEST_URI'];
        }
    }
    bx_error_log("User $loginName (IP: $userip) triggered a 403 on page $source with user agent $agent");
    if ($url != "") {
        header("location: $url");
        exit;
    }
}

// Function GetFormAttributes() walks through the $form_data and returns us the $parameters we want to
// submit to CCE. It intelligently handles checkboxes, which only have "on" set when they are ticked.
// In that case it pulls the unticked status from the hidden checkboxes and addes them to $parameters.
// It also transforms the value of the ticked checkboxes from "on" to "1". 
//
// Furthermore it intelligently handles textareas and turns their multiline strings into an urldecoded 
// and ampersand packed array in string format ready for submitting them to CODB. 
//
// Additionally it generates the form_validation rules for CodeIgniter.
//
// params: $i18n                i18n Object of the error messages
// params: $form_data           array with form_data array from CI
// params: $required_keys       array with keys that must have data in it. Needed for CodeIgniter's error checks
// params: $ignore_attributes   array with items we want to ignore. Such as Labels.
// return:                      array with keys and values ready to submit to CCE.

function GetFormAttributes ($i18n, $form_data, $required_keys=array(), $ignore_attributes=array(), $BxPage='') {
    // Get $CI instance:
    $CI =& get_instance();

    // Required array setup:
    $attributes = array();
    $seen_checkboxes = array();
    $seen_textareas = array();
    $seen_radios = array();
    $checkbox_data_before_submit = array();
    $textarea_data_before_submit = array();
    $radio_data_before_submit = array();
    $ignore_attributes[] = 'BlueOnyx_CSRF_token';
    $ignore_attributes[] = 'ci_csrf_token';
    $ignore_attributes[] = 'SelectedTab';

    // If present, ignore 'bx_session' data as well:
    if (isset($_COOKIE['bx_session'])) {
        $ignore_attributes[] = $_COOKIE['bx_session'];
    }

    // Let the games begin:
    foreach ($form_data as $key => $value) {
        //if (is_object($i18n)) {
        //    if (in_array($key, $required_keys)) {
        //        // This key is required. Create a CI form_validation rule that takes that into account:
        //        if (!is_array($value)) {
        //            //**$CI->form_validation->set_rules($key, $i18n->get($key), 'trim|required|xss_clean');
        //            //$CI->form_validation->set_rules($key, $i18n->get($key), 'trim|required');
        //        }
        //    }
        //    else {
        //        // This key is not required. Just do a form_validation rule with trim and xss_clean:
        //        if (!is_array($value)) {
        //            //**$CI->form_validation->set_rules($key, $i18n->get($key), 'trim|xss_clean');
        //            //$CI->form_validation->set_rules($key, $i18n->get($key), 'trim');
        //        }
        //    }
        //}
        //else {
        //    // This key is not required. Just do a form_validation rule with trim and xss_clean:
        //    if (!is_array($value)) {
        //        //**$CI->form_validation->set_rules($key, "N/A", 'trim|xss_clean');
        //        //$CI->form_validation->set_rules($key, "N/A", 'trim');
        //    }
        //}

        // Certain fields (like getSetSelector()) have arrays as values. We want to immediately
        // join them into a CODB-friendly storage format to make things a little easier:
        if (is_array($value)) {
            $value = array_to_scalar(array_values($value));
        }

        // Generate an array with the key => values we want to submit to CCE:
        if (!in_array($key, $ignore_attributes)) {
            // Key is not a key that we want to ignore.
            if (preg_match('/^checkbox-/', $key, $matches, PREG_OFFSET_CAPTURE)) {
                // This key is a hidden key from a checkbox. Extract the real key name:
                $new_key = preg_split('/^checkbox-/', $key);
                if (isset($new_key[1])) {
                    $the_new_key = $new_key[1];
                    // Add the real key name and the corresponding (old) value to $attributes:
                    $attributes[$the_new_key] = $value;
                    // Note down that we have seen this checkbox:
                    $seen_checkboxes[] = $the_new_key;
                    $checkbox_data_before_submit[$the_new_key] = $value;
                }
            }
            elseif (preg_match('/^textarea-/', $key, $matches, PREG_OFFSET_CAPTURE)) {
                // This key is a hidden key from a textarea. Extract the real key name:
                $new_ta_key = preg_split('/^textarea-/', $key);
                if (isset($new_ta_key[1])) {
                    $the_ta_new_key = $new_ta_key[1];
                    // Add the real key name and the corresponding (old) value to $attributes:
                    $attributes[$the_ta_new_key] = $value;
                    // Note down that we have seen this textarea:
                    $seen_textareas[] = $the_ta_new_key;
                    $textarea_data_before_submit[$the_ta_new_key] = $value;
                }
            }
            elseif (preg_match('/^radio-/', $key, $matches, PREG_OFFSET_CAPTURE)) {
                // This key is a hidden key from a radio selector. Extract the real key name:
                $new_radio_key = preg_split('/^radio-/', $key);
                if (isset($new_radio_key[1])) {
                    $the_radio_new_key = $new_radio_key[1];
                    // Add the real key name and the corresponding (old) value to $attributes:
                    $attributes[$the_radio_new_key] = $value;
                    // Note down that we have seen this textarea:
                    $seen_radios[] = $the_radio_new_key;
                    $radio_data_before_submit[$the_radio_new_key] = $value;
                }
            }           
            elseif (preg_match('/DataTables_length/', $key, $matches, PREG_OFFSET_CAPTURE)) {
                // Ignore DataTable length pulldown
            }
            elseif (preg_match('/DataTables_Table_(.*)_length/', $key, $matches, PREG_OFFSET_CAPTURE)) {
                // Ignore DataTable length pulldown - This is for new UIFC1 DataTables
            }
            else {
                // This is not the hidden key and (old) value of a checkbox:
                if (in_array($key, $seen_checkboxes)) {
                    // This is a "real" checkbox with new data. If it's ticked, the value will be "on".
                    // We need to change the value to "1" instead:
                    if ($value == "on") {
                        $attributes[$key] = "1";
                    }
                    else {
                        $attributes[$key] = "0";
                    }
                }
                elseif (in_array($key, $seen_textareas)) {
                    // This is a "real" textarea with new data. 
                    // We need to make its payload CODB-friendly.
                    $attributes[$key] = urldecode(arrayToString(stringNToArray($value)));
                }
                elseif (in_array($key, $seen_radios)) {
                    // This is a "real" radio with new data. 
                    // We need to make its payload CODB-friendly.
                    $attributes[$key] = urldecode(arrayToString(stringNToArray($value)));
                }
                else {
                    // This is not a hidden or real checkbox, nor is it a textarea. We can add it right away:
                    $attributes[$key] = $value;
                }
            }
        }
    }
    // Finally a correctional run to handle checkboxes which were "on", but have been unticked:
    foreach ($seen_checkboxes as $key => $value) {
        if (isset($checkbox_data_before_submit[$value])) {
            if ((isset($checkbox_data_before_submit[$value])) && (!isset($form_data[$value]))) {
                $attributes[$value] = "0";
            }
        }
    }
    // Finally a correctional run to handle radio selectors which were "on", but have been unticked:
    foreach ($seen_radios as $key => $value) {
        if (isset($radio_data_before_submit[$value])) {
            if ((isset($radio_data_before_submit[$value])) && (!isset($form_data[$value]))) {
                // Form had no value. Set to '0';
                $attributes[$value] = "0";
            }
            if ((isset($radio_data_before_submit[$value])) && (isset($form_data[$value])) && ($form_data[$value] != $radio_data_before_submit[$value])) {
                // Form had new and different value than what we had before. Set new value:
                $attributes[$value] = $form_data[$value];
            }
        }
    }   

    // Validation for missing fields:
    if ($BxPage != '') {
        $errors = array();
        foreach ($required_keys as $key => $formkey) {
            $validation_error = '0';
            if (!isset($attributes[$formkey])) {
                $missing_field = $i18n->get($formkey);
                $validation_error++;
            }
            if (isset($attributes[$formkey])) {
                if ($attributes[$formkey] == '') {
                    $validation_error++;
                }
            }
            if ($validation_error > '0') {
                $missing_field = $i18n->get($formkey);
                $errors[] = ErrorMessage($i18n->get("[[palette.val_is_required]]", false, array("field" => "'$missing_field'")) . '<br>&nbsp;');
            }
        }
        if (count($errors) > '0') {
            $BxPage->setErrors($errors);
        }
    }

    return $attributes;
}

// Private function that takes current ItemID and returns the URL of the first menu child
// that the current user has access rights for. Example: User has access to "Active Monitor".
// Which is under "Server Management". In that case we only want him to have "Active Monitor"
// listed under "Server Management" and need to hide "Network Services", "Security" and 
// "Maintenance". So we return just the URL for /am/amStatus. If he also had the privileges
// to mess with "Network Services", we'd get the first URL of the first item of "Network Services"
// that he is privileged to see. Sounds simple, but is a tiny weeny itzi bitzy complicated:
function getURLofFirstChild($val, $ignore_items, $_SiteMap_items, $access=array()) {

    // Our first itemID can be an array of IDs or a single ID.
    // We will only process ONE item ID, so we pick the first
    // item off the array and ignore the rest for now:
    if (is_array($val)) {
        $first_item = array_keys($val);
        $first_item = array_shift($first_item);
    }
    else {
        $first_item = $val;
    }

    // Find out which children this item ID has:
    $first_items_children = MenuChildren($first_item, $ignore_items, $_SiteMap_items, $access);

    // Sort the children based on their "order", so that the lowest order comes first:
    asort($first_items_children);

    // Go through the children one by one:
    foreach (array_keys($first_items_children) as $key => $itemID) {
        // Check if that menu child itself has other children:
        if (isset($_SiteMap_items[$itemID]["children"])) {
            // It does. So we extract the very first child from that:
            ksort($_SiteMap_items[$itemID]["children"]);
            $first_item = array_values($_SiteMap_items[$itemID]["children"]);
            $first_item = array_shift($first_item);
            // Check if that grandchild has an URL set. It should, as our menus are at the
            // worst three levels deep ("root" / category header / actual menu entry):
            if (isset($_SiteMap_items[$first_item]["url"])) {
                // Ok, it has an URL. We return that and be done with this charade:
                return $_SiteMap_items[$first_item]["url"];
            }
        }
        else {
            // This child has no childs of its own. So we check if it has an URL set:
            if ((isset($_SiteMap_items[$itemID]["url"])) && (!isset($_SiteMap_items[$itemID]["children"]))) {
                // It has an URL set. So we return that and be done here:
                return $_SiteMap_items[$itemID]["url"];
            }
        }
    }

    // After all this trouble we still don't have a return URL? In that case we 
    // return the URL of the parent passed to us. Which might contain an URL.
    // Or it not, it returns NULL:
    return $_SiteMap_items[$first_item]["url"];
}

// Function to clean URLs:
// The Menu XML files have some [[variables]] in them that need to be replaced with
// the actual intended content. Such as the group ID or the FQDN. We do that here.
function fixInternalURLs($url, $substitute=array()) {

    // Start sane:
    $numCount = "0";

    if ((isset($substitute['group'])) && (isset($substitute['fqdn']))) {
        // Check if the URL has a [[variable]] that needs replacing:
        $pattern = '/\[\[[a-zA-Z0-9\-\_\.]{1,99}\]\]/';
        if (isset($url)) {
            preg_match_all($pattern, $url, $matches);
            $numCount = count($matches[0], COUNT_RECURSIVE);
        }
        else {
            $numCount = '0';
        }

        if ($numCount > 0) {

            // Do the actual replacing:
            foreach ($matches[0] as $key => $value) {
                $patterns = array();
                $patterns[0] = '/\[\[/';
                $patterns[1] = '/\]\]/';
                $value = preg_replace($patterns, "", $value);
                $xpatterns = array();
                // Found [[VAR.group]]:
                if ($value == "VAR.group") {
                    // Replace with the group name:
                    $replacement = $substitute['group']; 
                }
                // Found [[VAR.instance]]:
                if ($value == "VAR.instance") {
                    // Replace with the group name:
                    $replacement = $substitute['group']; 
                }
                // Found [[VAR.hostname]]:
                if ($value == "VAR.hostname") {
                    // Replace with the FQDN of the Vsite the user belongs to:
                    $replacement = $substitute['fqdn'];
                }
                //if ($value == "VAR.title") { // <-- Not sure where this is used!
                //  $replacement = ... no idea!
                //} 
                $xpatterns[0] = "/\[\[$value\]\]/";
                // Actual replacement:
                if (isset($replacement)) {
                    $url = preg_replace($xpatterns, "" . $replacement . "", $url);
                }
            }
        }
    }
    // Return cleaned URL:
    return $url;
}

/**
 * initialize_languages($browserdetect)
 *
 * A helper function that defines which languages we support and which activates them for
 * usage. Depending on the language locale and the charset the generated pages will be 
 * rendered slightly different. 
 *
 * A cookie set locale always overrides anything that was gathered by browser detect. If
 * all fails we hail Mary (who sould have confessed to cheating instead) and fail back to 
 * English.
 *
 * @param VAR   $browserdetect  : TRUE or empty. Defines if we use browser detect or not.
 * @return ARR  array("locale" => $locale, "localization" => $localization, "charset" => $charset);
 */

function initialize_languages($browserdetect, $override=FALSE) {

    // Include BXBrowserLocale:
    include_once("BXBrowserLocale.php");

    // Start sane:
    $locale = 'en_US';
    $charset = 'UTF-8';

    $CI =& get_instance();
    $cookie_locale = 'en_US';
    if (isset($_COOKIE['locale'])) {
        $cookie_locale = $_COOKIE['locale'];
        $browserdetect = 'FALSE';
    }
    else {
        // No cookie for locale? Use browser-detect:
        $browserdetect = 'TRUE';
    }

    if ($override != FALSE) {
        $cookie_locale = $override;
        $browserdetect = 'FALSE';
    }

    if ($browserdetect == "TRUE") {

        // Detect the browser locale to see if it is supported.
        // If not, fall back to 'en_US':
        $BXBrowserLocale = new BXBrowserLocale();
        $detected_locale = $BXBrowserLocale->prefered_language();

        if ($detected_locale == 'en_US') {
            $locale = 'en_US';
            $localization = 'en-US';
            $loc = 'en';
        }
        elseif ($detected_locale == 'de_DE') {
            $locale = 'de_DE';
            $localization = 'de-DE';
            $loc = 'de';
        }
        elseif ($detected_locale == 'da_DK') {
            $locale = 'da_DK';
            $localization = 'da-DK';
            $loc = 'da';
        }
        elseif ($detected_locale == 'es_ES') {
            $locale = 'es_ES';
            $localization = 'es-ES';
            $loc = 'es';
        }
        elseif ($detected_locale == 'fr_FR') {
            $locale = 'fr_FR';
            $localization = 'fr-FR';
            $loc = 'fr';
        }
        elseif ($detected_locale == 'it_IT') {
            $locale = 'it_IT';
            $localization = 'it-IT';
            $loc = 'it';
        }
        elseif ($detected_locale == 'pt_PT') {
            $locale = 'pt_PT';
            $localization = 'pt-PT';
            $loc = 'pt';
        }
        elseif ($detected_locale == 'nl_NL') {
            $locale = 'nl_NL';
            $localization = 'nl-NL';
            $loc = 'nl';
        }
        elseif ($detected_locale == 'ja_JP') {
            $locale = 'ja_JP';
            $localization = 'ja-JP';
            $loc = 'ja';
        }
    }
    elseif ($cookie_locale == "en_US") {
        $locale = 'en_US';
        $localization = 'en-US';
        $loc = 'en';
    }
    elseif ($cookie_locale == "de_DE") {
        $locale = 'de_DE';
        $localization = 'de-DE';
        $loc = 'de';
    }
    elseif ($cookie_locale == "da_DK") {
        $locale = 'da_DK';
        $localization = 'da-DK';
        $loc = 'da';
    }
    elseif ($cookie_locale == "es_ES") {
        $locale = 'es_ES';
        $localization = 'es-ES';
        $loc = 'es';
    }
    elseif ($cookie_locale == "fr_FR") {
        $locale = 'fr_FR';
        $localization = 'fr-FR';
        $loc = 'fr';
    }
    elseif ($cookie_locale == "it_IT") {
        $locale = 'it_IT';
        $localization = 'it-IT';
        $loc = 'it';
    }
    elseif ($cookie_locale == "pt_PT") {
        $locale = 'pt_PT';
        $localization = 'pt-PT';
        $loc = 'pt';
    }
    elseif ($cookie_locale == "nl_NL") {
        $locale = 'nl_NL';
        $localization = 'nl-NL';
        $loc = 'nl';
    }
    elseif ($cookie_locale == "ja_JP") {
        $locale = 'ja_JP';
        $localization = 'ja-JP';
        $loc = 'ja';
    }
    else {
        $locale = 'en_US';
        $localization = 'en-US';
        $loc = 'en';
    }

    $localecharset = 'UTF-8';

    return array("locale" => $locale, "localization" => $localization, "charset" => $charset, "localecharset" => $localecharset, "loc" => $loc);
}

// This function is used to get the Newsfeed off www.blueonyx.it:
function getRssfeed($rssfeed, $cssclass="", $encode="auto", $howmany=10, $mode=0) {
    // $encode e[".*"; "no"; "auto"]

    // $mode e[0; 1; 2; 3]:
    // 0 = only titel and link of the items
    // 1 = Titel and link
    // 2 = Titel, link and description
    // 3 = 1 & 2
    
    $bx_title = array();
    $bx_date = array();
    $bx_desc = array();
    $bx_link = array();
    
    // Pull the RSS feed:
    $data = get_data($rssfeed);
    if(strpos($data,"</item>") > 0) {
        preg_match_all("/<item.*>(.+)<\/item>/Uism", $data, $items);
        $atom = 0;
    }
    elseif(strpos($data,"</entry>") > 0) {
        preg_match_all("/<entry.*>(.+)<\/entry>/Uism", $data, $items);
        $atom = 1;
    }

    if (!isset($atom)) {
        return NULL;
    }
    
    // Encoding:
    if($encode == "auto") {
        preg_match("/<?xml.*encoding=\"(.+)\".*?>/Uism", $data, $encodingarray);
        if (isset($encodingarray[1])) {
            $encoding = $encodingarray[1];
        }
    }
    else {
        $encoding = $encode;
    }
    
    // Titel and link:
    if ($mode == 1 || $mode == 3) {
        if(strpos($data,"</item>") > 0) {
            $data = preg_replace("/<item.*>(.+)<\/item>/Uism", '', $data);
        }
        else {
            $data = preg_replace("/<entry.*>(.+)<\/entry>/Uism", '', $data);
        }
        preg_match("/<title.*>(.+)<\/title>/Uism", $data, $channeltitle);
        if($atom == 0) {
            preg_match("/<link>(.+)<\/link>/Uism", $data, $channellink);
        }
        elseif($atom == 1) {
            preg_match("/<link.*alternate.*text\/html.*href=[\"\'](.+)[\"\'].*\/>/Uism", $data, $channellink);
        }

        $channeltitle = preg_replace('/<!\[CDATA\[(.+)\]\]>/Uism', '$1', $channeltitle);
        $channellink = preg_replace('/<!\[CDATA\[(.+)\]\]>/Uism', '$1', $channellink);
    }
    // Check if we get multiple news items back. If not, a proxy or a badly configured router may be interfering:
    $counter = count ($items);
    if ($counter) {
        // Titel, link and description of the news items:
        foreach ($items[1] as $item) {
        preg_match("/<title.*>(.+)<\/title>/Uism", $item, $title);
        if($atom == 0) {
            preg_match("/<link>(.+)<\/link>/Uism", $item, $link);
        }
        elseif($atom == 1) {
            preg_match("/<link.*alternate.*text\/html.*href=[\"\'](.+)[\"\'].*\/>/Uism", $item, $link);
        }
        
        if($atom == 0) {
            preg_match("/<description>(.*)<\/description>/Uism", $item, $description);
        }
        elseif($atom == 1) {
            preg_match("/<summary.*>(.*)<\/summary>/Uism", $item, $description);
        }

        preg_match("/<pubDate>(.*)-(.*)<\/pubDate>/Uism", $item, $pubDate);

        $bx_title[] = $title[1];
        $bx_date[] = $pubDate[1];
        $bx_desc[] = $description[1];
        $bx_link[] = $link[1];

        if ($howmany-- <= 1) break; }
        $payload["_bx_title"] = $bx_title;
        $payload["_bx_date"] = $bx_date;
        $payload["_bx_desc"] = $bx_desc;
        $payload["_bx_link"] = $bx_link;
    }
    else {
        // Did not receive expected results. Set bx_title to something we can catch and process:
        $payload["_bx_title"] = "n/a";
    }
    return $payload;
}

function areWeOnline($domain, $awo_timeout = "10") {
    // Check to see if we're online and if the desired URL is reachable.
    // Returns true, if URL is reachable, false if not

    if (!isset($awo_timeout)) {
        $awo_timeout = "10";
    }

    // Initialize curl:
    $curlInit = curl_init($domain);
    curl_setopt($curlInit,CURLOPT_TIMEOUT, $awo_timeout);
    curl_setopt($curlInit,CURLOPT_CONNECTTIMEOUT, $awo_timeout);
    curl_setopt($curlInit,CURLOPT_HEADER,true);
    curl_setopt($curlInit,CURLOPT_NOBODY,true);
    curl_setopt($curlInit,CURLOPT_RETURNTRANSFER,true);

    // Get answer
    $response = curl_exec($curlInit);

    // Close curl:
    curl_close($curlInit);

    // Generate response:
    if ($response) return true;
    return false;
}

function get_prometheus_metrics($prometheusURL = 'http://localhost:9100/metrics', $timeout = 90) {
    // Set up the cURL options
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $prometheusURL);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BlueLinQ/1.0');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 81920); // You can adjust the buffer size as needed

    // Execute the cURL request
    $data = curl_exec($ch);

    // Check for cURL errors
    $error = curl_error($ch);
    $info = curl_getinfo($ch);

    // Close the cURL handle
    curl_close($ch);

    if ($data === false) {
        return "cURL Error: $error";
    }

    if ($info['http_code'] != 200) {
        return "HTTP Error: {$info['http_code']}";
    }

    // Debugging statements
    // echo "HTTP Code: {$info['http_code']}\n";
    // echo "Response Size: " . strlen($data) . " bytes\n";
    // echo "Response Content:\n$data\n";

    return $data;
}

function displayDiskChart($BxPage, $cceClient, $plotName, $metrics, $warnPercentage = '0.85', $height = '250px') {

    $CI =& get_instance();

    $i18n = $BxPage->getI18n();

    $TotalDiskspace = $i18n->get("[[palette.TotalDiskspace]]");
    $UsedDiskspace = $i18n->get("[[palette.UsedDiskspace]]");

    $sort_map = array(2 => 'used', 3 => 'total');
    $partitions = $cceClient->findNSorted('Disk', $sort_map[2], array('mounted' => true));
    $partitions = array_reverse($partitions);

    $disk_info = [];
    foreach ($partitions as $id => $oid) {
        $codb_disk = $cceClient->get($oid);

        // Convert total size to gigabytes
        $total_gb = round($codb_disk['total'] / (1024 * 1024), 2);
        $used_gb = round($codb_disk['used'] / (1024 * 1024), 2);
        $disk_info[$codb_disk['mountPoint']] = array('total' => $total_gb, 'used' => $used_gb);
    }

    ksort($disk_info);

    $disk_labels = [];
    $disk_used = [];
    $disk_total = [];
    foreach ($disk_info as $label => $value) {
        $disk_labels[] = $label . ' (GB)';
        $disk_used[] = $value['used'];
        $disk_total[] = $value['total'];
    }

    $json_labels = json_encode($disk_labels);
    $json_disk_used = json_encode($disk_used);
    $json_disk_total = json_encode($disk_total);

    $extraFooters =<<<HTML

        <!-- Disk Size/Usage Chart -->
        <script>
            $(document).ready(function () {
                "use strict";

                if ($('#$plotName').length > 0) {
                    var ctx2 = document.getElementById("$plotName").getContext("2d");

                    var totalDiskspace = $json_disk_total;
                    var usedDiskspace = $json_disk_used;

                    var backgroundColors = usedDiskspace.map((value, index) => {
                        // Set custom color (red) if used space is more than 85% of total space
                        return value / totalDiskspace[index] > $warnPercentage ? "rgba(255, 0, 0, 1)" : "rgba(74, 162, 60, 1)";
                    });

                    var DiskData = {
                        labels: $json_labels,
                        datasets: [
                            {
                                label: "$TotalDiskspace",
                                backgroundColor: "rgba(248, 179, 45,1)",
                                borderColor: "rgba(248, 179, 45,1)",
                                data: totalDiskspace
                            },
                            {
                                label: "$UsedDiskspace",
                                backgroundColor: backgroundColors,
                                borderColor: "rgba(74, 162, 60,1)",
                                data: usedDiskspace
                            }
                        ]
                    };

                    var hBar = new Chart(ctx2, {
                        type: "horizontalBar",
                        data: DiskData,

                        options: {
                            tooltips: {
                                mode: "label"
                            },
                            scales: {
                                yAxes: [{
                                    stacked: false,
                                    gridLines: {
                                        color: "rgba(33,33,33,0)",
                                    },
                                    ticks: {
                                        fontFamily: "Roboto",
                                        fontColor: "#878787"
                                    }
                                }],
                                xAxes: [{
                                    stacked: false,
                                    gridLines: {
                                        color: "rgba(33,33,33,0)",
                                    },
                                    ticks: {
                                        fontFamily: "Roboto",
                                        fontColor: "#878787"
                                    }
                                }],
                            },
                            elements: {
                                point: {
                                    hitRadius: 40
                                }
                            },
                            animation: {
                                duration: 3000
                            },
                            responsive: true,
                            legend: {
                                display: false,
                            },
                            tooltip: {
                                backgroundColor: 'rgba(33,33,33,1)',
                                cornerRadius: 0,
                                footerFontFamily: "'Roboto'"
                            }

                        }
                    });
                }
            });
        </script>
        <!-- /Disk Size/Usage Chart -->
    HTML;

    $BxPage->setExtraFooters($extraFooters);

}

function displayFlotChart($BxPage, $plotName, $metrics, $labels, $height = '250px', $theme = 'light', $spacing = '4') {
    $themeColors = ($theme === 'dark')
        ? ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#8A2BE2"]
        : ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#E6E6FA"];

    $json_theme_colors = json_encode($themeColors);
    $json_spacing = json_encode($spacing);

    $extraFooters = <<<HTML

            <script>
            (function() {
                var maxDataPoints = 12;
                var chartId = "$plotName";
                var chartLabels = $labels;
                var spacingFactor = $json_spacing;
                var themeColors = $json_theme_colors;
                var chartSeries = {};

                function formatYAxisLabel(value) {
                    var absValue = Math.abs(value);
                    if (absValue >= 1e9) return (value / 1e9).toFixed(1) + "G";
                    if (absValue >= 1e6) return (value / 1e6).toFixed(1) + "M";
                    if (absValue >= 1e3) return (value / 1e3).toFixed(1) + "K";
                    return value.toFixed(2);
                }

                function updateChart(metricsData) {
                    var relevant = metricsData["$metrics"];
                    if (!relevant) return;

                    var dataMap = {};
                    if (Array.isArray(relevant)) {
                        // If metrics is a flat list of {name:..., value:...}
                        relevant.forEach(item => {
                            if (item.name && typeof item.value !== 'undefined') {
                                dataMap[item.name] = item.value;
                            }
                        });
                    } else {
                        // If metrics is already key-value (e.g., shortload)
                        dataMap = relevant;
                    }

                    for (var label in dataMap) {
                        if (!chartSeries[label]) {
                            chartSeries[label] = [];
                            for (var i = 0; i < maxDataPoints; i++) {
                                chartSeries[label].push([i, 0]);
                            }
                        }

                        chartSeries[label].push([chartSeries[label].length, dataMap[label]]);
                        if (chartSeries[label].length > maxDataPoints) {
                            chartSeries[label].shift();
                            // Normalize X axis
                            for (var j = 0; j < chartSeries[label].length; j++) {
                                chartSeries[label][j][0] = j;
                            }
                        }
                    }

                    var plotData = Object.keys(chartSeries).map(label => ({
                        label: label,
                        data: chartSeries[label]
                    }));

                    var maxY = 0;
                    plotData.forEach(series => {
                        series.data.forEach(point => {
                            if (point[1] > maxY) maxY = point[1];
                        });
                    });

                    $.plot("#" + chartId, plotData, {
                        series: {
                            shadowSize: 0,
                            stack: true,
                            lines: { show: true, fill: true, lineWidth: 2 },
                            points: { show: true, radius: 4, fill: true }
                        },
                        xaxis: {
                            font: { color: "#777" }
                        },
                        yaxis: {
                            min: 0,
                            max: maxY * spacingFactor,
                            font: { color: "#878787" },
                            tickFormatter: formatYAxisLabel
                        },
                        colors: themeColors,
                        grid: {
                            color: "rgba(0,0,0,0)",
                            hoverable: true,
                            borderWidth: 1,
                            borderColor: "#ddd"
                        },
                        legend: {
                            labelBoxBorderColor: "#ddd",
                            backgroundColor: 'transparent',
                            labelFormatter: function (label) {
                                return chartLabels[label] || label;
                            }
                        },
                        tooltip: true,
                        tooltipOpts: {
                            content: function (label, xval, yval) {
                                return (chartLabels[label] || label) + ": " + formatYAxisLabel(yval);
                            },
                            shifts: { x: -60, y: 25 },
                            defaultTheme: false,
                            cssClass: "flot-tooltip"
                        }
                    });
                }

                $(document).ready(function () {
                    $("#$plotName").css({ height: "$height" });

                    if (!window.chartUpdateCallbacks) {
                        window.chartUpdateCallbacks = [];
                    }

                    window.chartUpdateCallbacks.push(updateChart);
                });
            })();
            </script>
    HTML;

    $BxPage->setExtraFooters($extraFooters);
}

function renderSingleFlotChart($BxPage, $chartId, $metricKey, $labels, $i18n, $height = '250px', $theme = 'light', $spacing = 4) {
    $themeColors = ($theme === 'dark')
        ? ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#8A2BE2"]
        : ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#E6E6FA"];

    $json_theme_colors = json_encode($themeColors);
    $json_labels = $labels;
    $json_spacing = json_encode($spacing);

    $plotHtml = <<<HTML
        <style>
            #$chartId {
                width: 100%;
                height: $height;
            }
        </style>

        <div class="flot-container" style="height:$height">
            <div id="$chartId" class="demo-placeholder"></div>
        </div>

        <script>
            (function () {
                const chartId = "$chartId";
                const metricKey = "$metricKey";
                const chartLabels = $json_labels;
                const themeColors = $json_theme_colors;
                const spacingFactor = $json_spacing;
                const maxDataPoints = 12;

                let chartSeries = {};

                function formatYAxisLabel(value) {
                    let abs = Math.abs(value);
                    if (abs >= 1e9) return (value / 1e9).toFixed(1) + "G";
                    if (abs >= 1e6) return (value / 1e6).toFixed(1) + "M";
                    if (abs >= 1e3) return (value / 1e3).toFixed(1) + "K";
                    return value.toFixed(2);
                }

                window.updateFlotChart = function (plotTarget, dataMap) {
                    if (plotTarget !== chartId || !dataMap) return;

                    Object.keys(dataMap).forEach(label => {
                        if (!chartSeries[label]) {
                            chartSeries[label] = [];
                            for (let i = 0; i < maxDataPoints; i++) {
                                chartSeries[label].push([i, 0]);
                            }
                        }

                        chartSeries[label].push([chartSeries[label].length, dataMap[label]]);
                        if (chartSeries[label].length > maxDataPoints) {
                            chartSeries[label].shift();
                            // Reindex x-axis
                            chartSeries[label] = chartSeries[label].map((v, i) => [i, v[1]]);
                        }
                    });

                    const plotData = Object.entries(chartSeries).map(([label, data]) => ({
                        label,
                        data
                    }));

                    let maxY = 0;
                    plotData.forEach(series =>
                        series.data.forEach(point => {
                            if (point[1] > maxY) maxY = point[1];
                        })
                    );

                    $.plot("#" + chartId, plotData, {
                        series: {
                            shadowSize: 0,
                            stack: true,
                            lines: { show: true, fill: true, lineWidth: 2 },
                            points: { show: true, radius: 4, fill: true }
                        },
                        xaxis: {
                            font: { color: "#777" }
                        },
                        yaxis: {
                            min: 0,
                            max: maxY * spacingFactor,
                            font: { color: "#878787" },
                            tickFormatter: formatYAxisLabel
                        },
                        colors: themeColors,
                        grid: {
                            color: "rgba(0,0,0,0)",
                            hoverable: true,
                            borderWidth: 1,
                            borderColor: "#ddd"
                        },
                        legend: {
                            labelBoxBorderColor: "#ddd",
                            backgroundColor: 'transparent',
                            labelFormatter: function (label) {
                                return chartLabels[label] || label;
                            }
                        },
                        tooltip: true,
                        tooltipOpts: {
                            content: function (label, xval, yval) {
                                return (chartLabels[label] || label) + ": " + formatYAxisLabel(yval);
                            },
                            shifts: { x: -60, y: 25 },
                            defaultTheme: false,
                            cssClass: "flot-tooltip"
                        }
                    });
                };

                function pollMetrics() {
                    fetch("/gui/metrics", {
                        method: "GET",
                        credentials: "same-origin"
                    })
                    .then(res => {
                        if (!res.ok) throw new Error("HTTP " + res.status);
                        return res.json();
                    })
                    .then(data => {
                        if (data[metricKey]) {
                            let mappedData = {};

                            // Accept both array-of-objects and object formats
                            if (Array.isArray(data[metricKey])) {
                                data[metricKey].forEach(metric => {
                                    if (metric.name !== undefined && metric.value !== undefined) {
                                        mappedData[metric.name] = metric.value;
                                    }
                                });
                            } else if (typeof data[metricKey] === 'object') {
                                mappedData = data[metricKey];
                            }

                            updateFlotChart(chartId, mappedData);
                        }
                    })
                    .catch(err => console.warn("Polling failed for", chartId, ":", err));
                }

                document.addEventListener("DOMContentLoaded", () => {
                    pollMetrics();
                    setInterval(pollMetrics, 5000);
                });
            })();
        </script>
    HTML;

    $BxPage->setExtraFooters($plotHtml);
}

function renderSingleFlotChartDeltas($BxPage, $chartId, $metricKey, $labels, $i18n, $height = '250px', $theme = 'light', $spacing = 4) {
    $themeColors = ($theme === 'dark')
        ? ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#8A2BE2"]
        : ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#E6E6FA"];

    $json_theme_colors = json_encode($themeColors);
    $json_labels = $labels;
    $json_spacing = json_encode($spacing);

    $plotHtml = <<<HTML
        <style>
            #$chartId {
                width: 100%;
                height: $height;
            }
        </style>

        <div class="flot-container" style="height:$height">
            <div id="$chartId" class="demo-placeholder"></div>
        </div>

        <script>
        (function () {
            const chartId = "$chartId";
            const metricKey = "$metricKey";
            const chartLabels = $json_labels;
            const themeColors = $json_theme_colors;
            const spacingFactor = $json_spacing;
            const maxDataPoints = 12;

            let chartSeries = {};  // {label: [[x, value], ...]}
            let lastSamples = {};  // {label: { value, timestamp }}

            function formatBytesPerSecond(bytes) {
                const rate = bytes;
                if (rate >= 1e9) return (rate / 1e9).toFixed(2) + "G/s";
                if (rate >= 1e6) return (rate / 1e6).toFixed(2) + "M/s";
                if (rate >= 1e3) return (rate / 1e3).toFixed(2) + "K/s";
                return rate.toFixed(0) + " B/s";
            }

            window.updateFlotChart = function (plotTarget, dataMap) {
                if (plotTarget !== chartId || !dataMap) return;

                const now = Date.now();

                Object.entries(dataMap).forEach(([label, newValue]) => {
                    if (!chartSeries[label]) {
                        chartSeries[label] = [];
                        for (let i = 0; i < maxDataPoints; i++) {
                            chartSeries[label].push([i, 0]);
                        }
                    }

                    let rate = 0;

                    if (lastSamples[label]) {
                        const { value: lastVal, timestamp: lastTs } = lastSamples[label];
                        const deltaBytes = newValue - lastVal;
                        const deltaTimeSec = (now - lastTs) / 1000;

                        if (deltaBytes >= 0 && deltaTimeSec > 0) {
                            rate = deltaBytes / deltaTimeSec;
                        }
                    }

                    lastSamples[label] = { value: newValue, timestamp: now };

                    chartSeries[label].push([chartSeries[label].length, rate]);
                    if (chartSeries[label].length > maxDataPoints) {
                        chartSeries[label].shift();
                        chartSeries[label] = chartSeries[label].map((v, i) => [i, v[1]]);
                    }
                });

                const plotData = Object.entries(chartSeries).map(([label, data]) => ({
                    label,
                    data
                }));

                let maxY = 0;
                plotData.forEach(series =>
                    series.data.forEach(point => {
                        if (point[1] > maxY) maxY = point[1];
                    })
                );

                $.plot("#" + chartId, plotData, {
                    series: {
                        shadowSize: 0,
                        stack: true,
                        lines: { show: true, fill: true, lineWidth: 2 },
                        points: { show: true, radius: 4, fill: true }
                    },
                    xaxis: {
                        font: { color: "#777" }
                    },
                    yaxis: {
                        min: 0,
                        max: maxY * spacingFactor,
                        font: { color: "#878787" },
                        tickFormatter: formatBytesPerSecond
                    },
                    colors: themeColors,
                    grid: {
                        color: "rgba(0,0,0,0)",
                        hoverable: true,
                        borderWidth: 1,
                        borderColor: "#ddd"
                    },
                    legend: {
                        labelBoxBorderColor: "#ddd",
                        backgroundColor: 'transparent',
                        labelFormatter: function (label) {
                            return chartLabels[label] || label;
                        }
                    },
                    tooltip: true,
                    tooltipOpts: {
                        content: function (label, xval, yval) {
                            return (chartLabels[label] || label) + ": " + formatBytesPerSecond(yval);
                        },
                        shifts: { x: -60, y: 25 },
                        defaultTheme: false,
                        cssClass: "flot-tooltip"
                    }
                });
            };

            function pollMetrics() {
                fetch("/gui/metrics", {
                    method: "GET",
                    credentials: "same-origin"
                })
                .then(res => {
                    if (!res.ok) throw new Error("HTTP " + res.status);
                    return res.json();
                })
                .then(data => {
                    if (data[metricKey]) {
                        let mappedData = {};

                        if (Array.isArray(data[metricKey])) {
                            data[metricKey].forEach(metric => {
                                if (metric.name !== undefined && metric.value !== undefined) {
                                    mappedData[metric.name] = metric.value;
                                }
                            });
                        } else if (typeof data[metricKey] === 'object') {
                            mappedData = data[metricKey];
                        }

                        updateFlotChart(chartId, mappedData);
                    }
                })
                .catch(err => console.warn("Polling failed for", chartId, ":", err));
            }

            document.addEventListener("DOMContentLoaded", () => {
                pollMetrics();
                setInterval(pollMetrics, 5000);
            });
        })();
        </script>
    HTML;

    $BxPage->setExtraFooters($plotHtml);
}

function old_renderSingleFlotChart($BxPage, $chartId, $metricKey, $labels, $i18n, $height = '250px', $theme = 'light', $spacing = 4) {
    $themeColors = ($theme === 'dark')
        ? ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#8A2BE2"]
        : ["#f8b32d", "#4aa23c", "#667ADD", "#F33923", "#Cb6004", "#E6E6FA"];

    $json_theme_colors = json_encode($themeColors);
    $json_labels = $labels;
    $json_spacing = json_encode($spacing);

    $plotHtml = <<<HTML
        <style>
            #$chartId {
                width: 100%;
                height: $height;
            }
        </style>

        <div class="flot-container" style="height:$height">
            <div id="$chartId" class="demo-placeholder"></div>
        </div>

        <script>
            (function () {
                const chartId = "$chartId";
                const metricKey = "$metricKey";
                const chartLabels = $json_labels;
                const themeColors = $json_theme_colors;
                const spacingFactor = $json_spacing;
                const maxDataPoints = 12;

                let chartSeries = {};

                function formatYAxisLabel(value) {
                    let abs = Math.abs(value);
                    if (abs >= 1e9) return (value / 1e9).toFixed(1) + "G";
                    if (abs >= 1e6) return (value / 1e6).toFixed(1) + "M";
                    if (abs >= 1e3) return (value / 1e3).toFixed(1) + "K";
                    return value.toFixed(2);
                }

                window.updateFlotChart = function (plotTarget, dataMap) {
                    if (plotTarget !== chartId || !dataMap) return;

                    Object.keys(dataMap).forEach(label => {
                        if (!chartSeries[label]) {
                            chartSeries[label] = [];
                            for (let i = 0; i < maxDataPoints; i++) {
                                chartSeries[label].push([i, 0]);
                            }
                        }

                        chartSeries[label].push([chartSeries[label].length, dataMap[label]]);
                        if (chartSeries[label].length > maxDataPoints) {
                            chartSeries[label].shift();
                            // Reindex x-axis
                            chartSeries[label] = chartSeries[label].map((v, i) => [i, v[1]]);
                        }
                    });

                    const plotData = Object.entries(chartSeries).map(([label, data]) => ({
                        label,
                        data
                    }));

                    let maxY = 0;
                    plotData.forEach(series =>
                        series.data.forEach(point => {
                            if (point[1] > maxY) maxY = point[1];
                        })
                    );

                    $.plot("#" + chartId, plotData, {
                        series: {
                            shadowSize: 0,
                            stack: true,
                            lines: { show: true, fill: true, lineWidth: 2 },
                            points: { show: true, radius: 4, fill: true }
                        },
                        xaxis: {
                            font: { color: "#777" }
                        },
                        yaxis: {
                            min: 0,
                            max: maxY * spacingFactor,
                            font: { color: "#878787" },
                            tickFormatter: formatYAxisLabel
                        },
                        colors: themeColors,
                        grid: {
                            color: "rgba(0,0,0,0)",
                            hoverable: true,
                            borderWidth: 1,
                            borderColor: "#ddd"
                        },
                        legend: {
                            labelBoxBorderColor: "#ddd",
                            backgroundColor: 'transparent',
                            labelFormatter: function (label) {
                                return chartLabels[label] || label;
                            }
                        },
                        tooltip: true,
                        tooltipOpts: {
                            content: function (label, xval, yval) {
                                return (chartLabels[label] || label) + ": " + formatYAxisLabel(yval);
                            },
                            shifts: { x: -60, y: 25 },
                            defaultTheme: false,
                            cssClass: "flot-tooltip"
                        }
                    });
                };

                function pollMetrics() {
                    fetch("/gui/metrics", {
                        method: "GET",
                        credentials: "same-origin"
                    })
                    .then(res => {
                        if (!res.ok) throw new Error("HTTP " + res.status);
                        return res.json();
                    })
                    .then(data => {
                        if (data[metricKey]) {
                            // Convert array of objects to a map { name: value }
                            const mappedData = {};
                            data[metricKey].forEach(metric => {
                                mappedData[metric.name] = metric.value;
                            });

                            updateFlotChart(chartId, mappedData);
                        }
                    })
                    .catch(err => console.warn("Polling failed for", chartId, ":", err));
                }

                document.addEventListener("DOMContentLoaded", () => {
                    pollMetrics();
                    setInterval(pollMetrics, 5000);
                });
            })();
        </script>
    HTML;

    $BxPage->setExtraFooters($plotHtml);
}

function getMetricsData($group = null) {
    static $curlHandle = null;

    $url = "https://127.0.0.1:9092/v2/metrics";

    if ($curlHandle === null) {
        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandle, CURLOPT_FORBID_REUSE, false);   // allow reuse
        curl_setopt($curlHandle, CURLOPT_FRESH_CONNECT, false);  // allow keep-alive
    }

    $response = curl_exec($curlHandle);

    if ($response === false) {
        error_log("getMetricsData() curl error: " . curl_error($curlHandle));
        return [];
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("getMetricsData() JSON decode error: " . json_last_error_msg());
        return [];
    }

    if ($group && isset($data[$group])) {
        return $data[$group];
    }

    return $data;
}

function get_metrics_by_name($parsedMetrics, $targetMetricName) {
    $filteredMetrics = [];

    foreach ($parsedMetrics as $metric) {
        // Check if the metric name contains the target metric name (case-insensitive)
        if (stripos($metric['name'], $targetMetricName) !== false) {
            $filteredMetrics[] = $metric;
        }
    }

    return $filteredMetrics;
}

function get_data($url, $to = "15") {
    $ch = curl_init();
    $timeout = $to;
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_TIMEOUT,$timeout);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BlueLinQ/1.0');
    $error = curl_error($ch);
    $data = curl_exec($ch);
    if($data === false) {
        $data = $error;
    }
    curl_close($ch);
    return $data;
}

/**
 * PoorMansBabelFish($text, $language, $domain)
 *
 * A helper function that translates text via PHP's i18n support. We use this on
 * pages where we can't use Sausalito's i18n support, which needs CceClient.
 *
 * @param VAR   $text           : msgid of the string we need to translate
 * @param VAR   $language       : language identifier. Like de_DE for German.
 * @param VAR   $domain         : name of the gettext file without extension
 * @return VAR translated string
 */

function PoorMansBabelFish ($text, $language, $domain) {

    if ($language === 'browser') {
        $language = 'en_US';
    }

    putenv("LANG=$language"); 
    setlocale(LC_ALL, $language);

    $directory = "/usr/share/locale";

    setlocale( LC_MESSAGES, $language);
    bindtextdomain($domain, $directory);
    textdomain($domain);
    bind_textdomain_codeset($domain, 'UTF-8');

    return gettext($text);
}

function bx_is_HTTPS () {
    if (isset($_SERVER['HTTPS'])) {
        return TRUE;
    }
    else {
        return FALSE;
    }
    return FALSE;
}

function is_HTTPS () {
    if (isset($_SERVER['HTTPS'])) {
        return TRUE;
    }
    else {
        return FALSE;
    }
    return FALSE;
}

function PageURLReferer () {
    if(isset($_SERVER['HTTP_REFERER'])) {
        $ng = $_SERVER['HTTP_HOST'];
        $match = "/^http:(.*)$ng/";
        $baseline = preg_replace($match, '', $_SERVER['HTTP_REFERER']);
    }
    else {
        $baseline = "";
    }
    return $baseline;
}

/**
 * MenuChildren($root, $ignore_items, $_SiteMap_items)
 *
 * A helper function that parses the Menu XML files and returns
 * the $_SiteMap_items object with all menu entries.
 *
 * @param VAR   $root       : ItemID of the menu entry whose childs we're looking for
 * @param ARR   $ignore_items   : array of menu items we ignore in this search
 * @param ARR   $_SiteMap_items : Our array with the complete SiteMap
 * @return ARR $root_children_sort_order
 */

function MenuChildren($root, $bx_ignore_items, $SiteMap_items, $access=array()) {

    // Build an array that contains all children of a given menu entry from our $SiteMap_items:
    $root_children = elements($SiteMap_items[$root]['children'], $SiteMap_items);

    $parent_we_search_for = $root;

    // Build the array $root_children_sort_order which will eventually contain
    // just the 'id' of the child as keys and the 'order' as values.
    // That array will be sorted with the item with the lowest ID comming first.
    //
    // However, there may be some menu items that we don't want to show. They can 
    // be listed in the array $bx_ignore_items. If we have these, we simply ignore
    // them here.

    $root_children_sort_order = array();

    foreach ($root_children as $itemID => $val) {
      if (!in_array($itemID, $bx_ignore_items)) { // See, here we ignore the ignore items:
        if (isset($val['parents'])) {
            $temp_arr = elements(array('id', 'order', 'access', 'requiresChildren', 'children'), $val['parents'], NULL);
            // Catches items which only have a single parent:
            if ($temp_arr['id'] == $parent_we_search_for) {
                // Is this an item that anyone can access? Or one that we specifically have access rights for?
                if (($temp_arr['access'] == NULL) || (in_array($temp_arr['access'], $access))) {
                    // We have access. However, we don't know if this is a 2nd level menu child
                    // or a third level menu entry. So we use getURLofFirstChild() again and see
                    // if it returns an URL or NULL. If it has no URL as return, then this is either
                    // a 2nd level menu with entries to which we don't have access. Or it's a
                    // 3rd level menu entry to which we don't have access. We only add this
                    // entry as child to the parent if we have access and need access:
                    if (getURLofFirstChild($itemID, array(), $SiteMap_items, $access) != NULL) {
                        // We have access, so we add it. But only if we have access to the item 
                        // itself, too:
//                      print_rp($itemID);
//                      print_rp($SiteMap_items[$itemID]['parents']);
//                      print_rp($access);
//                      print_rp($temp_arr['access']);
                        if (isset($SiteMap_items[$itemID]['parents'])) {
                            if (in_array($temp_arr['access'], $SiteMap_items[$itemID]['parents'])) {
                                $root_children_sort_order[$itemID] = $temp_arr['order'];
                            }
                        }
                        else {
                            $root_children_sort_order[$itemID] = $temp_arr['order'];
                        }
                    }
                }
            }
            // Catches items which have multiple parents:
            if (isset($val['parents'][0])) {
                foreach ($val['parents'] as $par_key => $par_val) {
                    $temp_arr = elements(array('id', 'order', 'access', 'requiresChildren', 'children'), $par_val, NULL);
                    if ($temp_arr['id'] == $parent_we_search_for) {
                        // Is this an item that anyone can access? Or one that we specifically have access rights for?
                        if (($temp_arr['access'] == NULL) || (in_array($temp_arr['access'], $access))) {
                            // We have access. However, we don't know if this is a 2nd level menu child
                            // or a third level menu entry. So we use getURLofFirstChild() again and see
                            // if it returns an URL or NULL. If it has no URL as return, then this is either
                            // a 2nd level menu with entries to which we don't have access. Or it's a
                            // 3rd level menu entry to which we don't have access. We only add this
                            // entry as child to the parent if we have access and need access:
                            if (getURLofFirstChild($itemID, array(), $SiteMap_items, $access) != NULL) {
                                // We have access, so we add it:
                                $root_children_sort_order[$itemID] = $temp_arr['order'];
                            }
                        }
                    }
                }
            }
        }
      }
    }
    // Sort $root_children_sort_order by numeric value 'order', lowest first:
    asort($root_children_sort_order);
    return $root_children_sort_order;
}

/**
 * generateSiteMap()
 *
 * Menu related:
 *
 * A helper function that returns an unsorted array of all menu items with all
 * information that is required to build the menu. However, this also contains
 * information that the current user may not be privileged to see.
 *
 * @param  ARR      $debug              : If set to TRUE, it dumps the array with print_rp()
 * @param  ARR      $access             : Access rights of the current users
 * @param  ARR      $CceClient          : Current CceClient Object that this user is using.
 * @return ARR      $_SiteMap_items
 */

function generateSiteMap($debug = FALSE, $access = '', $CceClient = '', $substitutes = '') {

    // Location of the directory with the BX Menus:
    $menu_XML_dir = '/usr/sausalito/ui/chorizo/menu/';

    // Get a fileMap of /usr/sausalito/ui/chorizo/menu/:
    $map = directory_map($menu_XML_dir, FALSE, FALSE);

    // Pre-define array for our XML files:
    $xml_files = array();

    // The fileMap $map is pretty detailed. Let us build an array that has all
    // paths to XML files in it and contains them in an easily accessible way:
    foreach($map as $key => $val) {
        foreach($map[$key] as $key_zwo => $val_zwo) {
            // This handles 'base' and 'vendor' dirs:
            if (is_array($map[$key][$key_zwo])) {
                foreach($map[$key][$key_zwo] as $key_drei => $val_drei) {
                    // We're only interested in XML files:
                    if (preg_match('/\.xml$/', $val_drei)) {
                        $xml_files[] = $menu_XML_dir . "$key" . '/' .  $key_zwo . '/' . $val_drei;
                    }
                }
            }
            else {
                // This handles 'palette' and other short pathed XML locations:
                // We're only interested in XML files:
                if (preg_match('/\.xml$/', $map[$key][$key_zwo])) {
                    $xml_files[] = $menu_XML_dir . "$key" . '/' .  $map[$key][$key_zwo];
                }
            }
        }
    }

    // Set up an empty $_SiteMap_items array:
    $_SiteMap_items = array();

    for($i = 0; $i < count($xml_files); $i++) {
        // Read in each XML file:
        $xml_data = file_get_contents($xml_files[$i]);

        // For debugging - print the path and filename:
        //echo "$xml_files[$i]<pre>";

        // Convert the raw XML data into an array:
        //
        // This is mightily fucking brilliant and fast! Thanks to Eric Potvin for this amazing idea!
        // See: http://www.bookofzeus.com/articles/convert-simplexml-object-into-php-array/
        $xml = json_decode(json_encode((array) simplexml_load_string($xml_data)), 1);

        // Array preparation (we want to start fresh during each iteration):
        $item = array();

        // Start the extraction procedure to get all menu items we need:
        // First create items of the easily extractable information:
        $k = elements(array('id', 'description', 'label', 'type', 'url', 'window', 'imageOff', 'imageOn', 'requiresChildren', 'children', 'module', 'icon', 'elmer_icon', 'icononly'), $xml['@attributes'], NULL);
        $itemId = $item['id'] = $k['id'];
        $item['description'] = $k['description'];
        $item['label'] = $k['label'];
        $item['type'] = $k['type'];
        $item['url'] = fixInternalURLs($k['url'], $substitutes);
        $item['window'] = $k['window'];
        $item['imageOff'] = $k['imageOff'];
        $item['imageOn'] = $k['imageOn'];
        $item['requiresChildren'] = $k['requiresChildren'];
        $item['children'] = $k['children'];
        $item['module'] = $k['module'];
        $item['icon'] = $k['icon'];
        $item['elmer_icon'] = $k['elmer_icon'];
        $item['icononly'] = $k['icononly'];

        // Now the complicated stuff:
        //
        // We need to extract the 'parent id' of this object. To make matters worse: It may have multiple parents!
        // And as if it ain't enought, each of these 'parent id' entries may have an optional access restriction.
        // But CodeIgniter's elements() function is a time saviour, so this is mean and clean:

        // We check if there is a 'parent' field in the array to begin with:
        if (isset($xml['parent'])) {
            // We loop through the results:
            $l = array();
            foreach($xml['parent'] as $key => $val) {
                // If there is directly an '@attributes' element, then this object only has one 'parent':
                if (isset($xml['parent']['@attributes'])) {
                  // Get Id of the single parent, the sort order and the access:
                  $item["parents"] = elements(array('id', 'order', 'access'), $xml['parent']['@attributes'], NULL);
                  // Extract 'access require' correctly as well:
                  if (isset($xml['parent']['access'])) {
                    $l = elements(array('require'), $xml['parent']['access']['@attributes'], NULL);
                  }
                }
                // If the $key is an integer and $val is an array, then we have multiple parents:
                if ((is_int($key) === true) && (is_array($val))) {
                  $item["parents"][] = elements(array('id', 'order', 'access'), $val['@attributes'], NULL);
                  // Extract 'access require' correctly as well:
                  if (isset($xml['parent'][$key]['access']['@attributes']['require'])) {
                    $l['require'][] = $xml['parent'][$key]['access']['@attributes']['require'];
                  }
                }

                // Stuff 'access require' into $item during this post processing:
                if (isset($l['require'])) {
                    // But only do so, if the current user has access to it!
                    if (($l['require'] == NULL) || (in_array($l['require'], $access))) {
                        $item["parents"]['access'] = $l['require'];
                    }
                    else {
                        // This user does not have access to this item.
                        // Remove the item: 
                        unset($item);
                    }
                }
            }
        }
        if (isset($item)) {
            // We still do have an item, so we add it to the $_SiteMap_items:
            $_SiteMap_items["$itemId"] = $item;
        }
    }

    // Now we need to populate the $_SiteMap_items['children'] fields.
    // This makes sure that our siteMap contains not only the entries 
    // to let us know who the parents are, but it also tells us which 
    // children (if any) an item has.
    $itemIds = array_keys($_SiteMap_items);
    foreach ($itemIds as $itemId) {
        $item = $_SiteMap_items[$itemId];
        // Create a list of children for this item
        if (isset($_SiteMap_items[$itemId]["parents"])) {
            $h = array();
            foreach($_SiteMap_items[$itemId]["parents"] as $parentkey => $parentval) {
                // Multiple parents found:
                if ((is_int($parentkey) === true) && (is_array($parentval))) {
                    $h = $parentval['id'];
                    $order = "";
                    // Loop through the various parents:
                    foreach ($item['parents'] as $key => $value) {
                        if ($value['id'] == $parentval['id']) {
                            // Find out the sort order:
                            $order = $value['order'];
                        }
                    }
                    // Make sure the sort order isn't already taken by another menu item:
                    if (isset($_SiteMap_items[$h]['children'][$order])) {
                        print_rp("ERROR: Menu item with the ID '$itemId' has the same sort order as item " . $_SiteMap_items[$h]['children'][$order]);
                        exit;
                    }

                    // Store the sort order of the children:
                    $_SiteMap_items[$h]['children'][$order] = $itemId;
                }
                else {
                    // Single parent found:
                    if ($parentkey == "id") {
                        // Get the sort order:
                        $order = $_SiteMap_items[$itemId]['parents']['order'];
                        // Make sure the sort order isn't already taken by another menu item:
                        if (isset($_SiteMap_items[$parentval]['children'][$order])) {
                            print_rp("ERROR: Menu item with the ID '$itemId' has the same sort order as item " . $_SiteMap_items[$parentval]['children'][$order]);
                            exit;
                        }
                        // Store the sort order of the children:
                        $_SiteMap_items[$parentval]['children'][$order] = $itemId;
                    }
                }
            }
        }
    }

    // At this point our $_SiteMap_items is complete and every item is populated
    // with all info. Like which children it has. What parents it has. And so on.
    // This is the full sitemap and it contains items that the user might not be
    // privileged to see. We handle the actual access rights in the function
    // MenuChildren(), which is called via getURLofFirstChild(). Which in turn
    // is used to populate menu entries with the correct URL of the first item
    // that the user has rights to see.

    if ($debug == TRUE) {
        echo "----_SiteMap_items:----<br>";
        print_rp($_SiteMap_items);
    }

  return $_SiteMap_items;
}

function bx_menu_xml_files(): array {
    static $files = null;
    if ($files !== null) return $files;

    $menu_XML_dir = '/usr/sausalito/ui/chorizo/menu/';
    $map = directory_map($menu_XML_dir, FALSE, FALSE);
    $xml_files = [];

    foreach ($map as $key => $val) {
        foreach ($map[$key] as $key_zwo => $val_zwo) {
            if (is_array($map[$key][$key_zwo])) {
                foreach ($map[$key][$key_zwo] as $val_drei) {
                    if (is_string($val_drei) && str_ends_with($val_drei, '.xml')) {
                        $xml_files[] = $menu_XML_dir . $key . '/' . $key_zwo . '/' . $val_drei;
                    }
                }
            } else {
                $v = $map[$key][$key_zwo];
                if (is_string($v) && str_ends_with($v, '.xml')) {
                    $xml_files[] = $menu_XML_dir . $key . '/' . $v;
                }
            }
        }
    }

    sort($xml_files);
    $files = $xml_files;
    return $files;
}

function bx_menu_version(): string {
    $files = bx_menu_xml_files();
    $h = hash_init('sha1');
    foreach ($files as $f) {
        $st = @stat($f);
        if (!$st) continue;
        hash_update($h, $f . '|' . $st['mtime'] . '|' . $st['size'] . ';');
    }
    return hash_final($h);
}

function bx_cache_get($key) {
    $CI = get_instance();
    if (method_exists($CI, 'rget')) return $CI->rget($key);
    return null;
}

function bx_cache_set($key, $value, $ttl) {
    $CI = get_instance();
    if (method_exists($CI, 'rset')) return $CI->rset($key, $value, $ttl);
    return false;
}

function generateSiteMapCached($access = [], $substitutes = [], $debug = FALSE) {
    static $inproc = [];

    $ver = bx_menu_version();
    $access = is_array($access) ? $access : [];
    sort($access);
    $accessSig = sha1(implode('|', $access));

    // cache WITHOUT substitutes first
    $cacheKey = "admserv:menu:v{$ver}:raw:acc:{$accessSig}";

    // per-request cache
    if (isset($inproc[$cacheKey])) {
        $siteMap = $inproc[$cacheKey];
    } else {
        $siteMap = bx_cache_get($cacheKey);

        if (!is_array($siteMap) || empty($siteMap)) {
            // build it using the original function but with substitutes empty
            $siteMap = generateSiteMap(FALSE, $access, '', []); // CceClient unused anyway
            // long-ish TTL; version key makes it safe
            bx_cache_set($cacheKey, $siteMap, 3600);
        }

        $inproc[$cacheKey] = $siteMap;
    }

    // apply substitutes cheaply (only touches url fields)
    if (is_array($substitutes) && isset($substitutes['group'], $substitutes['fqdn'])) {
        // If you want, you can cache this too, but it’s cheap.
        foreach ($siteMap as $id => $item) {
            if (isset($item['url'])) {
                $siteMap[$id]['url'] = fixInternalURLs($item['url'], $substitutes);
            }
        }
    }

    if ($debug) {
        echo "----_SiteMap_items (cached):----<br>";
        print_rp($siteMap);
    }

    return $siteMap;
}

/**
 *
 *  Simple function to detect if a string is UTF-8 or not.
 *
 */


function detectUTF8($string) {
        return preg_match('%(?:
        [\xC2-\xDF][\x80-\xBF]                  # non-overlong 2-byte
        |\xE0[\xA0-\xBF][\x80-\xBF]             # excluding overlongs
        |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}      # straight 3-byte
        |\xED[\x80-\x9F][\x80-\xBF]             # excluding surrogates
        |\xF0[\x90-\xBF][\x80-\xBF]{2}          # planes 1-3
        |[\xF1-\xF3][\x80-\xBF]{3}              # planes 4-15
        |\xF4[\x80-\x8F][\x80-\xBF]{2}          # plane 16
        )+%xs', $string);
}

function Utf8Encode($text) {
  if (mb_detect_encoding($text, "JIS, UTF-8, EUC-JP, ISO-8859-1, ISO-8859-15, windows-1252") == "EUC-JP") {
    $text = mb_convert_encoding($text, "UTF-8", "EUC-JP");
  }
  if (detectUTF8($text) == "1" ) {
    return $text;
  }
  return BXEncoding::toUTF8($text);
}

/**
 * bx_charsetsafe()
 *
 * This is bloody anoying: Say someone with a Japanese locale sets a username in
 * Japanese. An English serverAdmin or siteAdmin then sees the username garbled.
 * Likewise: German umlauts, or foreign acutes which work fine in 'UTF-8' will look
 * garbled in 'EUC-JP'. So we use this function to sanitize the fullName of users
 * (and other things). 
 *
 * The function takes the string as argument, checks if it is UTF-8 and if not, 
 * converts it. If it is already UTF-8, it gets returned outright. 
 *
 * @param  VAR  $string     : string we want to convert to a safe charset for display
 * @return VAR  $string     : sanitized string or original string
 */

function bx_charsetsafe($string) {

    if (detectUTF8($string) == "1") {
        return BXEncoding::toUTF8($string);
    }
    return $string;
}

/**
 * bx_profiler()
 *
 * A helper function for profiling
 *
 * @param TRUE or FALSE
 * @return TRUE
 */


function bx_profiler($enabled = FALSE) {
  $CI =& get_instance();
  // Profiling and Benchmarking:
  $sections = array(
      'config'  => TRUE,
      'queries' => FALSE,
      'get' => TRUE,
      'http_headers' => TRUE,
      'memory_usage' => TRUE,
      'post' => TRUE,
      'uri_string' => TRUE,
      'controller_info' => TRUE,
      'benchmarks' => TRUE
      );
  $CI->output->set_profiler_sections($sections);
  $CI->output->enable_profiler($enabled);
  return $enabled;
}

/**
 * print_rp()
 *
 * A helper function that mimicks print_r, but encapsulates the results in '<pre></pre>' tags.
 *
 * @param ARR   $prp        : array we want to print
 * @return NONE
 */

function print_rp($prp) {
  echo "<pre>";
  print_r($prp);
  echo "</pre>";
}

/**
 * init_libraries()
 *
 * A helper function for loading all our usual libraries and helpers.
 * Loading them all with two lines of code is more comforting than 
 * having a dozen load->... lines in every controller.
 *
 * @param none
 * @return TRUE
 */

function init_libraries() {

    //  $CI =& get_instance();
    //
    //  // Need to load 'user_agent' as we need to access the browser info:
    //  $CI->load->library('user_agent');
    //  // Need to load 'parser' to load our template parser:
    //  $CI->load->library('parser');
    //  // Need to load the 'cookie' helper:
    //  $CI->load->helper('cookie');
    //  // Load the array helper:
    //  $CI->load->helper('array');
    //  // Load the string helper:
    //  $CI->load->helper('string');
    //  // Load URL helper:
    //  $CI->load->helper('url');
    //  // Load the text helper:
    //  $CI->load->helper('text');
    //
    //  // Load CI helper and libraries for form validation and handling:
    //  $CI->load->helper(array('form', 'url'));
    //  $CI->load->library('form_validation');
    //
    //  // Load Directory helper:
    //  $CI->load->helper('directory');
    //  // Load File helper:
    //  $CI->load->helper('file');
    //
    //  // Need to load 'I18n' for localization and 'CceClient' for access to CCE:
    //  $CI->load->library('I18n');
    //  $CI->load->library('CceClient');
    //  $CI->load->library('BXEncoding');
    //
    //  // Load 'session' library:
    //  //$CI->load->library('session');
    //
    //  // Load UIFC NG library:
    //  $CI->load->helper('uifc_ng');
    //
    //  // Load ArrayPacker:
    //  include_once("ArrayPacker.php");
    //
    //  return TRUE;

}

/**
 * bx_pw_check()
 *
 * A helper function for checking if passwords are good enough.
 *
 * @param TRUE or FALSE
 * @return TRUE
 */

function bx_pw_check($i18n, $password = "", $pass_repeat = "") {

        // Start sane:
        $my_errors = array();

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        // Get loginName:
        $loginName = $BX_SESSION['loginName'];

        if ((!isset($loginName)) || ($loginName == "")) {
            // This handles pw-checks in Wizard. We might not yet have a cookie:
            $loginName = 'admin';
        }

        // We do have a pass_repeat, but it's not identical to the password:
        if (($pass_repeat != "") && ($password != $pass_repeat)) {
            $my_errors[] = ErrorMessage($i18n->interpolate("[[palette.pw_not_identical]]"));
        }
        elseif (strcasecmp($loginName, $password) === 0) {
            // Username == Password? Baaaad idea!
            $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-password-equals-username]]"));
        }
        elseif ((empty($password)) || (empty($pass_repeat))) {
            // Either password or repeat password are empty:
            $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-password-invalid]]") . " ". $i18n->get("[[base-user.error-invalid-password]]"));
        }
        elseif ((preg_match('/"/', $password)) || (preg_match('/&quot;/', $password))) {
            $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-invalid-password]]"));
        }
        elseif ($password) {

            if (function_exists('crack_opendict')) {

                // Open CrackLib Dictionary for usage:
                @$dictionary = crack_opendict('/usr/share/dict/pw_dict');

                // Perform password check with cracklib:
                $check = crack_check($dictionary, $password);

                // Retrieve messages from cracklib:
                $diag = crack_getlastmessage();

                if ($diag == 'strong password') {
                    // Nothing to do. Cracklib thinks it's a good password.
                }
                else {

                    // Parse the return strings from cracklib and localize them:
                    if (preg_match('/^it\'s WAY too short$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_way_too_short]]");
                    }
                    elseif (preg_match('/^it is too short$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_too_short]]");
                    }
                    elseif (preg_match('/^it does not contain enough DIFFERENT characters$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_not_nuff_different]]");
                    }
                    elseif (preg_match('/^it is all whitespace$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_all_whitespace]]");
                    }
                    elseif (preg_match('/^it is too simplistic\/systematic$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_too_simple]]");
                    }
                    elseif (preg_match('/^it looks like a National Insurance (.*)$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_insurance_number]]");
                    }
                    elseif (preg_match('/^it is based on a dictionary word$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_dictionary_word]]");
                    }
                    elseif (preg_match('/^it is based on a \(reversed\) dictionary word$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_reversed_dictionary_word]]");
                    }
                    elseif (preg_match('/^strong password$/', $diag)) {
                        $diag_result = $i18n->getHtml("[[palette.pw_strong_password]]");
                    }
                    else {
                        // In case the localization fails, return the cracklib output directly:
                        $diag_result = $diag;
                    }

                    $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-password-invalid]]") . '<br>' . $diag_result);
                    $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-invalid-password]]"));
                }

                // Close cracklib dictionary:
                crack_closedict($dictionary);
            }
            else {
                // No Cracklib support available. We have alternatives, though:

                $CI =& get_instance();
                // Override the default errors messages
                $hardlang = array(
                'length' => $i18n->getHtml("[[palette.pw_way_too_short]]"),
                'upper'  => $i18n->getHtml("[[palette.pw_not_nuff_different]]"),
                'lower'  => $i18n->getHtml("[[palette.pw_not_nuff_different]]"),
                'numeric'=> $i18n->getHtml("[[palette.pw_too_simple]]"),
                'special'=> $i18n->getHtml("[[palette.pw_too_simple]]"),
                'common' => $i18n->getHtml("[[palette.pw_dictionary_word]]"),
                'environ'=> $i18n->getHtml("[[palette.pw_too_simple]]"));

                // Supply reference of the environment (company, hostname, username, etc)
                $environmental = array('blueonyx', 'admin');
                $sp = new StupidPass(40, $environmental, '/usr/sausalito/ui/chorizo/ci4/app/Libraries/stupid-pass/StupidPass.default.dict', $hardlang);

                if ($sp->validate($password) === false) {
                    $PWerrors = $sp->get_errors();
                    $diag_result = $PWerrors[0];
                    $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-password-invalid]]") . '<br>' . $diag_result);
                    $my_errors[] = ErrorMessage($i18n->get("[[base-user.error-invalid-password]]"));
                }
                else {
                    $diag_result = $i18n->getHtml("[[palette.pw_strong_password]]");
                }
            }
        }

        if (is_array($my_errors)) {
            if (count($my_errors) >= "1") {
                return $my_errors;
            }
        }
}

/**
 * format_bytes()
 *
 * A helper function used by /mysql/mysqlserver/
 *
 * @param SIZE
 * @return SIZE
 */

function format_bytes ( $size ) {
    switch ( $size ) {
        case $size > 1000000:
            return number_format(ceil($size / 1000000)) . "mb";
            break;
        case $size > 1000:
            return number_format(ceil($size / 1000)) . "k";
            break;
        default:
            return number_format($size) . "b";
            break;
    }
}

function br2nl($str) {
   $str = preg_replace("/(\r\n|\n|\r)/", "", $str);
   return preg_replace("=<br */?>=i", "\n", $str);
}

/**
 * str_split_php4()
 *
 * A helper function used by /console/consolelogins
 *
 * @param SIZE
 * @return SIZE
 */

// str_split_php4
function str_split_php4( $text, $min, $max ) {
    // place each character of the string into and array
    $array = array();
    for ( $i=0; $i < strlen( $text ); ){
        $key = NULL;
        for ( $j = 0; $j < $max; $j++, $i++ ) {
            if ($j >= $min) {
                $key .= $text[$i];
            }
        }
        array_push( $array, $key );
    }
    return $array;
}

/**
 * formspecialchars()
 *
 * A helper function used to clean up output to make it HTML-Safe.
 * Can be run on arrays AND strings, but will always return strings.
 * Does safe encoding based on user charset, too.
 *
 * @param $input
 * @return $output
 */


function formspecialchars($var) {
        $pattern = '/&(#)?[a-zA-Z0-9]{0,};/';

        if (is_array($var)) {    // If variable is an array
            $out = array();      // Set output as an array - for now
            foreach ($var as $key => $v) {     
                $out[$key] = formspecialchars($v);         // Run formspecialchars on every element of the array and return the result. Also maintains the keys.
            }
            // Now that we're done with the array, we turn it back into a string:
            $out = implode("", $out);
        } else {
            $out = $var;
            $lang_helper = initialize_languages(FALSE); // will return $lang_helper['charset'] which contains the client used charset
            $out = htmlspecialchars(stripslashes(trim($out)), ENT_QUOTES, $lang_helper['charset']);     // Trim the variable, strip all slashes, and encode it
        }
        return $out;
}

/**
  * 
  * This gets the timezone offset based on the olson code.
  * In this code it is used to find the offset between the given olson code and UTC, but can be used to convert other differences
  * 
  * @param string $remote_tz TZ string
  * @param string $origin_tz TZ string, defaults to UTC
  * @return int offset in seconds
  */

function ln_get_timezone_offset($remote_tz, $origin_tz = 'UTC') {
        $origin_dtz = new DateTimeZone($origin_tz);
        $remote_dtz = new DateTimeZone($remote_tz);
        $origin_dt = new DateTime("now", $origin_dtz);
        $remote_dt = new DateTime("now", $remote_dtz);
        $offset = $remote_dtz->getOffset($remote_dt) - $origin_dtz->getOffset($origin_dt);
        return $offset;
}

/**
 * Converts a timezone difference to be displayed as GMT +/-
 * 
 * @param string $timezone TZ time
 * @return string text with GMT
 */

function ln_get_timezone_offset_text($timezone){
        $time = ln_get_timezone_offset($timezone);
        $minutesOffset = $time/60;
        $hours = floor(($minutesOffset)/60);
        $minutes = abs($minutesOffset%60);
        $minutesFormatted = sprintf('%02d', $minutes);
        $plus = '';
        if($time >= 0){
            $plus = '+';
        }
        if ($timezone == 'UTC') {
            $GMToff = 'UTC '.$plus.$hours.':'.$minutesFormatted;
        }
        else {
            $GMToff = 'GMT '.$plus.$hours.':'.$minutesFormatted;
        }
        return $GMToff;
}

/**
 * This is for formatting how the timezone option displays.
 * It can be converted to include current time, not include gmt or anything like that.
 * 
 * @param string $timezone TZ time
 * @param string $text format select box option
 */
function ln_display_timezone_option($timezone, $text, $value) {
        $selectedTZ = '';
        if ($value == $timezone) {
            $selectedTZ = " SELECTED ";
        }
        $out = '<option ' . $selectedTZ . 'value="' . $timezone .'">' . '(' . ln_get_timezone_offset_text($timezone) .') ' . $text . '</option>' . "\n";
        return $out;
}

/**
 *  The concise list of timezones.  This generates the html wherever it is called
 */

function ln_display_timezone_selector($value = "") {

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $selectLine = '<select name="timezoneSelectDropdown" id="timezoneSelectDropdown" class="form-control select2">' . "\n";

        $out = $selectLine
        . ln_display_timezone_option('Pacific/Auckland', 'International Date Line West', $value)
        . ln_display_timezone_option('Pacific/Midway', 'Midway Island, Samoa', $value)
        . ln_display_timezone_option('US/Hawaii', 'Hawaii', $value)
        . ln_display_timezone_option('US/Alaska', 'Alaska', $value)
        . ln_display_timezone_option('US/Pacific', 'Pacific Time (US & Canada)', $value)
        . ln_display_timezone_option('America/Tijuana', 'Tijuana, Baja California', $value)
        . ln_display_timezone_option('America/Phoenix', 'Arizona', $value)
        . ln_display_timezone_option('America/Chihuahua', 'Chihuahua, La Paz, Mazatlan', $value)
        . ln_display_timezone_option('US/Mountain', 'Mountain Time (US & Canada)', $value)
        . ln_display_timezone_option('America/Cancun', 'Central America', $value)
        . ln_display_timezone_option('US/Central', 'Central Time (US & Canada)', $value)
        . ln_display_timezone_option('America/Mexico_City', 'Guadalajara, Mexico City, Monterrey', $value)
        . ln_display_timezone_option('Canada/Saskatchewan', 'Saskatchewan', $value)
        . ln_display_timezone_option('America/Lima', 'Bogota, Lima, Quito, Rio Branco', $value)
        . ln_display_timezone_option('US/Eastern', 'Eastern Time (US & Canada)', $value)
        . ln_display_timezone_option('US/East-Indiana', 'Indiana (East)', $value)
        . ln_display_timezone_option('Canada/Atlantic', 'Atlantic Time (Canada)', $value)
        . ln_display_timezone_option('America/Caracas', 'Caracas, La Paz', $value)
        . ln_display_timezone_option('America/Manaus', 'Manaus', $value)
        . ln_display_timezone_option('America/Santiago', 'Santiago', $value)
        . ln_display_timezone_option('Canada/Newfoundland', 'Newfoundland', $value)
        . ln_display_timezone_option('America/Sao_Paulo', 'Brasilia', $value)
        . ln_display_timezone_option('America/Argentina/Buenos_Aires', 'Buenos Aires, Georgetown', $value)
        . ln_display_timezone_option('America/Godthab', 'Greenland', $value)
        . ln_display_timezone_option('America/Montevideo', 'Montevideo', $value)
        . ln_display_timezone_option('Atlantic/South_Georgia', 'Mid-Atlantic', $value)
        . ln_display_timezone_option('Atlantic/Cape_Verde', 'Cape Verde Is.', $value)
        . ln_display_timezone_option('Atlantic/Azores', 'Azores', $value)
        . ln_display_timezone_option('Africa/Casablanca', 'Casablanca, Monrovia, Reykjavik', $value)
        . ln_display_timezone_option('UTC', 'Greenwich Mean Time : Dublin, Edinburgh, Lisbon, London', $value)
        . ln_display_timezone_option('BST', 'British Summer Time : Dublin, Edinburgh, Liverpool, London', $value)
        . ln_display_timezone_option('Europe/Amsterdam', 'Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna', $value)
        . ln_display_timezone_option('Europe/Belgrade', 'Belgrade, Bratislava, Budapest, Ljubljana, Prague', $value)
        . ln_display_timezone_option('Europe/Brussels', 'Brussels, Copenhagen, Madrid, Paris', $value)
        . ln_display_timezone_option('Europe/Sarajevo', 'Sarajevo, Skopje, Warsaw, Zagreb', $value)
        . ln_display_timezone_option('Africa/Windhoek', 'West Central Africa', $value)
        . ln_display_timezone_option('Asia/Amman', 'Amman', $value)
        . ln_display_timezone_option('Europe/Athens', 'Athens, Bucharest, Istanbul', $value)
        . ln_display_timezone_option('Asia/Beirut', 'Beirut', $value)
        . ln_display_timezone_option('Africa/Cairo', 'Cairo', $value)
        . ln_display_timezone_option('Africa/Harare', 'Harare, Pretoria', $value)
        . ln_display_timezone_option('Europe/Helsinki', 'Helsinki, Kyiv, Riga, Sofia, Tallinn, Vilnius', $value)
        . ln_display_timezone_option('Asia/Jerusalem', 'Jerusalem', $value)
        . ln_display_timezone_option('Europe/Minsk', 'Minsk', $value)
        . ln_display_timezone_option('Africa/Windhoek', 'Windhoek', $value)
        . ln_display_timezone_option('Asia/Kuwait', 'Kuwait, Riyadh, Baghdad', $value)
        . ln_display_timezone_option('Europe/Moscow', 'Moscow, St. Petersburg, Volgograd', $value)
        . ln_display_timezone_option('Africa/Nairobi', 'Nairobi', $value)
        . ln_display_timezone_option('Asia/Tbilisi', 'Tbilisi', $value)
        . ln_display_timezone_option('Asia/Tehran', 'Tehran', $value)
        . ln_display_timezone_option('Asia/Muscat', 'Abu Dhabi, Muscat', $value)
        . ln_display_timezone_option('Asia/Baku', 'Baku', $value)
        . ln_display_timezone_option('Asia/Yerevan', 'Yerevan', $value)
        . ln_display_timezone_option('Asia/Kabul', 'Kabul', $value)
        . ln_display_timezone_option('Asia/Yekaterinburg', 'Yekaterinburg', $value)
        . ln_display_timezone_option('Asia/Karachi', 'Islamabad, Karachi, Tashkent', $value)
        . ln_display_timezone_option('Asia/Kolkata', 'Sri Jayawardenepura', $value)
        . ln_display_timezone_option('Asia/Kolkata', 'Chennai, Kolkata, Mumbai, New Delhi', $value)
        . ln_display_timezone_option('Asia/Kathmandu', 'Kathmandu', $value)
        . ln_display_timezone_option('Asia/Almaty', 'Almaty, Novosibirsk', $value)
        . ln_display_timezone_option('Asia/Dhaka', 'Astana, Dhaka', $value)
        . ln_display_timezone_option('Asia/Rangoon', 'Yangon (Rangoon)', $value)
        . ln_display_timezone_option('Asia/Bangkok', 'Bangkok, Hanoi, Jakarta', $value)
        . ln_display_timezone_option('Asia/Krasnoyarsk', 'Krasnoyarsk', $value)
        . ln_display_timezone_option('Asia/Shanghai', 'Beijing, Chongqing, Hong Kong, Urumqi', $value)
        . ln_display_timezone_option('Asia/Singapore', 'Kuala Lumpur, Singapore', $value)
        . ln_display_timezone_option('Asia/Irkutsk', 'Irkutsk, Ulaan Bataar', $value)
        . ln_display_timezone_option('Australia/Perth', 'Perth', $value)
        . ln_display_timezone_option('Asia/Taipei', 'Taipei', $value)
        . ln_display_timezone_option('Asia/Tokyo', 'Osaka, Sapporo, Tokyo', $value)
        . ln_display_timezone_option('Asia/Seoul', 'Seoul', $value)
        . ln_display_timezone_option('Asia/Yakutsk', 'Yakutsk', $value)
        . ln_display_timezone_option('Australia/Adelaide', 'Adelaide', $value)
        . ln_display_timezone_option('Australia/Darwin', 'Darwin', $value)
        . ln_display_timezone_option('Australia/Brisbane', 'Brisbane', $value)
        . ln_display_timezone_option('Australia/Sydney', 'Canberra, Melbourne, Sydney', $value)
        . ln_display_timezone_option('Australia/Hobart', 'Hobart', $value)
        . ln_display_timezone_option('Pacific/Guam', 'Guam, Port Moresby', $value)
        . ln_display_timezone_option('Asia/Vladivostok', 'Vladivostok', $value)
        . ln_display_timezone_option('Asia/Magadan', 'Magadan, Solomon Is., New Caledonia', $value)
        . ln_display_timezone_option('Pacific/Auckland', 'Auckland, Wellington', $value)
        . ln_display_timezone_option('Pacific/Fiji', 'Fiji, Kamchatka, Marshall Is.', $value)
        . ln_display_timezone_option('Pacific/Tongatapu', 'Nuku\'alofa', $value)
        . '</select>';
    return $out;
}

// description: converts a array into a CCE-encoded scalar
function array_to_scalar( $array ) {
$result = "&";
    if (is_array($array)) {
        $result = "&";
        foreach($array as $value) {
                //$value = preg_replace("/([^A-Za-z0-9_\. -])/e", "sprintf('%%%02X', ord('\\1'))", $value);
                //$value = preg_replace_callback("/([^A-Za-z0-9_\. -])/", function ($m) { return "sprintf('%%%02X', ord('\\1'))"; }, $value);
                $value = preg_replace_callback("/([^A-Za-z0-9_\. -])/", function ($m) { return sprintf('%%%02X', ord($m[1])); }, $value);
                $value = preg_replace("/ /", "+", $value);

                $result .= $value . "&";
        }
    }
    if ($result == "&") $result = "";
    return $result;
}

// description: converts a CCE-encoded scalar into an array
function scalar_to_array( $scalar ) {
    // just in case trim off whitespace
    if (!is_array($scalar)) {
        $scalar = trim($scalar);
    }
    else {
        // We already got an array, so return that one instead:
        return $scalar;
    }
    $scalar = preg_replace("/^&/", "", $scalar);
    $scalar = preg_replace("/&$/", "", $scalar);
    $array = explode("&", $scalar);
    for($i = 0; $i < count($array); $i++) {
      $array[$i] = preg_replace("/\+/", " ", $array[$i]);
      //$array[$i] = preg_replace("/%([0-9a-fA-F]{2})/e", "chr(hexdec('\\1'))", $array[$i]);
      $array[$i] = preg_replace_callback("/%([0-9a-fA-F]{2})/", function ($m) { return chr(hexdec($m[1])); }, $array[$i]);
    }
    return $array;
}

// description: converts a string to a CCE-encoded scalar. 
// This is new as of 5200R and is a necessity due to CodeIgniters
// XSS cleaning of our form data:
function string_to_scalar ($string) {
  // Just in case trim off whitespace:
  $string = trim($string);

  // Strip leading and trailing "&" - just in case as well:
  $string = preg_replace("/^&/", "", $string);
  $string = preg_replace("/&$/", "", $string);

  // Strip excess whitespaces:
  $string = preg_replace("/\s\s+/", " ", $string);

  // Replace ", " with "&":
  $string = preg_replace("/,[\s+]{0,999}/i", "&", $string);

  // Replace "\n" with "&":
  $string = preg_replace("/\n/i", "&", $string);

  // Build scalar:
  if ($string) {
    $scalar = "&" . $string . "&";
  }
  else {
    $scalar = "";
  }

  return $scalar;
}

// description: converts a CCE-encoded scalar into a string:
function scalar_to_string($scalar, $delimiter='\n') {
  if (preg_match("/^\&(.*)\&$/", $scalar, $regs)) {
    $value = implode($delimiter, stringToArray($scalar));
  }
  else {
    $value = $scalar;
  }
  return $value;
}

function array_merge_alt($a, $b) {
    $new = array();
    $new = $a;
    foreach ( $b as $line ) {
        $key = array_search($line, $a);
        if ( $key === FALSE ) {
            if ( $line ) {
                $new[] = $line;
            }
        }
    }
    return $new;
}

function removeElementWithValue($array, $key, $value){
     foreach($array as $subKey => $subArray){
          if($subArray[$key] == $value){
               unset($array[$subKey]);
          }
     }
     return $array;
}

function createRandomPassword($length='7', $type='alpha') {

    // Get CI instance and load library uifc/PasswordGenerator.php:
    $CI =& get_instance();

    // Can return random passwords of varius length and type.
    // Supported types:
    //
    // - 'ascii'
    // - 'hex'
    // - 'alpha' (alphanumeric)
    // - 'custom' (not supported by us at this time)

    if ($type == 'ascii') {
        return PasswordGenerator::getASCIIPassword($length);
    }
    elseif ($type == 'hex') {
        return PasswordGenerator::getHexPassword($length);
    }
    else {
        return PasswordGenerator::getAlphaNumericPassword($length);
    }
}

function ja_wordwrap($string, $charlim = '76') { // $charlim is treated as visualwidth.
    $folded = "";
    $room   = $charlim;
    $chars  = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $char) {
        $vw = strlen(mb_convert_encoding($char, 'SJIS', 'UTF-8'));
        if ($char == "\n") {
            $folded .= $char;
            $room    = $charlim;
            continue;
        }
        if ($room >= $vw) {
            $folded .= $char;
            $room   -= $vw;
        }
        else {
            $folded .= "\n".$char;
            $room    = $charlim-$vw;
        }
    }
    return $folded;
} 

// Cookie Delete - set expiration time of cookie to one hour ago:
function delete_cookie($cook_name) {
    setcookie($cook_name, "", time() - 3600);
}

function bx_session_encrypt($textToEncrypt, $encryptionMethod='AES-256-CBC', $secretHash='', $iv='34857d973953e44a') {

    if (empty($textToEncrypt)) {
        return '';
    }

    if (empty($encryptionMethod)) {
        $encryptionMethod = "AES-256-CBC";
    }

    if ((isset($_COOKIE['sessionId'])) && (empty($secretHash))) {
        $secretHash = $_COOKIE['sessionId'];
    }

    if (empty($iv)) {
        $ivlen = openssl_cipher_iv_length($encryptionMethod);
        $iv = openssl_random_pseudo_bytes($ivlen);
    }

    //To encrypt
    $encryptedMessage = openssl_encrypt($textToEncrypt, $encryptionMethod, $secretHash, '0', $iv);

    //To Decrypt
    $decryptedMessage = openssl_decrypt($encryptedMessage, $encryptionMethod, $secretHash, '0', $iv);

    return array('encryptedMessage' => $encryptedMessage, 'decryptedMessage' => $decryptedMessage);
}

/**
 * Generates random string consisting of ASCII chars
 *
 * @param int  $length Length of string
 * @param bool $asHex  (optional) Send the result as hex
 */
function generateRandom(int $length, bool $asHex = false): string {
    $result = '';

    while (strlen($result) < $length) {
        // Get random byte and strip highest bit
        // to get ASCII only range
        $byte = ord(random_bytes(1)) & 0x7f;
        // We want only ASCII chars and no DEL character (127)
        if ($byte <= 32 || $byte === 127) {
            continue; 
        }

        $result .= chr($byte);
    }

    return $asHex ? bin2hex($result) : $result;
}

/**
 * Elements
 *
 * Returns only the array items specified. Will return a default value if
 * it is not set.
 *
 * @param       array
 * @param       array
 * @param       mixed
 * @return      mixed   depends on what the array contains
 */
function elements($items, array $array, $default = NULL) {
        $return = array();

        is_array($items) OR $items = array($items);

        foreach ($items as $item) {
            $return[$item] = array_key_exists($item, $array) ? $array[$item] : $default;
        }

        return $return;
}

function find_eth_ifaces() {
    $eth_ifaces = [];

    $output = shell_exec("LC_ALL=C /usr/sbin/ip link show 2>/dev/null");
    if ($output === null) {
        return $eth_ifaces;
    }

    $lines = explode("\n", $output);

    // Match interface name before the first colon, e.g., "2: enp5s0:"
    $pattern = '/^\d+:\s+([a-zA-Z0-9@._-]+):/';

    foreach ($lines as $line) {
        if (preg_match($pattern, $line, $matches)) {
            $iface = $matches[1];

            // Only accept certain interface types
            if (preg_match('/^(eth[0-9]+(:[0-9]+)?|br[0-9]+|venet[0-9]+(:[0-9]+)?|en[a-z0-9]+(:[0-9]+)?|wlan[0-9]+(:[0-9]+)?|wwan[0-9]+(:[0-9]+)?|bond[0-9]+(:[0-9]+)?)$/', $iface)) {

                // Skip veth interfaces
                if (preg_match('/^veth/', $iface)) {
                    continue;
                }

                // If it's br0, try to find its slave and use that instead
                if ($iface === 'br0') {
                    $primary_interface = shell_exec("/usr/sbin/ifconfig | /usr/bin/grep -E '^[a-zA-Z0-9]+' | /usr/bin/grep -v '^br' | /usr/bin/grep -v 'veth' | /usr/bin/awk '{print \$1}' | /usr/bin/sed 's/://g' | head -1");
                    $primary_interface = $primary_interface !== null ? trim($primary_interface) : '';
                    if ($primary_interface && !in_array($primary_interface, $eth_ifaces)) {
                        $eth_ifaces[] = $primary_interface;
                    }
                } else {
                    if (!in_array($iface, $eth_ifaces)) {
                        $eth_ifaces[] = $iface;
                    }
                }
            }
        }
    }

    return $eth_ifaces;
}

function get_primary_interface($override=FALSE) {
    $primary_interface = '';

    // Get the default routes
    $routes = shell_exec("/usr/sbin/ip route | /usr/bin/grep default");
    if ($routes !== null) {
        $routes = explode("\n", trim($routes));

        // Check the routes to find the primary interface
        foreach ($routes as $route) {
            if (strpos($route, 'linkdown') === false) {
                if (preg_match('/\bdev\s+(\S+)/', $route, $matches)) {
                    $primary_interface = $matches[1];
                    break;
                }
            }
        }
    }

    // If no primary interface found, fallback to ifconfig
    if ($primary_interface === '') {
        $primary_interface = shell_exec("/usr/sbin/ifconfig | /usr/bin/grep -E '^[a-zA-Z0-9]+' | /usr/bin/awk '{print \$1}' | /usr/bin/sed 's/://g' | /usr/bin/head -1");
        $primary_interface = $primary_interface !== null ? trim($primary_interface) : '';
    }

    if (!$override) {
        // If the primary network interface is reported as 'br0', then we check what the bridge slave is and report that back instead. 
        // After all: CODB stores the data for the br0 bridge in the 'Network' object of the primary network interface (eth0 for example):
        if ($primary_interface === 'br0') {
            $primary_interface = shell_exec("/usr/sbin/ifconfig | /usr/bin/grep -E '^[a-zA-Z0-9]+' | /usr/bin/grep -v '^br' | /usr/bin/awk '{print \$1}' | /usr/bin/sed 's/://g' | head -1");
            $primary_interface = $primary_interface !== null ? trim($primary_interface) : '';
        }
    }

    return $primary_interface;
}

function get_primary_ipv4_ip($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    // Get IPv4 IP
    $ipv4_ip = shell_exec("LC_ALL=C /usr/sbin/ip -o address show $actual_device | /usr/bin/grep -v inet6 | /usr/bin/grep '$actual_device\\\\' | /usr/bin/awk '{print \$4}' | /usr/bin/cut -d / -f1    | /usr/bin/head -1");

    return $ipv4_ip !== null ? trim($ipv4_ip) : '';
}

function get_primary_ipv4_netmask($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    // Get primary IPv4
    $ipv4_ip = get_primary_ipv4_ip($actual_device);

    // Get IPv4 Netmask
    $ipv4_nm = shell_exec("LC_ALL=C /usr/sbin/ip -o address show $actual_device | /usr/bin/grep -v inet6 | /usr/bin/grep '$actual_device\\\\' | /usr/bin/awk '{print \$4}' | /usr/bin/cut -d / -f2    | /usr/bin/head -1");
    $ipv4_nm = $ipv4_nm !== null ? trim($ipv4_nm) : '';

    if ($ipv4_nm !== '') {
        $ipv4_nm = long2ip(-1 << (32 - (int)$ipv4_nm));
    }

    // Fallback if we have no NETMASK yet
    if ($ipv4_nm === '' && $ipv4_ip !== '') {
        $ipv4_nm = shell_exec("LC_ALL=C /usr/sbin/ifconfig | /usr/bin/grep $ipv4_ip | /usr/bin/awk '{print \$4}'");
        $ipv4_nm = $ipv4_nm !== null ? trim($ipv4_nm) : '';
    }

    return $ipv4_nm;
}

function get_primary_ipv4_gateway($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    // Get IPv4 Gateway
    $ipv4_gw = shell_exec("LC_ALL=C /usr/sbin/ip route show dev $actual_device | /usr/bin/awk '/default/ {print \$3}'");
    return $ipv4_gw !== null ? trim($ipv4_gw) : '';
}

function get_primary_ipv6_ip($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    // Get IPv6 IP
    $ipv6_ip = shell_exec("LC_ALL=C /usr/sbin/ip -6 addr show dev $actual_device | /usr/bin/grep inet6 | /usr/bin/awk '{ print \$2 }' | /usr/bin/cut -d / -f1 | /usr/bin/grep -v '^fe80::' | /usr/bin/head -1");

    return $ipv6_ip !== null ? trim($ipv6_ip) : '';
}

function get_primary_ipv6_gateway($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    // Get IPv6 Gateway
    $ipv6_gw = shell_exec("LC_ALL=C /usr/sbin/ip -6 route show dev \"$actual_device\" | /usr/bin/awk '/default/ {print \$3}' | /usr/bin/head -1");
    return $ipv6_gw !== null ? trim($ipv6_gw) : '';
}

function get_primary_dns_servers($actual_device = null) {
    // If no device is provided, use the primary network interface
    if ($actual_device === null) {
        $actual_device = get_primary_interface(TRUE);
    }

    $dns_servers = [];

    // Run the nmcli command to get DNS information
    $output = shell_exec("/usr/bin/nmcli device show $actual_device");
    if ($output !== null) {
        // Parse the output to extract DNS servers
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/IP[46]\.DNS\[\d+\]:\s*(\S+)/', $line, $matches)) {
                $dns_servers[] = $matches[1];
            }
        }
    }

    return $dns_servers;
}

function bx_error_log($msg='') {
    error_log("$msg" . PHP_EOL, 3, '/var/log/gui-debug.log');
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
