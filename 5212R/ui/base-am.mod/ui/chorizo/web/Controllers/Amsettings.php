<?php 
namespace Am\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Amsettings extends BaseController {
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

        //helper(['form']);

        $CI =& get_instance();

        if (!$CI->getAllowed('serverShowActiveMonitor')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $CODBDATA = $CI->cceClient->getObject("ActiveMonitor");
        $System = $CI->getSystem();

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-am", "/am/amSettings");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle POST Request:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // Has getPost request:
            $form_data = $BxPage->FORM_POST;

            // Form fields that are required to have input:
            $required_keys = array();

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text");

            // Run GetFormAttributes()
            if (is_array($form_data)) {
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
                $errors = $BxPage->getErrors();
            }

            // If we have no errors, then we submit to CODB:
            if (count($errors) == "0") {

                // We have no errors. We submit to CODB.

                // Actual submit to CODB:
                $CI->cceClient->setObject("ActiveMonitor", array("enabled" => $attributes['enableAMField'], "alertEmailList" => $attributes['alertEmailList']), "");

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                //
                //--- Handle the updating of all "ActiveMonitor" items:
                //

                $amobj = $CI->cceClient->getObject("ActiveMonitor");
                if (isset($attributes['itemsToMonitor'])) {
                    $items = stringToArray($attributes['itemsToMonitor']);
                }
                else {
                    $items = array();
                }
                $names = $CI->cceClient->names($amobj["OID"]);

                // for each namespace on ActiveMonitor
                for ($i=0; $i < count($names); ++$i) {
                    $namespace = $CI->cceClient->get($amobj["OID"], $names[$i]);
                    if (isset($namespace["hideUI"])) {
                        if ($namespace["hideUI"]) {
                            continue;
                        }
                    }
                    $val = 0;
                    // try see if the nameTag for this namespace is in the list
                    for ($j=0; $j < count($items); ++$j) {
                        if ($namespace["NAMESPACE"] == $items[$j]) {
                            $val = 1;
                            break;
                        }
                    }
                    /* only set it if it is a boolean change */
                    if (($val && !$namespace["monitor"]) || (!$val && $namespace["monitor"])) {
                        /*
                        // If we are changing an "aggregate" service, then
                        // also enable/disable the typeData fields too.
                        // (ie. if Email, then do SMTP, POP3, IMAP too)
                        */
                        if ($namespace["type"] == "aggregate") {
                            $amServices = preg_split("/ /",$namespace["typeData"]);
                            foreach($amServices as $agServ) {
                                $CI->cceClient->set($amobj["OID"], $agServ, array("monitor" => $val));
                                $errors = array_merge($errors, $CI->cceClient->errors());
                            }
                        }

                        $CI->cceClient->set($amobj["OID"], $names[$i], array("monitor" => $val));
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }
                }

                //--- Done with AM-Item-Handling.

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors);

            }
        }

        // Prepare Page:
        $BxPage->setFormUrl("/am/amSettings");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_monitor');
        $BxPage->setVerticalMenuChild('base_amSettings');
        $page_module = 'base_sysmanage';
        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("amSettings", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // enabled checkbox
        $xxx = $factory->getBoolean("enableAMField", $CODBDATA["enabled"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("enableAMField"), 
            $defaultPage
            );

        //      
        // Alert Notification List:
        //
        // Work around for getEmailAddressList(): These days it only takes "emailAddresses"
        // and just a username like "admin" is no longer a valid email address. 
        $fixed_addies = array();
        $alertEmailList = $CI->cceClient->scalar_to_array($CODBDATA["alertEmailList"]);
        foreach ($alertEmailList as $key => $value) {
            if (!preg_match('/\@/', $value)) {
                $fixed_addies[] = $value . '@' . $System['hostname'] . '.' . $System['domainname'];
            }
            else {
                $fixed_addies[] = $value;
            }
        }
        $CODBDATA["alertEmailList"] = $CI->cceClient->array_to_scalar($fixed_addies);

        $alerts = $factory->getEmailAddressList("alertEmailList", $CODBDATA["alertEmailList"]);
        $alerts->setOptional(true);

        $block->addFormField(
            $alerts,
            $factory->getLabel("alertEmailList"), 
            $defaultPage
            );

        $selected = array();
        $selectedVals = array();
        $notSelected = array();
        $notSelectedVals = array();

        $names = $CI->cceClient->names($CODBDATA["OID"]);
        $namespaces = array();

        for ($i=0; $i < count($names); ++$i) {
            $nspace = $CI->cceClient->get($CODBDATA["OID"], $names[$i]);
            $name = $i18n->get($nspace["nameTag"]);
            $namespaces[$name] = $nspace;
        }

        // sort by i18n'ed strings
        ksort($namespaces);
    
        $all_monitor_items = array();
        $all_monitor_itemsVals = array();

        foreach ($namespaces as $name => $nspace) {
            if (isset($nspace["hideUI"])) {
                if ($nspace["hideUI"] == "0") {
                    $all_monitor_items[] = $name;
                    $all_monitor_itemsVals[] = $nspace["NAMESPACE"];
                }
            }
            else {
                $all_monitor_items[] = $name;
                $all_monitor_itemsVals[] = $nspace["NAMESPACE"];
            }

            if ($nspace["monitor"]) {
                if (isset($nspace["hideUI"])) {
                    if ($nspace["hideUI"] == "0") {
                        $selected[] = $name;
                        $selectedVals[] = $nspace["NAMESPACE"];
                    }
                }
                else {
                    $selected[] = $name;
                    $selectedVals[] = $nspace["NAMESPACE"];         
                }
            }
            else {
                $notSelected[] = $name;
                $notSelectedVals[] = $nspace["NAMESPACE"];
            }
        }

        $select_caps = $factory->getSetSelector('itemsToMonitor',
                            $CI->cceClient->array_to_scalar($selected), 
                            $CI->cceClient->array_to_scalar($all_monitor_items),
                            'selected', 'notSelected', 'rw',
                            $CI->cceClient->array_to_scalar($selectedVals),
                            $CI->cceClient->array_to_scalar($all_monitor_itemsVals));
       
        $select_caps->setOptional(true);

        $block->addFormField($select_caps, 
                    $factory->getLabel('itemsToMonitor'),
                    $defaultPage
                    );


        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/am/amSettings"));

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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