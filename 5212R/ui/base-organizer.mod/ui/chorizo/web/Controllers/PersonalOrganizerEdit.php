<?php 
namespace Organizer\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PersonalOrganizerEdit extends BaseController {

    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }
    
    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // get Radicale info from CCE:
        $radicale = $CI->cceClient->getObject("System", array(), "Radicale");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-organizer", "/organizer/personalOrganizerEdit");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Validate GET data:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $get_form_data = $BxPage->getGETPOST('GET');

        if (isset($get_form_data['id'])) {
            // We have a delete transaction:
            $collection_id = $get_form_data['id'];
        }
        else {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        //
        //-- Prepare data:
        //

        $possible_collection_tags = array('VCALENDAR', 'VADDRESSBOOK');

        $payload = '';
        $calendars = [];
        $addressbooks = [];

        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/bin/radicale_list.pl " . $BX_SESSION['loginName'], $payload, 'root', $BX_SESSION['sessionId']);

        if ($ret == 0) {
            // Decode the returned JSON data into an associative array:
            $data = json_decode($payload, true);

            // Check if decoding was successful
            if ($data === null) {
                $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorDecodingCollection]]"));
            }

            if (isset($data['props'])) {
                // Get the "props" array from $data
                $props = $data['props'];

                // Loop through each key-value pair in "props"
                foreach ($props as $key => $value) {
                    // Decode the JSON value
                    $decodedValue = json_decode($value, true);

                    // Check if decoding was successful
                    if ($decodedValue === null) {
                        echo "Error decoding JSON value for key: $key\n";
                        continue;
                    }

                    // Update the value in the "props" array with the decoded value
                    $props[$key] = $decodedValue;
                }

                // Update the modified "props" array back in $data
                $data['props'] = $props;

                // Isolate Calendars and Addressbooks (if present):
                $props = $data['props'];
                foreach ($props as $key => $prop) {
                    if (isset($prop['tag']) && $prop['tag'] === 'VCALENDAR') {
                        $calendars[$key] = $prop;
                    } elseif (isset($prop['tag']) && $prop['tag'] === 'VADDRESSBOOK') {
                        $addressbooks[$key] = $prop;
                    }
                }
            }
            else {
                $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorDecodingCollection]]"));
            }

        }
        else {
            // No Collection data yet!
            $data = array();
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text");

        if ((is_array($form_data)) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Function GetFormAttributes() walks through the $form_data and returns us the $parameters we want to
            // submit to CCE. It intelligently handles checkboxes, which only have "on" set when they are ticked.
            // In that case it pulls the unticked status from the hidden checkboxes and addes them to $parameters.
            // It also transformes the value of the ticked checkboxes from "on" to "1". 
            //
            // Additionally it generates the form_validation rules for CodeIgniter.
            //
            // params: $i18n                i18n Object of the error messages
            // params: $form_data           array with form_data array from CI
            // params: $required_keys       array with keys that must have data in it. Needed for CodeIgniter's error checks
            // params: $ignore_attributes   array with items we want to ignore. Such as Labels.
            // params: $BxPage              our already declared $BxPage Object (for storing validation Errors)
            // return:                      array with keys and values ready to submit to CCE.
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

            // Get potential errors that GetFormAttributes() ran into from $BxPage:
            $errors = array_merge($errors, $BxPage->getErrors());
        }

        //
        //--- Own error checks:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            $Type_Choices_Reverse = array(
                'ADDRESSBOOK' => 'VADDRESSBOOK',
                'VEVENT' => 'CALENDAR',
                'VJOURNAL' => 'JOURNAL',
                'VTODO' => 'TASKS',
                'CALENDAR_JOURNAL_TASKS' => 'VEVENT,VJOURNAL,VTODO',
                'CALENDAR_JOURNAL' => 'VEVENT,VJOURNAL',
                'CALENDAR_TASKS' => 'VEVENT,VTODO',
                'CALENDAR' => 'VTODO',
                'JOURNAL_TASKS' => 'VJOURNAL,VTODO'
            );

            // Handle Addressbook modifications:
            if (isset($attributes['col_type'])) {
                if ($attributes['col_type'] == 'ADDRESSBOOK') {

                    $attributes['col_type'] = $Type_Choices_Reverse[$attributes['col_type']];

                    if (!isset($attributes['col_desc'])) {
                        $attributes['col_desc'] = '';
                    }

                    if (strlen($attributes['colorInput']) < 8) {
                        $attributes['colorInput'] = $attributes['colorInput'] . 'ff';
                    }

                    $attributes_addressbook = array(
                                                    'D:displayname' => $attributes['col_name'],
                                                    'CR:addressbook-description' => $attributes['col_desc'],
                                                    'tag' => $attributes['col_type'],
                                                    '{http://inf-it.com/ns/ab/}addressbook-color' => $attributes['colorInput']
                                                    );

                    // Convert to JSON and reformat it a little to make it identical to what Radicale usually uses:
                    $addressbook_json_out = json_encode($attributes_addressbook, JSON_UNESCAPED_SLASHES);
                    $addressbook_json_out = preg_replace('/,(?![^\{\[]*[\}\]])/', ', ', $addressbook_json_out);
                    $addressbook_json_out = preg_replace('/":"(.*?)"/', '": "$1"', $addressbook_json_out);

                    // Update .Radicale.props file of this collection:
                    $ret = $CI->serverScriptHelper->shell("/usr/sausalito/bin/radicale_update.pl " . $BX_SESSION['loginName'] . " $collection_id '$addressbook_json_out'" , $payload, 'root', $BX_SESSION['sessionId']);
                    if ($ret == 0) {
                        $BxPage->ReturnToThisPage($errors, "/organizer/personalOrganizer");
                    }
                    else {
                        $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorUpdatingCollection]]"));
                        $BxPage->ReturnToThisPage($errors);
                    }
                }
                else {
                    // If it is not an ADDRESSBOOK, then it's one of the various calendar types:
                    if ($attributes['col_type'] == 'VCALENDAR') {

                        // Is calendar type known?
                        if (in_array($attributes['col_subtype'], array_keys($Type_Choices_Reverse))) {
                            $attributes['col_subtype'] = $Type_Choices_Reverse[$attributes['col_subtype']];
                        }
                        else {
                            // Retain previous value:
                            $attributes['col_subtype'] = $calendars[$collection_id]['C:supported-calendar-component-set'];
                        }

                        if (!isset($attributes['col_desc'])) {
                            $attributes['col_desc'] = '';
                        }

                        if (strlen($attributes['colorInput']) < 8) {
                            $attributes['colorInput'] = $attributes['colorInput'] . 'ff';
                        }

                        $attributes_calendar = array(
                                                        'C:calendar-description' => $attributes['col_desc'],
                                                        'C:supported-calendar-component-set' => $attributes['col_subtype'], 
                                                        'D:displayname' => $attributes['col_name'],
                                                        'ICAL:calendar-color' => $attributes['colorInput'],
                                                        'tag' => $attributes['col_type']
                                                        );

                        // Convert to JSON and reformat it a little to make it identical to what Radicale usually uses:
                        $calendar_json_out = json_encode($attributes_calendar, JSON_UNESCAPED_SLASHES);
                        $calendar_json_out = preg_replace('/,(?![^"]*"[^"]*(?:"[^"]*"[^"]*)*$)/', ', ', $calendar_json_out);
                        $calendar_json_out = preg_replace('/":"(.*?)"/', '": "$1"', $calendar_json_out);

                        // Update .Radicale.props file of this collection:
                        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/bin/radicale_update.pl " . $BX_SESSION['loginName'] . " $collection_id '$calendar_json_out'" , $payload, 'root', $BX_SESSION['sessionId']);
                        if ($ret == 0) {
                            $BxPage->ReturnToThisPage($errors, "/organizer/personalOrganizer");
                        }
                        else {
                            $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorUpdatingCollection]]"));
                            $BxPage->ReturnToThisPage($errors);
                        }
                    }
                }
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/organizer/personalOrganizerEdit?id=" . $collection_id);
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('radicale_personal');
        $page_module = 'base_personalProfile';

        $defaultPage = "basicSettingsTab";

        //
        //-- Check what we have and if it makes sense:
        //

        $have_calendar = '0';
        $have_addressbook = '0';

        if ($radicale['enabled'] == '0') {
            // Radicale is disabled. Show info text:

            $block = $factory->getPagedBlock("radicale_server_long", array($defaultPage));
            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs('#');
            $block->setDefaultPage($defaultPage);

            // Show a text description why this is turned off and what it can do if turned on:
            $radicale_off_desc = $factory->getHtmlField("radicale_off_desc", "<br>" . $i18n->get("[[base-organizer.radicale_off_desc]]"), 'r');
            $radicale_off_desc->setLabelType("nolabel");
            $block->addFormField(
                    $radicale_off_desc,
                    $factory->getLabel("radicale_off_desc"),
                    $defaultPage
                    );

            $page_body[] = $block->toHtml();

            // Out with the page:
            return $BxPage->render($page_module, $page_body);

        }
        elseif (in_array($collection_id, array_keys($calendars))) {
            $have_calendar = '1';
        }
        elseif (in_array($collection_id, array_keys($addressbooks))) {
            $have_addressbook = '1';
        }
        else {
            // Collection is unknown:
            $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorFileNotPresent]]"));

            // Nice people say goodbye, or CCEd waits forever:
            $redirect_URL = "/organizer/personalOrganizer";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Build UI:
        //

        if ($have_addressbook == '1') {
            $paged_block_heading = 'edit_addressbook';
        }
        else {
            $paged_block_heading = 'edit_calendar';
        }

        $block = $factory->getPagedBlock($paged_block_heading, array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // For Addressbooks:
        if ($have_addressbook == '1') {

            $col_name = '';
            if (isset($addressbooks[$collection_id]['D:displayname'])) {
                $col_name = $addressbooks[$collection_id]['D:displayname'];
            }
            $xxx = $factory->getTextField('col_name', $col_name, 'rw');
            $xxx->setType("");
            $block->addFormField($xxx, $factory->getLabel('col_name'), $defaultPage);

            $col_desc = '';
            if (isset($addressbooks[$collection_id]['CR:addressbook-description'])) {
                $col_desc = $addressbooks[$collection_id]['CR:addressbook-description'];
            }
            $xxx = $factory->getTextField('col_desc', $col_desc, 'rw');
            $xxx->setType("");
            $block->addFormField($xxx, $factory->getLabel('col_desc'), $defaultPage);

            // Type selector:
            $col_type = $addressbooks[$collection_id]['tag'];
            $Type_Choices = array(
                'VADDRESSBOOK' => 'ADDRESSBOOK',
            );
            $Type_select = $factory->getMultiChoice("col_type", array_values($Type_Choices));
            $Type_select->setSelected($Type_Choices[$col_type], true);
            $block->addFormField($Type_select, $factory->getLabel("col_type"), $defaultPage);

            //
            //-- Start: Colourpicker (using HTML5 functions):
            //

            if (isset($addressbooks[$collection_id]['{http://inf-it.com/ns/ab/}addressbook-color'])) {
                $color = $addressbooks[$collection_id]['{http://inf-it.com/ns/ab/}addressbook-color'];
            }
            else {
                $color = '#1C5EA0';
            }

            // Remove all but the first seven characters from $color, as we don't use Alpha-Channel:
            if (strlen($color) > 7) {
                $color = substr($color, 0, 7);
            }

            $col_colour = $i18n->get("[[base-organizer.col_colour]]");
            $col_colour_help = $i18n->get("[[base-organizer.col_colour_help]]");

            $color_picker_html = '
                <fieldset class="label_side">
                    <label for="col_colour" title="' . $col_colour_help . '" class="tooltip right uniform">' . $col_colour . '</label>
                    <div>
                        <input type="color" name="colorInput" id="colorInput" value="' . $color . '">
                    </div>
                </fieldset>';

            $BxPage->setExtraHeaders('
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var colorInput = document.getElementById("colorInput");
                        var initialColor = colorInput.value;
                        
                        if (initialColor.length === 9) {
                            initialColor = initialColor.substr(0, 7); // Remove last two characters for alpha channel
                        }
                        
                        colorInput.style.backgroundColor = initialColor;
                        
                        colorInput.addEventListener("change", function() {
                            var selectedColor = this.value;
                            colorInput.style.backgroundColor = selectedColor;
                        });
                    });
                </script>
            ');

            $xxx = $factory->getRawHTML("col_colour", $color_picker_html);
            $block->addFormField(
                $xxx,
                $factory->getLabel("col_colour"),
                $defaultPage
            );

            //
            //-- End: Colourpicker (using HTML5 functions)
            //

        }

        // For Addressbooks:
        if ($have_calendar == '1') {

            $col_name = '';
            if (isset($calendars[$collection_id]['D:displayname'])) {
                $col_name = $calendars[$collection_id]['D:displayname'];
            }
            $xxx = $factory->getTextField('col_name', $col_name, 'rw');
            $xxx->setType("");
            $block->addFormField($xxx, $factory->getLabel('col_name'), $defaultPage);

            $col_desc = '';
            if (isset($calendars[$collection_id]['C:calendar-description'])) {
                $col_desc = $calendars[$collection_id]['C:calendar-description'];
            }
            $xxx = $factory->getTextField('col_desc', $col_desc, 'rw');
            $xxx->setType("");
            $block->addFormField($xxx, $factory->getLabel('col_desc'), $defaultPage);

            // Type selector:
            $Type_Choices = array(
                'VEVENT,VJOURNAL,VTODO' => 'CALENDAR_JOURNAL_TASKS',
                'VEVENT,VJOURNAL' => 'CALENDAR_JOURNAL',
                'VEVENT,VTODO' => 'CALENDAR_TASKS',
                'VJOURNAL,VTODO' => 'JOURNAL_TASKS',
                'VEVENT' => 'CALENDAR',
                'VJOURNAL' => 'JOURNAL',
                'VTODO' => 'TASKS'
            );

            $col_type = $calendars[$collection_id]['tag'];
            $col_subtype = $calendars[$collection_id]['C:supported-calendar-component-set'];

            // Type:
            $Type_select = $factory->getMultiChoice("col_type", array_values(array('VCALENDAR' => 'VCALENDAR')));
            $Type_select->setSelected($col_type, true);
            $block->addFormField($Type_select, $factory->getLabel("col_type"), $defaultPage);

            // Subtype
            $SubType_select = $factory->getMultiChoice("col_subtype", array_values(array($Type_Choices[$col_subtype] => $Type_Choices[$col_subtype])));
            $SubType_select->setSelected($Type_Choices[$col_subtype], true);
            $block->addFormField($SubType_select, $factory->getLabel("col_subtype"), $defaultPage);

            //
            //-- Start: Colourpicker (using HTML5 functions):
            //

            if (isset($calendars[$collection_id]['ICAL:calendar-color'])) {
                $color = $calendars[$collection_id]['ICAL:calendar-color'];
            }
            else {
                $color = '#1C5EA0';
            }

            // Remove all but the first seven characters from $color, as we don't use Alpha-Channel:
            if (strlen($color) > 7) {
                $color = substr($color, 0, 7);
            }

            $col_colour = $i18n->get("[[base-organizer.col_colour]]");
            $col_colour_help = $i18n->get("[[base-organizer.col_colour_help]]");

            $color_picker_html = '
                <fieldset class="label_side">
                    <label for="col_colour" title="' . $col_colour_help . '" class="tooltip right uniform">' . $col_colour . '</label>
                    <div>
                        <input type="color" name="colorInput" id="colorInput" value="' . $color . '">
                    </div>
                </fieldset>';

            $BxPage->setExtraHeaders('
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var colorInput = document.getElementById("colorInput");
                        var initialColor = colorInput.value;
                        
                        if (initialColor.length === 9) {
                            initialColor = initialColor.substr(0, 7); // Remove last two characters for alpha channel
                        }
                        
                        colorInput.style.backgroundColor = initialColor;
                        
                        colorInput.addEventListener("change", function() {
                            var selectedColor = this.value;
                            colorInput.style.backgroundColor = selectedColor;
                        });
                    });
                </script>
            ');

            $xxx = $factory->getRawHTML("col_colour", $color_picker_html);
            $block->addFormField(
                $xxx,
                $factory->getLabel("col_colour"),
                $defaultPage
            );

            //
            //-- End: Colourpicker (using HTML5 functions)
            //

        }

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/organizer/personalOrganizer"));

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }       
}
/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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