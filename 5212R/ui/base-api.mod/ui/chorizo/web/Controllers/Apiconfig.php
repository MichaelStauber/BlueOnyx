<?php 
namespace Api\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Apiconfig extends BaseController {
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

        if (!$CI->getAllowed('serverServerDesktop')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-api", "/istat/apiconfig");
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

        $access_file = '/etc/cced-api/config/access';
        $access_tokens = [];
        if (is_file($access_file)) {
            $lines = file($access_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                $pos = strrpos($line, ':');
                if ($pos !== false) {
                    $cidr = substr($line, 0, $pos);
                    $token = substr($line, $pos + 1);
                    $access_tokens[] = [
                        'cidr'  => trim($cidr),
                        'token' => trim($token)
                    ];
                }
            }
        }

        //
        //--- Get CODB-Object of interest: 
        //

        $CODBDATA = $CI->cceClient->get($System['OID'], "API");

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
        $ignore_attributes = array("BlueOnyx_Info_Text", '_', 'BlueOnyx_CSRF_token');

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Array
            // (
            //     [api_enabled] => 1
            //     [forceHTTPS] => 0
            //     [listen_port] => 127.0.0.1:9092
            //     [api_access] => &192.168.114.0/24&192.168.114.0/24&
            //     [token_lifetime] => 300
            //     [api_auth_fails] => 5
            //     [api_ban_time] => 60
            //     [logging] => 1
            //     [debuglog] => 0
            // )

            // Break up listen_port into address and port:
            list($api_ip, $api_port) = explode(':', $attributes['listen_port']);

            $cleaned_attributes = $attributes;
            unset($cleaned_attributes['listen_port']);

            // API *must* be enabled:
            $cleaned_attributes['enabled'] = "1";
            $cleaned_attributes['api_enabled'] = "1";

            // We don't set this intentionally. That way old API v1 users
            // can continue to use the brolen v1 API, but won't be able to
            // set up new machines using it.
            //
            //$cleaned_attributes['apiHosts'] = $attributes['api_access'];

            // Cleaned new settings for access:
            $cleaned_attributes['listen_address'] = $api_ip;
            $cleaned_attributes['listen_port'] = $api_port;

            // Actual submit to CODB:
            $CI->cceClient->setObject("System", $cleaned_attributes, "API");

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
        $BxPage->setFormUrl("/api/apiconfig");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_sysmaintenance');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";
        $access_token_tab = "AccessTokenTab";

        $block = $factory->getPagedBlock("apiSettings", array($defaultPage, $access_token_tab));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);

        $my_TEXT = $i18n->getClean("[[base-api.API_info]]") . "<br><br>";
        $infotext = $factory->getHtmlField("_", $my_TEXT, 'r');
        $infotext->setLabelType("nolabel");
        $block->addFormField(
          $infotext,
          $factory->getLabel(" ", false),
          $defaultPage
        );

        // Add divider:
        $apiSettings_line = $factory->addBXDivider("apiSettings", "");
        $block->addFormField(
                $apiSettings_line,
                $factory->getLabel("apiSettings", false),
                $defaultPage
                );

        // API enabled:
        $api_enabled_line = $factory->getBoolean("api_enabled", '1', 'r');
        $block->addFormField(
            $api_enabled_line,
            $factory->getLabel("enableServerField"),
            $defaultPage
        );

        // HTTPS Only:
        $forceHTTPS_line = $factory->getBoolean("forceHTTPS", '1', 'r');
        $block->addFormField(
            $forceHTTPS_line,
            $factory->getLabel("forceHTTPSField"),
            $defaultPage
        );

        // Localhost or public access:
        $localhost = '127.0.0.1:' . $CODBDATA["listen_port"];
        $public = '0.0.0.0:' . $CODBDATA["listen_port"];
        $current_access = $CODBDATA['listen_address'] . ':' . $CODBDATA['listen_port'];
        $AccessTypeMap = array("$localhost" => "$localhost", "$public" => "$public");
        $AccessType_select = $factory->getMultiChoice("listen_port", array_values($AccessTypeMap));
        $AccessType_select->setSelected($AccessTypeMap[$current_access], true);
        $block->addFormField($AccessType_select, $factory->getLabel("listen_port"));

        // API Access:
        $apiclienthosts = $factory->getTextList("api_access", $CODBDATA["api_access"]);
        $apiclienthosts->setOptional(true);
        $apiclienthosts->setType('InetAddressListIPv4IPv6');
        $block->addFormField(
          $apiclienthosts,
          $factory->getLabel("apiHosts"),
          $defaultPage
        );

        // Add divider:
        $securityDesc_line = $factory->addBXDivider("securityDesc", "");
        $block->addFormField(
                $securityDesc_line,
                $factory->getLabel("securityDesc", false),
                $defaultPage
                );

        // Token Lifetime:
        $token_lifetime_Field = $factory->getInteger("token_lifetime", $CODBDATA["token_lifetime"], "60", "86400");
        $token_lifetime_Field->setWidth(5);
        $token_lifetime_Field->showBounds(1);
        $block->addFormField(
            $token_lifetime_Field,
            $factory->getLabel("token_lifetime")
        );

        // Block after how many AUTH failures:
        $api_auth_fails_Field = $factory->getInteger("api_auth_fails", $CODBDATA["api_auth_fails"], "1", "100");
        $api_auth_fails_Field->setWidth(5);
        $api_auth_fails_Field->showBounds(1);
        $block->addFormField(
            $api_auth_fails_Field,
            $factory->getLabel("api_auth_fails")
        );

        // Block for how many seconds:
        $api_ban_time_Field = $factory->getInteger("api_ban_time", $CODBDATA["api_ban_time"], "30", "86400");
        $api_ban_time_Field->setWidth(5);
        $api_ban_time_Field->showBounds(1);
        $block->addFormField(
            $api_ban_time_Field,
            $factory->getLabel("api_ban_time")
        );

        // Add divider:
        $loggingDesc_line = $factory->addBXDivider("loggingDesc", "");
        $block->addFormField(
                $loggingDesc_line,
                $factory->getLabel("loggingDesc", false),
                $defaultPage
                );

        // Logging:
        $logging_line = $factory->getBoolean("logging", $CODBDATA["logging"]);
        $block->addFormField(
            $logging_line,
            $factory->getLabel("logging"),
            $defaultPage
        );

        // Debuglog:
        $debuglog_line = $factory->getBoolean("debuglog", $CODBDATA["debuglog"]);
        $block->addFormField(
            $debuglog_line,
            $factory->getLabel("debuglog"),
            $defaultPage
        );

        // Hidden trigger to force Handler run even if no value was changed on save:
        $textblock = $factory->getTextField("force_update", time(), '');
        $block->addFormField(
            $textblock,
            $factory->getLabel("force_update"),
            $defaultPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/api/apiconfig"));

        //
        //--- Access Token Tab:
        //

        $token_list = $factory->getScrollList("blackList", array("CIDR", "Token"), array()); 
        $token_list->setAlignments(array("left", "left"));
        $token_list->setDefaultSortedIndex('0');
        $token_list->setSortOrder('ascending');
        $token_list->setSortDisabled(array('1'));
        $token_list->setPaginateDisabled(FALSE);
        $token_list->setSearchDisabled(FALSE);
        $token_list->setSelectorDisabled(FALSE);
        $token_list->enableAutoWidth(FALSE);
        $token_list->setInfoDisabled(FALSE);
        $token_list->setColumnWidths(array("539", "200")); // Max: 739px

        foreach ($access_tokens as $key => $access_array) {
            $token_list->addEntry(array(
                        $access_array['cidr'],
                        $access_array['token'],
                        ));
        }

        $xxx = $factory->getRawHTML("access_token_tab", $token_list->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("access_token_tab"),
            $access_token_tab
        );

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }       
}

/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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