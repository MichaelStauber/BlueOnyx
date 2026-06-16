<?php 
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class StatSettings extends BaseController {
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

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        //
        //--- Restrict access:
        //

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/istat/statSettings");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a delete transaction:
            $group = $get_form_data['group'];
        }
        else {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        //
        // Access Rules:
        //

        //
        //-- Access Rights Check for Vsite level pages:
        // 
        // 1.) Checks if the Group/Vsite exists.
        // 2.) Checks if the user is systemAdministrator
        // 3.) Checks if the user is Reseller of the given Group/Vsite
        // 4.) Checks if the iser is siteAdmin of the given Group/Vsite
        // Returns Forbidden403 if *none* of that is the case.
        if (!$CI->serverScriptHelper->getGroupAdmin($group)) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        if ((!$CI->getAllowed('adminUser')) && 
            (!$CI->getAllowed('siteAdmin')) && 
            (!$CI->getAllowed('manageSite')) && 
            (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->getAllowed('siteAdmin')) &&
            (($vsiteObj['createdUser'] != $BX_SESSION['loginName']) && $CI->getAllowed('manageSite'))
            ) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare data:
        //

        $SystemSiteStats = $CI->cceClient->getObject('System', array(), 'Sitestats');

        $purgeMap = array();
        if ($SystemSiteStats['purge'] == '0') {
            $purgeMap = array(
                    'never' =>      0,
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    '2year' =>      732,
                    '3year' =>      1096,
                    '4year' =>      1462,
                    '5year' =>      1827,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '32') {
            $purgeMap = array(
                    'month' =>      32,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '62') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '93') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '181') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '366') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '732') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    '2year' =>      732,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '1096') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    '2year' =>      732,
                    '3year' =>      1096,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '1462') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    '2year' =>      732,
                    '3year' =>      1096,
                    '4year' =>      1462,
                    );
        }
        elseif ($SystemSiteStats['purge'] == '1827') {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    '2year' =>      732,
                    '3year' =>      1096,
                    '4year' =>      1462,
                    '5year' =>      1827,
                    );
        }
        else {
            $purgeMap = array(
                    'month' =>      32,
                    '2month' =>     62,
                    '3month' =>     93,
                    '6month' =>     181,
                    'year' =>       366,
                    );
        }

        $detailMap = array(
            'sitestatsConsolidateDaily' =>      0,
            'sitestatsConsolidateMonthly' =>    1,
            );

        // Session is read-only for non-server administrators
        if($CI->getAllowed('adminUser')) {
            $sitestats_access = 'rw';
        }
        else {
            $sitestats_access = 'r';
        }

        // Get data for the Vsite:
        $sitestats = $CI->cceClient->getObject('Vsite', array('name' => $group), 'SiteStats');
        list($vsite) = $CI->cceClient->find('Vsite', array('name' => $group));
        $vsiteObj = $CI->cceClient->get($vsite);

        // Make sure our max val is not bigger than the max allowed by the System:
        $possVals = array_values($purgeMap);
        if (!in_array($sitestats['purge'], $possVals)) {
            if (in_array('0', $possVals)) {
                $sitestats['purge'] = '0';
            }
            else {
                $sitestats['purge'] = array_pop($possVals);
            }
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

            // Assemble SET data:
            if (!isset($attributes['Sitestats_enabled'])) {
                $Sitestats_enabled = "0";
            }
            else {
                $Sitestats_enabled = $attributes['Sitestats_enabled'];
            }
            if (!isset($attributes['Sitestats_consolidate'])) {
                $Sitestats_consolidate = "0";
            }
            else {
                $Sitestats_consolidate = $attributes['Sitestats_consolidate'];
            }
            if (!isset($attributes['Sitestats_purge'])) {
                $Sitestats_consolidate = "never";
            }
            else {
                $Sitestats_purge = $attributes['Sitestats_purge'];
            }

            $settings = array();
            $settings["enabled"] = $Sitestats_enabled;
            $settings["consolidate"] = $detailMap[$Sitestats_consolidate];
            $settings["purge"] = $purgeMap[$Sitestats_purge];

            // Actual submit to CODB:
            list($vsite) = $CI->cceClient->find('Vsite', array('name' => $group));
            $CI->cceClient->set($vsite, 'SiteStats', $settings);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to sender:
            $redirect_URL = "/sitestats/statSettings?group=$group";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/sitestats/statSettings?group=$group");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_siteusage');
        $BxPage->setVerticalMenuChild('base_vsite_sitestats');
        $page_module = 'base_sitemanage';

        $defaultPage = "pageID";
        $block = $factory->getPagedBlock("sitestatsSettings", array($defaultPage));
        $block->setLabel($factory->getLabel('sitestatsSettings', false, array('fqdn' => $vsiteObj['fqdn'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        // Construct all the form fields needed, note that only simple
        // form fields are allowd.  no composite form fields
        $statsEnable = $factory->getBoolean('Sitestats_enabled', $sitestats['enabled'], $sitestats_access);

        // Simple array setup:
        $detailLabels = array_keys($detailMap);
        $detailDays = array_values($detailMap);
        $detailrevMap = array_flip($detailMap);

        $statsConsolidate = $factory->getMultiChoice('Sitestats_consolidate', $detailLabels, array($detailrevMap[$sitestats['consolidate']]), $sitestats_access);
        $statsConsolidate->setSelected($detailrevMap[$sitestats['consolidate']], true);

        // Yet again:
        $purgeLabels = array_keys($purgeMap);
        $purgeDays = array_values($purgeMap);
        $revMap = array_flip($purgeMap);

        // Some cleanup logic. It can be that $sitestats['purge'] is not
        // set or set to something not matching our $revMap. In that case
        // we need to set a default:
        if (!array_key_exists($sitestats['purge'], $revMap)) {
            $sitestats['purge'] = '366';
        }

        $purgeSelect = $factory->getMultiChoice('Sitestats_purge', $purgeLabels, array($revMap[$sitestats['purge']]), $sitestats_access);
        $purgeSelect->setSelected($revMap[$sitestats['purge']], true);

        $block->addFormField($statsEnable, $factory->getLabel("sitestatsEnable"), $defaultPage);
        $block->addFormField($statsConsolidate, $factory->getLabel("sitestatsConsolidate"), $defaultPage);
        $block->addFormField($purgeSelect, $factory->getLabel("sitestatsPurge"), $defaultPage);

        $xff = $factory->getTextField('save', 1, '');
        $block->addFormField($xff);

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/sitestats/statSettings?group=$group"));

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