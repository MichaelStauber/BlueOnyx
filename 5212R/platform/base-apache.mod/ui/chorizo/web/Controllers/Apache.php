<?php 
namespace Apache\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Apache extends BaseController {
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

        if (!$CI->getAllowed('serverHttpd')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $System = $CI->getSystem();
        $web = $CI->cceClient->getObject("System", array(), "Web");
        $nginx = $CI->cceClient->getObject("System", array(), "Nginx");

        //
        //--- Helper Arrays:
        //

        $NginxVals = array(  
                        'enabled', 
                        'worker_processes', 
                        'worker_connections', 
                        'ssl_session_timeout', 
                        'ssl_session_cache', 
                        'ssl_session_tickets', 
                        'resolver_valid', 
                        'resolver_timeout', 
                        'ssl_stapling', 
                        'ssl_stapling_verify',
                        'max_age',
                        'include_subdomains'
                    );

        $NginxValsOnOff = array(  
                        'ssl_session_tickets', 
                        'ssl_stapling', 
                        'ssl_stapling_verify' 
                    );

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-apache", "/apache/apache");
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
            $required_keys = array('maxClients', 'minSpare', 'maxSpare');

            // Empty array for key => values we want to submit to CCE:
            $attributes = array();

            // Items we do NOT want to submit to CCE:
            $ignore_attributes = array("BlueOnyx_Info_Text", "Nginx_Info_Text");

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
            //--- Own error checks:
            //

            // min spares needs to be less than or equal to max spares
            if ($form_data['minSpare'] > $form_data['maxSpare']) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.MinMaxError]]"));
            }

            // maxClientsField must be smaller than maxSpareField:
            if ($form_data['maxClients'] < $form_data['maxSpare']) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.ClientMaxError]]"));
            }

            // Check if the HTTP/SSL ports are in use:
            $httpPortField = $form_data['httpPort'];
            $sslPortField = $form_data['sslPort'];
            $HTTPportInUse = `/bin/netstat -tupan|/bin/grep LISTEN|awk '{print \$4}'|cut -d : -f2|egrep -v '^[[:space:]]*\$'| egrep -E '^$httpPortField\$'|wc -l`;
            $SSLportInUse = `/bin/netstat -tupan|/bin/grep LISTEN|awk '{print \$4}'|cut -d : -f2|egrep -v '^[[:space:]]*\$'| egrep -E '^$sslPortField\$'|wc -l`;

            $HTTPportInUse = preg_replace('/\n$/','',$HTTPportInUse); 
            $SSLportInUse = preg_replace('/\n$/','',$SSLportInUse); 

            if (($HTTPportInUse != "0") && ($web['httpPort'] != $httpPortField)) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.httpPortInUse]]"));
            }
            if (($SSLportInUse != "0") && ($web['sslPort'] != $sslPortField)) {
                $errors[] = ErrorMessage($i18n->get("[[base-apache.SSLportInUse]]"));
            }

            //
            //--- No errors? Submit to CODB:
            //

            if (count($errors) == "0") {

                // Any additional parameters that we need to pass on?
                $attributes['Writeback_BlueOnyx_Conf'] = time();

                $attributesNginx = array();
                if (isset($attributes['HSTS_Nginx_enabled'])) {
                    $attributesNginx['HSTS'] = $attributes['HSTS_Nginx_enabled'];
                    unset($attributes['HSTS_Nginx_enabled']);
                }

                // 'Options_All' includes:
                // 
                // FollowSymLinks
                // SymLinksIfOwnerMatch
                // AllowOverride

                if ($attributes['Options_All'] == '1') {
                    $attributes['Options_FollowSymLinks'] = '1';
                    $attributes['Options_SymLinksIfOwnerMatch'] = '1';
                }

                foreach ($attributes as $key => $value) {
                    if (in_array($key, $NginxVals)) {
                        if (in_array($key, $NginxValsOnOff)) {
                            if ($value == '1') {
                                $value = 'on';
                            }
                            else {
                                $value = 'off';
                            }
                            $attributesNginx[$key] = $value;
                        }
                        else {
                            $attributesNginx[$key] = $value;
                        }
                        unset($attributes[$key]);
                    }
                }
                $attributes['reload'] = time();
                $attributesNginx['force_update'] = time();

                // GetFormAttributes() has issues with MultiChoice getTextList(), so we extract 'good_useragents' manually here:
                if ((isset($form_data['good_useragents'])) && (!empty($form_data['good_useragents']))) {
                    $attributes['good_useragents'] = urldecode(arrayToString(stringNToArray($form_data['good_useragents'])));
                }

                // GetFormAttributes() has issues with MultiChoice getTextList(), so we extract 'bad_useragents' manually here:
                if ((isset($form_data['bad_useragents'])) && (!empty($form_data['bad_useragents']))) {
                    $attributes['bad_useragents'] = urldecode(arrayToString(stringNToArray($form_data['bad_useragents'])));
                }

                // Actual submit to CODB of Apache Settings:
                $CI->cceClient->setObject("System", $attributes, "Web");

                if (($web['httpPort'] != $httpPortField) || ($web['sslPort'] != $sslPortField) || ($web['HSTS'] != $attributes['HSTS'])) {
                    // In case the HTTP-port, SSL-port or HSTS settings are changed (or if Nginx is enabled as SSL-Proxy!), then we also need to update all 
                    // VHost containers with the new port information. Which is a bit messy. But we can simply do so by running /usr/sausalito/sbin/SSL_fixer.pl. 
                    // And as that may take a while to finish, we simply shoot it into the background via fork() ...
                    $nfk = '';
                    $ret = $CI->serverScriptHelper->fork("/usr/sausalito/sbin/SSL_fixer.pl", $nfk, 'root', $BX_SESSION['sessionId']);
                }

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }

                // Actual submit to CODB of Nginx Settings:
                $CI->cceClient->setObject("System", $attributesNginx, "Nginx");

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
        }

        //
        //-- Generate page:
        //

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $BxPage->setVerticalMenuChild('base_apache');
        $page_module = 'base_sysmanage';

        $defaultPage = "basicSettingsTab";
        $nginxPage = "nginxSettingsTab";

        $block = $factory->getPagedBlock("apacheSettings", array($defaultPage, $nginxPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);

        // Add divider:
        $ffs = $factory->addBXDivider("DIVIDER_TOP", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("DIVIDER_TOP", false),
                $defaultPage
                );          

        $ffs = $factory->getBoolean("hostnameLookups", $web["hostnameLookups"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("hostnameLookupsField"),
            $defaultPage
        );

        // HTTP Port:
        $httpPortField = $factory->getInteger("httpPort", $web["httpPort"], "80", "65535");
        $httpPortField->setWidth(5);
        $httpPortField->showBounds(1);
        $block->addFormField(
            $httpPortField,
            $factory->getLabel("httpPortField"),
            $defaultPage
        );

        // SSL Port:
        $sslPortField = $factory->getInteger("sslPort", $web["sslPort"], "443", "65535");
        $sslPortField->setWidth(5);
        $sslPortField->showBounds(1);
        $block->addFormField(
            $sslPortField,
            $factory->getLabel("sslPortField"),
            $defaultPage
        );

        // Checkbox for 'Header add Strict-Transport-Security': 
        $ffs = $factory->getBoolean("HSTS", $web["HSTS"]);
        $block->addFormField( 
            $ffs, 
            $factory->getLabel("HSTS"),
            $defaultPage
        ); 

        $max_client = $factory->getInteger("maxClients", $web["maxClients"], 1, $web["maxClientsAdvised"]);
        $max_client->setWidth(5);
        $max_client->showBounds(1);

        $block->addFormField(
            $max_client,
            $factory->getLabel("maxClientsField"),
            $defaultPage
        );


        $min = $factory->getInteger("minSpare", $web["minSpare"], 1, $web["minSpareAdvised"]);
            $min->setWidth(5);
            $min->showBounds(1);

        $block->addFormField(
            $min,
            $factory->getLabel("minSpareField"),
            $defaultPage
        );

        $max_spare = $factory->getInteger("maxSpare", $web["maxSpare"], 1, $web["maxSpareAdvised"]);
        $max_spare->setWidth(5);
        $max_spare->showBounds(1);

        $block->addFormField(
            $max_spare,
            $factory->getLabel("maxSpareField"),
            $defaultPage
        );

        //
        //--- Badbots and robots.txt:
        //

        // Add divider:
        $ffs = $factory->addBXDivider("DIVIDER_BOTS", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("DIVIDER_BOTS", false),
                $defaultPage
                );

        // Checkbox for 'xmlrpc deny': 
        $xmlrpc_deny_field = $factory->getBoolean("xmlrpc_deny", $web["xmlrpc_deny"]);
        $block->addFormField( 
            $xmlrpc_deny_field, 
            $factory->getLabel("xmlrpc_deny"),
            $defaultPage
        );

        // Checkbox for 'default_robots_txt deny': 
        $default_robots_txt_field = $factory->getBoolean("default_robots_txt", $web["default_robots_txt"]);
        $block->addFormField( 
            $default_robots_txt_field, 
            $factory->getLabel("default_robots_txt"),
            $defaultPage
        );

        // Bots MultiChoice:
        $Bots_Multichoice = $factory->getMultiChoice('bad_useragents_deny');
        $enable = $factory->getOption('bad_useragents_deny', $web['bad_useragents_deny'], 'rw');
        $xxx = $factory->getLabel('bad_useragents_deny', false);
        $enable->setLabel($xxx);
        $Bots_Multichoice->addOption($enable);

        // bad_useragents:
        $bad_useragents = $factory->getTextList('bad_useragents', $web['bad_useragents'], 'rw');
        $bad_useragents->setOptional(FALSE);
        $bad_useragents->setType('alphanum_plus_multiline');
        $enable->addFormField($bad_useragents, $factory->getLabel('bad_useragents'));

        // good_useragents:
        $good_useragents = $factory->getTextList('good_useragents', $web['good_useragents'], 'rw');
        $good_useragents->setOptional(FALSE);
        $good_useragents->setType('alphanum_plus_multiline');
        $enable->addFormField($good_useragents, $factory->getLabel('good_useragents'));

        // Out with the enabler:
        $block->addFormField($Bots_Multichoice, $factory->getLabel('bad_useragents_deny'), $defaultPage);

        // BlueOnyx.conf modification stuff:

        // Add divider:
        $ffs = $factory->addBXDivider("DIVIDER_EXPLANATION", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("DIVIDER_EXPLANATION", false),
                $defaultPage
                );

        $my_TEXT = $i18n->getClean("[[base-apache.BlueOnyx_Info_Text]]") . "<br><br>";
        $infotext = $factory->getHtmlField("BlueOnyx_Info_Text", $my_TEXT, 'r');
        $infotext->setLabelType("nolabel");
        $block->addFormField(
          $infotext,
          $factory->getLabel(" ", false),
            $defaultPage
        );

        // Add divider:
        $ffs = $factory->addBXDivider("DIVIDER_OPTIONS", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("DIVIDER_OPTIONS", false),
                $defaultPage
                );      

        $ffs = $factory->getBoolean("Options_All", $web["Options_All"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_AllField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("Options_FollowSymLinks", $web["Options_FollowSymLinks"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_FollowSymLinksField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("Options_Includes", $web["Options_Includes"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_IncludesField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("Options_Indexes", $web["Options_Indexes"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_IndexesField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("Options_MultiViews", $web["Options_MultiViews"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_MultiViewsField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("Options_SymLinksIfOwnerMatch", $web["Options_SymLinksIfOwnerMatch"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("Options_SymLinksIfOwnerMatchField"),
            $defaultPage
        );

        // Add divider:
        $ffs = $factory->addBXDivider("DIVIDER_ALLOWOVERRIDE", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("DIVIDER_ALLOWOVERRIDE", false),
                $defaultPage
                );

        $ffs = $factory->getBoolean("AllowOverride_All", $web["AllowOverride_All"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_AllField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("AllowOverride_AuthConfig", $web["AllowOverride_AuthConfig"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_AuthConfigField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("AllowOverride_FileInfo", $web["AllowOverride_FileInfo"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_FileInfoField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("AllowOverride_Indexes", $web["AllowOverride_Indexes"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_IndexesField"),
            $defaultPage
        );
        $ffs = $factory->getBoolean("AllowOverride_Limit", $web["AllowOverride_Limit"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_LimitField"),
            $defaultPage
        );

        $ffs = $factory->getBoolean("AllowOverride_Options", $web["AllowOverride_Options"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("AllowOverride_OptionsField"),
            $defaultPage
        );

        //
        //--- NGINX Tab:
        //

        // Add divider:
        $ffs = $factory->addBXDivider("GENERAL_NGINX_DIVIDER", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("GENERAL_NGINX_DIVIDER", false),
                $nginxPage
                );

        $nginx_TEXT = $i18n->get("[[base-apache.Nginx_Info_Text]]") . "<br><br>";
        $NginxInfotext = $factory->getHtmlField("Nginx_Info_Text", $nginx_TEXT, 'r');
        $NginxInfotext->setLabelType("nolabel");
        $block->addFormField(
          $NginxInfotext,
          $factory->getLabel(" ", false),
          $nginxPage
        );

        $ffs = $factory->getBoolean("enabled", $nginx["enabled"]);
        $block->addFormField(
            $ffs,
            $factory->getLabel("enabled"),
            $nginxPage
        );

        // Add divider:
        $ffs = $factory->addBXDivider("NGINX_CFG_DIVIDER", "");
        $block->addFormField(
                $ffs,
                $factory->getLabel("NGINX_CFG_DIVIDER", false),
                $nginxPage
                );

        $worker_processes = $factory->getTextField("worker_processes", $nginx["worker_processes"], 'rw');
        $worker_processes->setWidth(5);
        $worker_processes->settype("nginxWorker");
        $block->addFormField(
            $worker_processes,
            $factory->getLabel("worker_processes"),
            $nginxPage
        );

        $open_files_limit = `cat /proc/sys/fs/file-max`;
        $worker_connections = $factory->getInteger("worker_connections", $nginx["worker_connections"], 1, $open_files_limit);
        $worker_connections->setWidth(16);
        $worker_connections->showBounds(1);
        $block->addFormField(
            $worker_connections,
            $factory->getLabel("worker_connections"),
            $nginxPage
        );

        //
        //-- HSTS for Nginx:
        //

        $HSTS_Nginx = $factory->getMultiChoice('HSTS_Nginx_enabled');
        $enable = $factory->getOption('HSTS_Nginx', $nginx["HSTS"], 'rw');
        $ffs = $factory->getLabel('enable', false);
        $enable->setLabel($ffs);
        $HSTS_Nginx->addOption($enable);

        $max_age = $factory->getInteger("max_age", $nginx["max_age"], '0', '31536000');
        $max_age->setWidth(8);
        $max_age->showBounds(1);
        $enable->addFormField(
            $max_age,
            $factory->getLabel("max_age")
        );

        $include_subdomains = $factory->getBoolean("include_subdomains", $nginx["include_subdomains"], 'rw');
        $enable->addFormField(
            $include_subdomains,
            $factory->getLabel("include_subdomains")
        );

        $block->addFormField($HSTS_Nginx, $factory->getLabel('HSTS_Nginx_enabled'), $nginxPage);

        //---


        $ssl_session_timeout = $factory->getTextField("ssl_session_timeout", $nginx["ssl_session_timeout"], 'rw');
        $ssl_session_timeout->setWidth(5);
        $ssl_session_timeout->settype("valTime");
        $block->addFormField(
            $ssl_session_timeout,
            $factory->getLabel("ssl_session_timeout"),
            $nginxPage
        );

        $ssl_session_cache = $factory->getTextField("ssl_session_cache", $nginx["ssl_session_cache"], 'rw');
        $ssl_session_cache->setWidth(5);
        $ssl_session_cache->settype("valTime");
        $block->addFormField(
            $ssl_session_cache,
            $factory->getLabel("ssl_session_cache"),
            $nginxPage
        );

        $sstVal = '0';
        if ($nginx["ssl_session_tickets"] == 'on') {
            $sstVal = '1';
        }
        $ssl_session_tickets = $factory->getBoolean("ssl_session_tickets", $sstVal, 'rw');
        $block->addFormField(
            $ssl_session_tickets,
            $factory->getLabel("ssl_session_tickets"),
            $nginxPage
        );

        $resolver_valid = $factory->getTextField("resolver_valid", $nginx["resolver_valid"], 'rw');
        $resolver_valid->setWidth(5);
        $resolver_valid->settype("valTime");
        $block->addFormField(
            $resolver_valid,
            $factory->getLabel("resolver_valid"),
            $nginxPage
        );

        $resolver_timeout = $factory->getTextField("resolver_timeout", $nginx["resolver_timeout"], 'rw');
        $resolver_timeout->setWidth(5);
        $resolver_timeout->settype("valTime");
        $block->addFormField(
            $resolver_timeout,
            $factory->getLabel("resolver_timeout"),
            $nginxPage
        );

        $stapling = '0';
        if ($nginx["ssl_stapling"] == 'on') {
            $stapling = '1';
        }
        $ssl_stapling = $factory->getBoolean("ssl_stapling", $stapling, 'rw');
        $block->addFormField(
            $ssl_stapling,
            $factory->getLabel("ssl_stapling"),
            $nginxPage
        );

        $staplingVerify = '0';
        if ($nginx["ssl_stapling_verify"] == 'on') {
            $staplingVerify = '1';
        }
        $ssl_stapling_verify = $factory->getBoolean("ssl_stapling_verify", $staplingVerify, 'rw');
        $block->addFormField(
            $ssl_stapling_verify,
            $factory->getLabel("ssl_stapling_verify"),
            $nginxPage
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/apache/apache"));

        // Pass on errors:
        $BxPage->setErrors($errors);

        // Assemble page body:
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