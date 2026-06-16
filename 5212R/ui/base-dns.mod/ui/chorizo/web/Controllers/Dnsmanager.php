<?php 
namespace Dns\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Dnsmanager extends BaseController {
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

        if (!$CI->getAllowed('siteDNS')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-dns", "/dns/dnsmanager");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //--- Handle form validation:
        //

        // Form fields that are required to have input:
        $required_keys = array("default_refresh", "default_retry", "default_expire", "default_ttl", "responses_per_second", "window");

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
            $errors = $BxPage->getErrors();
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            // None
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Any additional parameters that we need to pass on?
            $attributes['commit'] = time();

            // Actual submit to CODB:
            $CI->cceClient->setObject("System", $attributes, "DNS");

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/dns/dnsmanager");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_sysmanage';

        // get DNS
        $dns = $CI->cceClient->getObject("System", array(), "DNS");

        //
        // -- Button-Header:
        //

        $p_button = $factory->getButton("/dns/primarydns", 'primary_service_button', "DEMO-OVERRIDE");
        $s_button = $factory->getButton("/dns/secondarydns", 'secondary_service_button', "DEMO-OVERRIDE");
        $buttonContainer = $factory->getButtonContainer("", array($p_button, $s_button));

        //
        // -- Initialize PagedBlock:
        //

        $defaultPage = "basic";

        $block = $factory->getPagedBlock("modifyDNS", array($defaultPage, "advanced", "zone_format_tab", "auto_dns"));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic Tab
        //

        // Enable DNS:
        $xxx = $factory->getBoolean("enabled", $dns["enabled"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("enabled"),
            $defaultPage
        );


        //
        //--- Advanced Tab
        //

        // Add divider:
        $xxx = $factory->addBXDivider("soa_defaults", "");
        $block->addFormField(
              $xxx,
              $factory->getLabel("soa_defaults", false),
              "advanced"
              );

        $admin_email = $factory->getEmailAddress("admin_email", $dns["admin_email"]);
        $admin_email->setOptional(true);
        $block->addFormField(
            $admin_email,
            $factory->getLabel("admin_email"),
            "advanced"
        );

        $default_refresh = $factory->getInteger("default_refresh", $dns["default_refresh"], 1, "4096000");
        $default_refresh->setWidth(5);
        $default_refresh->showBounds(1);
        $block->addFormField(
            $default_refresh,
            $factory->getLabel("default_refresh"),
            "advanced"
        );

        $default_retry = $factory->getInteger("default_retry", $dns["default_retry"], 1, "4096000");
        $default_retry->setWidth(5);
        $default_retry->showBounds(1);
        $block->addFormField(
            $default_retry,
            $factory->getLabel("default_retry"),
            "advanced"
        );

        $default_expire = $factory->getInteger("default_expire", $dns["default_expire"], 1, "4096000");
        $default_expire->setWidth(5);
        $default_expire->showBounds(1);
        $block->addFormField(
            $default_expire,
            $factory->getLabel("default_expire"),
            "advanced"
        );

        $default_ttl = $factory->getInteger("default_ttl", $dns["default_ttl"], 1, "4096000");
        $default_ttl->setWidth(5);
        $default_ttl->showBounds(1);
        $block->addFormField(
            $default_ttl,
            $factory->getLabel("default_ttl"),
            "advanced"
        );

        // Add divider:
        $xxx = $factory->addBXDivider("global_settings", "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("global_settings", false),
            "advanced"
        );

        $xxx = $factory->getBoolean("query", $dns["query"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("query"),
            "advanced"
        );

        $xxx = $factory->getBoolean("query_all_allowed", $dns["query_all_allowed"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("query_all_allowed"),
            "advanced"
        );

        $query_inetaddr = $factory->getInetAddressList("query_inetaddr", $dns["query_inetaddr"]);
        $query_inetaddr->setOptional(true);
        $block->addFormField(
            $query_inetaddr,
            $factory->getLabel("query_inetaddr"),
            "advanced"
        );

        $xxx = $factory->getBoolean("caching", $dns["caching"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("caching"),
            "advanced"
        );

        $xxx = $factory->getBoolean("caching_all_allowed", $dns["caching_all_allowed"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("caching_all_allowed"),
            "advanced"
        );

        $recursion_inetaddr = $factory->getInetAddressList("recursion_inetaddr", $dns["recursion_inetaddr"]);
        $recursion_inetaddr->setOptional(true);
        $block->addFormField(
            $recursion_inetaddr,
            $factory->getLabel("recursion_inetaddr"),
            "advanced"
        );

        $forwarders = $factory->getIpAddressList("forwarders", $dns["forwarders"]);
        $forwarders->setOptional(true);
        $block->addFormField(
            $forwarders,
            $factory->getLabel("forwarders"),
            "advanced"
        );

        $zone_xfer_ipaddr = $factory->getIpAddressList("zone_xfer_ipaddr", $dns["zone_xfer_ipaddr"]);
        $zone_xfer_ipaddr->setOptional(true);
        $block->addFormField(
            $zone_xfer_ipaddr,
            $factory->getLabel("zone_xfer_ipaddr"),
            "advanced"
        );

        // Add divider:
        $xxx = $factory->addBXDivider("rate_limits", "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("rate_limits", false),
            "advanced"
        );

        $xxx = $factory->getBoolean("rate_limits_enabled", $dns["rate_limits_enabled"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("rate_limits_enabled"),
            "advanced"
        );

        $responses_per_second = $factory->getInteger("responses_per_second", $dns["responses_per_second"], 1, "1000");
        $responses_per_second->setWidth(4);
        $responses_per_second->showBounds(1);
        $block->addFormField(
            $responses_per_second,
            $factory->getLabel("responses_per_second"),
            "advanced"
        );

        $window = $factory->getInteger("window", $dns["window"], 1, "128");
        $window->setWidth(3);
        $window->showBounds(1);
        $block->addFormField(
            $window,
            $factory->getLabel("window"),
            "advanced"
        );

        // Add divider:
        $xxx = $factory->addBXDivider("dns_logging", "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("dns_logging", false),
            "advanced"
        );

        $xxx = $factory->getBoolean("enable_dns_logging", $dns["enable_dns_logging"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("enable_dns_logging"),
            "advanced"
        );

        //
        //-- Zone Format:
        //

        // Add divider:
        $xxx = $factory->addBXDivider("zone_format_settings_divider", "");
        $block->addFormField(
            $xxx,
            $factory->getLabel("zone_format_settings_divider", false),
            "zone_format_tab"
        );

        // Note: in 5106R/5107R/5108R we disabled 'DION','OCN-JT','USER'. Hence the next formfield
        // doesn't have to be a read-only multichoice. Hence I made it a read-only getTextField:
        //$zone_format_array = array('RFC2317','DION','OCN-JT','USER');
        $zone_format = $factory->getTextField("zone_format", $dns["zone_format"], "r");
        $zone_format->setOptional(true);
        $block->addFormField(
            $zone_format,
            $factory->getLabel("zone_format"),
            "zone_format_tab"
        );

        //
        //-- Auto-DNS
        //

        $auto_a = $factory->getTextList("auto_a", $dns["auto_a"]);
        $auto_a->setOptional(TRUE);
        $auto_a->setType("alphanum_plus_multiline");
        $block->addFormField(
            $auto_a,
            $factory->getLabel("auto_a"),
            "auto_dns"
        );

        $auto_mx = $factory->getTextField("auto_mx", $dns["auto_mx"], "rw");
        $auto_mx->setOptional(true);
        $auto_mx->setType("alphanum_plus");
        $block->addFormField(
            $auto_mx,
            $factory->getLabel("auto_mx"),
            "auto_dns"
        );


        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/dns/dnsmanager"));

        $page_body[] = $buttonContainer->toHtml();
        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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