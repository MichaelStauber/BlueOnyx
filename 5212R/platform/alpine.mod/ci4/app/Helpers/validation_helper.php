<?php

/**
 * BlueOnyx Validation Helper Library
 *
 * BlueOnyx Validation Helper for Codeigniter
 *
 * @package   CI Blueonyx
 * @author    Michael Stauber
 * @copyright Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
 * @link      http://www.solarspeed.net
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   1.0
 */


function bx_validation($reset=FALSE) {

    $CI =& get_instance();
    $BX_SESSION = $CI->getBX_SESSION();

    $cachedValidation = FALSE;

    if ((session()->get('cachedValidation')) && ($reset === FALSE)) {
        $cachedValidation = session()->get('cachedValidation');
        return $cachedValidation;
    }

    $locale = $BX_SESSION['locale'];
    $localization = $BX_SESSION['localization'];
    $charset = $BX_SESSION['charset'];

    $data = array();

    // Location of the directory with the BX Schema files:
    $menu_XML_dir = '/usr/sausalito/schemas/';

    // Get a fileMap of /usr/sausalito/schemas/:
    $map = directory_map($menu_XML_dir, FALSE, FALSE);

    // Pre-define array for our XML schema files:
    $xml_files = array();

    // The fileMap $map is pretty detailed. Let us build an array that has all
    // paths to XML files in it and contains them in an easily accessible way. 
    foreach($map as $key => $val) {
        if (is_array($val)) {
            foreach($map[$key] as $key_zwo => $val_zwo) {
                // This handles 'base' and 'vendor' dirs:
                if (is_array($map[$key][$key_zwo])) {
                    foreach($map[$key][$key_zwo] as $key_drei => $val_drei) {
                        // We're only interested in .schema files:
                        if (preg_match('/\.schema$/', $val_drei)) {
                            $xml_files[] = $menu_XML_dir . "$key" . '/' .  $key_zwo . '/' . $val_drei;
                        }
                    }
                }
                else {
                    // This handles short pathed XML locations:
                    // We're only interested in .schema files:
                    if (preg_match('/\.schema$/', $map[$key][$key_zwo])) {
                        $xml_files[] = $menu_XML_dir . "$key" . '/' .  $map[$key][$key_zwo];
                    }
                }
            }
        }
        else {
            // This handles the toplevel dir:
            // We're only interested in .schema files:
            if (preg_match('/\.schema$/', $map[$key])) {
                $xml_files[] = $menu_XML_dir . $map[$key];
            }
        }
    }

    // Set up an empty $_Schema_Items array:
    $_Schema_Items = array();

    // Populate $_Schema_Items:
    // Unfortunately our *.schema XML files are really dirty and neither 
    // simplexml or DOMXML can get at the data without throwing fits. So we have
    // to rely on simple regular expressions and preg_match_all() and preg_match()
    // to populate $_Schema_Items with the typref data that we want:
    for($i = 0; $i < count($xml_files); $i++) {
        // Read in each XML file:
        $xml_data = file_get_contents($xml_files[$i]);

        // Preg match all <typedef(.*)/> tags:
        preg_match_all('#<typedef(.*)/>#isU',$xml_data,$matches, PREG_SET_ORDER);

        foreach ($matches as $key => $val) {
            if (isset($val)) {
                foreach ($val as $key_zwo => $val_zwo) {
                    if (isset($val_zwo)) {
                        preg_match('#name\s{0,3}=\s{0,3}"(.*)"#isU',$val_zwo,$name);
                        preg_match('#type\s{0,3}=\s{0,3}"(.*)"#isU',$val_zwo,$type);
                        if (preg_match('#data\s{0,3}=\s{0,3}"(.*)"#isU',$val_zwo,$data)) {
                            $my_data = $data[1];
                        }
                        else {
                            $my_data = "";
                        }
                        if (preg_match('#errmsg\s{0,3}=\s{0,3}"(.*)"#isU',$val_zwo,$errmsg)) {
                            $my_errmsg = $errmsg[1];
                        }
                        else {
                            $my_errmsg = "";
                        }
                        $_Schema_Items[$name[1]] = array(
                                            'type' => $type[1],
                                            'data' => json_encode($my_data), 
                                            'errmsg' => $my_errmsg,
                                            'schemafile' => $xml_files[$i]
                                            );
                    }
                }
            }
        }
    }

    $i18n = new I18n("palette", $locale);

    // Prepare the messages output for our jQuery script:

    // These are for the checks that are already included in the stock validator.js:
    $messages = array(
                'required' => $i18n->getHtml("[[palette.val_required]]"), 
                'remote"' => $i18n->getHtml("[[palette.val_remote]]"), 
                'email' => $i18n->getHtml("[[palette.val_email]]"),
                'url' => $i18n->getHtml("[[palette.val_url]]"),
                'date' => $i18n->getHtml("[[palette.val_date]]"),
                'dateISO' => $i18n->getHtml("[[palette.val_dateISO]]"),
                'number' => $i18n->getHtml("[[palette.val_number]]"),
                'digits' => $i18n->getHtml("[[palette.val_digits]]"),
                'creditcard' => $i18n->getHtml("[[palette.val_creditcard]]"),
                'equalTo' => $i18n->getHtml("[[palette.val_equalTo]]"),
                'accept' => $i18n->getHtml("[[palette.val_accept]]"),
                'maxlength' => $i18n->getHtml("[[palette.val_maxlength]]"),
                'minlength' => $i18n->getHtml("[[palette.val_minlength]]"),
                'rangelength' => $i18n->getHtml("[[palette.val_rangelength]]"),
                'range:' => $i18n->getHtml("[[palette.val_range]]"),
                'max' => $i18n->getHtml("[[palette.val_max]]"),
                'min' => $i18n->getHtml("[[palette.val_min]]")
        );

    // We now add our schema based BlueOnyx checks to that list:
    foreach ($_Schema_Items as $key => $value) {
        if ($_Schema_Items[$key]['errmsg']) {
            // Schema rule has own error message:
            $messages[$key] = $i18n->getHtml($_Schema_Items[$key]['errmsg']);
        }
        else {
            // Schema rule has no own error message. Add the default one ("Fix your input!"):
            //$messages[$key . '|' . $_Schema_Items[$key]['schemafile']] = $i18n->getHtml("[[palette.val_remote]]");
            $messages[$key] = $i18n->getHtml("[[palette.val_remote]]");
        }
    }

    // Next item: The actual checks. They look roughly like this:
    //
    //      // http://docs.jquery.com/Plugins/Validation/Methods/dateISO
    //      dateISO: function(value, element) {
    //          return this.optional(element) || /^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/.test(value);
    //      },

    // The 'delegate' stuff looks like this:
    //
    //      "[type='number'], [type='search'] ,[type='tel'], [type='url'], " +

    // Class stuff:
    //
    //      creditcard: {creditcard: true}

    $new_data = array();

    foreach ($_Schema_Items as $key => $value) {
        if ($key == "fqdn") {
            $new_data[$key] = '(?=^.{1,254}$)(^(?:(?!\d+\.)[a-zA-Z0-9_\-]{1,63}\.?)+(?:[a-zA-Z]{2,})$)';
        }
        elseif (($key === "network") || ($key === 'schedule_filename_type')) {
            // Bad rule. Skip.

        }
        else {
            //$new_data[$key . '|' . $_Schema_Items[$key]['schemafile']] = strtr(json_decode($_Schema_Items[$key]['data']), array('\\\\' => "\\"));
            $new_data[$key] = strtr(json_decode($_Schema_Items[$key]['data']), array('\\\\' => "\\"));
        }
    }

    // Include errors:
    $new_data['MESSAGES'] = $messages;

    // Cache $new_data:
    $data['cachedValidation'] = $new_data;
    session()->set($data);

    // return $data;
    return $new_data;
}

/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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