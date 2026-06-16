<?php 
namespace Network\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PoolingModify extends BaseController {
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

        if (!$CI->getAllowed('serverNetwork')) {
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

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-network", "/network/poolingModify");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

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

        // Get TARGET of Modification request:
        $action = "";
        if(isset($get_form_data['ACTION'])) {
            if ($get_form_data['ACTION'] == "M") {
                $action = "modify";
                if ($this->request->getPost(NULL, NULL, TRUE)) {
                    // Security Check:
                    $oid = $get_form_data['_oid'];
                    $oidData = $CI->cceClient->get($get_form_data['_oid']);
                    if ($oidData['CLASS'] != "IPPoolingRange") {
                        // These are not the droids we are looking for!
                        // Nice people say goodbye, or CCEd waits forever:
                        $CI->cceClient->bye();
                        $CI->serverScriptHelper->destructor();
                        Log403Error("/gui/Forbidden403");
                    }
                    if (!isset($attributes["admin"])) {
                        $attributes["admin"] = 'admin';
                    }
                    // construct object:
                    $obj = array(
                        "min" => $attributes["min"],
                        "max" => $attributes["max"],
                        "admin" => $attributes["admin"],
                        "creation_time" => time());

                    // Set Object:
                    $ok = $CI->cceClient->set($oid, "", $obj);
                }
            }
            if ($get_form_data['ACTION'] == "D") {
                // Security Check:
                $oid = $get_form_data['_oid'];
                $oidData = $CI->cceClient->get($get_form_data['_oid']);
                if ($oidData['CLASS'] != "IPPoolingRange") {
                    // These are not the droids we are looking for!
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403");
                }
                if (!is_file("/etc/DEMO")) {
                    $ok = $CI->cceClient->destroy($oid);
                }

                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                if (count($errors) <= '0') {
                    // Nice people say goodbye, or CCEd waits forever:
                    $redirect_URL = '/network/pooling';
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
            }
        }
        else {
            $action = "create";
                if ($this->request->getPost(NULL, NULL, TRUE)) {
                    // construct object:
                    $obj = array(
                        "min" => $attributes["min"],
                        "max" => $attributes["max"],
                        "admin" => $attributes["admin"],
                        "creation_time" => time());

                    // Set Object:
                    $ok = $CI->cceClient->create("IPPoolingRange", $obj);

                    $CCEerrors = $CI->cceClient->errors();
                    foreach ($CCEerrors as $object => $objData) {
                        // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                        $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                    }
                }           
        }


        // If we have no errors and have POST data:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {
            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // We have no errors and have POST data, we submitted to CODB without errors? Redirect.
            if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE)) && (count($CCEerrors) == 0)) {
                // Nice people say goodbye, or CCEd waits forever:
                $redirect_URL = '/network/pooling';
                $BxPage->ReturnToThisPage($errors, $redirect_URL);                
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $post_URL = "/network/poolingModify";
        if ((isset($get_form_data['ACTION'])) && (isset($get_form_data['_oid']))) {
            if ($get_form_data['ACTION'] == "M") {
                $post_URL = "/network/poolingModify?ACTION=M&_oid=" . $get_form_data['_oid'];
            }
            if ($get_form_data['ACTION'] == "D") {
                $post_URL = "/network/poolingModify?ACTION=D&_oid=" . $get_form_data['_oid'];
            }           
        }
        $BxPage->setFormUrl($post_URL);
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $BxPage->setVerticalMenuChild('base_sitepooling');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";

        if (isset($get_form_data['_oid'])) {
            $add = false;
            $pbTitle = 'sitepooling';
            $oid = $get_form_data['_oid'];
            $current = $CI->cceClient->get($oid);
            $min_string = "min";
            $max_string = "max";
        }
        else {
            $add = true;
            $pbTitle = 'sitepooling';
            if (isset($attributes["min"])) {
                $current['min'] = $attributes["min"];
            }
            else {
                $current['min'] = "";
            }
            if (isset($attributes["max"])) {
                $current['max'] = $attributes["max"];
            }
            else {
                $current['max'] = "";
            }
            $min_string = "min";
            $max_string = "max";
        }

        $block = $factory->getPagedBlock($pbTitle, array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $minfield = $factory->getIpAddress($min_string, $current['min']);
        $minfield->setType("ipaddrIPv4IPv6");
        $block->addFormField($minfield,$factory->getLabel('min'), $defaultPage);

        $maxfield = $factory->getIpAddress($max_string, $current['max']);
        $maxfield->setType("ipaddrIPv4IPv6");
        $block->addFormField($maxfield,$factory->getLabel('max'), $defaultPage);

        // Get all adminUsers:
        $oids = $CI->cceClient->findx('User', 
                                        array('capLevels' => 'adminUser'), 
                                        array(), 
                                        'ascii', 
                                        'name'); 
         
        foreach ($oids as $oid) { 
            $admins[$oid] = $CI->cceClient->get($oid); 
        } 

        // Add 'admin' as well:
        $oids = $CI->cceClient->findx('User', 
                                        array('name' => 'admin'), 
                                        array(), 
                                        'ascii', 
                                        'name');
        foreach ($oids as $oid) { 
            $admins[$oid] = $CI->cceClient->get($oid); 
        }

        foreach ($admins as $adm) {
            $adminNames[] = $adm["name"];
        }

        if (isset($current['admin'])) {
            if ($current['admin'] == "") {
                $current['admin'] = "admin";
            }
        }
        else {
            $current['admin'] = "admin";
        }

        $adminArray = $CI->cceClient->scalar_to_array($current['admin']); 

        $select_caps = $factory->getSetSelector(
                                'admin',
                                $CI->cceClient->array_to_scalar($adminArray),
                                $CI->cceClient->array_to_scalar($adminNames), 
                                '', '',
                                'rw', 
                                $CI->cceClient->array_to_scalar($adminArray),
                                $CI->cceClient->array_to_scalar($adminNames)
                            );
           
        $select_caps->setOptional(true);

        $xxx = $factory->getLabel('adminPowers');
        $block->addFormField($select_caps, 
                    $xxx,
                    $defaultPage
                    );

        // Add the buttons
        $block->addButton($factory->getSaveButton("/network/poolingModify"));
        $block->addButton($factory->getCancelButton("/network/pooling"));

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