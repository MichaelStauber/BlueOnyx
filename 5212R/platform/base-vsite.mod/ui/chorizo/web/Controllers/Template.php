<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class Template extends BaseController {
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
        if (!$CI->getAllowed('admin')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        // Update PHP version info:
        $CI->cceClient->setObject("System", array('version_update' => time()), "PHP_mgmt" );

        // Read the current defaults from cce, so they can be substituted
        $vsiteDefaults = $CI->cceClient->get($System['OID'], "VsiteDefaults");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/template");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-vsite", $CI->getBX_Locale());
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
            $ignore_attributes = array("BlueOnyx_Info_Text", '_serialized_errors');

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

            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {

                if (!isset($attributes['ipAddr'])) {
                    $attributes['ipAddr'] = "";
                }
                if (!isset($attributes['ipaddrIPv6'])) {
                    $attributes['ipaddrIPv6'] = "";
                }

                // Check if our quota has a unit:
                $pattern = '/^(\d*\.{0,1}\d+)(K|M|G|T)$/';
                if (preg_match($pattern, $attributes['quota'], $matches, PREG_OFFSET_CAPTURE)) {
                    // Quota has a unit:
                    $quota = (unsimplify_number($attributes['quota'], "K")/1000);
                }
                else {
                    // Quota has no unit:
                    $quota = simplify_number($attributes['quota'], "K", "0");
                }

                // We have no errors. We submit to CODB.
                $CI->cceClient->setObject("System",
                        array(
                            "ipaddr" => $attributes['ipAddr'],
                            "ipaddrIPv6" => $attributes['ipaddrIPv6'],
                            "domain" => $attributes['domain'],
                            "quota" => $quota,
                            "maxusers" => $attributes['maxusers'],
                            "emailDisabled" => $attributes['emailDisabled'],
                            "mailCatchAll" => $attributes['mailCatchAll'],
                            "dns_auto" => $attributes['dns_auto'],
                            "webAliasRedirects" => $attributes['webAliasRedirects'],
                            "site_preview" => $attributes['site_preview'],
                            "siteAdminAliases" => $attributes['siteAdminAliases'],
                            "defaultSiteAdminAliasChanged" => '1'
                        ),
                        "VsiteDefaults"
                    );

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                //
                //-- Handle submit for AutoFeatures:
                //

                if (count($errors) == "0") {
                    // Handle automatically detected services
                    $autoFeatures = new AutoFeatures($CI->serverScriptHelper, $attributes);
                    $cce_info = array();
                    list($cce_info["CCE_SERVICES_OID"]) = $CI->cceClient->find("VsiteServices");
                    $af_errors = $autoFeatures->handle("defaults.Vsite", $cce_info); 
                    $errors = array_merge($errors, $af_errors);
                }

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors, '/vsite/template');
            }
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_sitemanage');
        //$BxPage->setVerticalMenuChild('pam_abl');
        $page_module = 'base_sitemanageVSL';


        $pageId = "siteDefaultsTab";
        $block = $factory->getPagedBlock("vsiteDefaults", array($pageId, 'otherServices'));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($pageId);

        // Determine visibility of IP protocol related fields:
        $show_IPv4 = FALSE;
        $show_IPv6 = FALSE;
        $access_IPv4 = '';
        $access_IPv6 = '';
        if (in_array($System['IPType'], array('IPv4', 'VZv4', 'BOTH', 'VZBOTH'))) {
            $show_IPv4 = TRUE;
            $access_IPv4 = 'rw';
        }
        if (in_array($System['IPType'], array('IPv6', 'VZv6', 'BOTH', 'VZBOTH'))) {
            $show_IPv6 = TRUE;
            $access_IPv6 = 'rw';
        }

        //
        //--- Basic Tab
        //

        //default IPv4 ip address
        $ipAddrField = $factory->getIpAddress("ipAddr", $vsiteDefaults["ipaddr"], $access_IPv4);
        $ipAddrField->setOptional('silent');
        $block->addFormField(
                    $ipAddrField,
                    $factory->getLabel("defaultIpAddr"),
                    $pageId
                    );

        //default IPv6 ip address
        $ipv6_address = $factory->getIpAddress("ipaddrIPv6", $vsiteDefaults["ipaddrIPv6"]);
        $ipv6_address->setType("ipaddrIPv6");
        $ipv6_address->setOptional('silent');
        $block->addFormField(
                    $ipv6_address,
                    $factory->getLabel("ipaddrIPv6"),
                    $pageId
                    );

        // default domain
        $domainField = $factory->getDomainName("domain", $vsiteDefaults["domain"]);
        $domainField->setOptional('silent');
        $block->addFormField(
                    $domainField,
                    $factory->getLabel("defaultDomain"),
                    $pageId
                    );

        //-- Start: siteAdmin creation:

        // Add divider:
        $ffs = $factory->addBXDivider("siteAdminDivider", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("[[base-user.siteAdminEnabled]]", false),
                $pageId
                );

        // defaultSiteAdminAliasChanged
        // siteAdminAliases

        $show_emailAlias = $vsiteDefaults["siteAdminAliases"];
        if (($vsiteDefaults["defaultSiteAdminAliasChanged"] == '0') && ($vsiteDefaults["siteAdminAliases"] == '')) {
            $show_emailAlias = '&webmaster&';
        }

        $emailAliases = $factory->getEmailAliasList("siteAdminAliases", $show_emailAlias);
        $emailAliases->setOptional(true);

        // Set Label/Desription directly in BxPage);
        $BxPage->setLabel('siteAdminAliases', $i18n->get("[[base-user.emailAliasesField]]"), $i18n->get("[[base-user.emailAliasesField_help]]"));

        $block->addFormField(
            $emailAliases,
            $factory->getLabel("siteAdminAliases"),
            $pageId
        );

        // Add divider:
        $ffs = $factory->addBXDivider("siteConfigData", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("[[base-api.basicSettingsTab]]", false),
                $pageId
                );

        //-- Stop: siteAdmin creation:

        // default site quota
        $quotaField = $factory->getInteger("quota", simplify_number($vsiteDefaults["quota"]*1000*1000, "K", "0"), '1000000', 0);
        $quotaField->showBounds(FALSE);
        $quotaField->setType('memdisk');
        $block->addFormField(
                    $quotaField,
                    $factory->getLabel("quota"),
                    $pageId
                    );

        // default maxusers
        $xxx = $factory->getInteger("maxusers", $vsiteDefaults["maxusers"], 1);
        $block->addFormField(
                $xxx,
                $factory->getLabel("maxUsers"),
                $pageId
                );

        // enable & disable Email
        $xxx = $factory->getBoolean("emailDisabled", $vsiteDefaults["emailDisabled"]);
        $block->addFormField(
                $xxx,
                $factory->getLabel("emailDisabled"),
                $pageId
                );

        // default email catch-all
        $mailCatchAllField = $factory->getEmailAddress("mailCatchAll", $vsiteDefaults["mailCatchAll"], 1);
        $mailCatchAllField->setOptional('silent');
        $block->addFormField(
                $mailCatchAllField,
                $factory->getLabel("mailCatchAll"),
                $pageId
                );

        // auto dns option
        $xxx = $factory->getBoolean("dns_auto", $vsiteDefaults["dns_auto"]);
        $block->addFormField(
                $xxx,
                $factory->getLabel("dns_auto"),
                $pageId
                    );

        // preview site option
        $xxx = $factory->getBoolean("site_preview", $vsiteDefaults["site_preview"]);
        $block->addFormField(
                $xxx,
                $factory->getLabel("site_preview"),
                $pageId
                );

        // webAliasRedirects (to main site) option
        $xxx = $factory->getBoolean("webAliasRedirects", $vsiteDefaults["webAliasRedirects"]);
        $block->addFormField(
                $xxx,
                $factory->getLabel("webAliasRedirects"),
                $pageId
                );

        //
        //--- Services and Features
        //

        // Add automatically detected features
        $autoFeatures = new AutoFeatures($CI->serverScriptHelper);
        $cce_info = array();
        list($cce_info['CCE_SERVICES_OID']) = $CI->cceClient->find('VsiteServices');
        $cce_info['PAGED_BLOCK_DEFAULT_PAGE'] = 'otherServices';
        $autoFeatures->display($block, 'defaults.Vsite', $cce_info);

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/vsite/template"));

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
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