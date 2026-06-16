<?php 
namespace Support\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;
use DateTime;

class Ticket extends BaseController {
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

        if (!$CI->getAllowed('managePackage')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-support", "/support/ticket");
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

        if (isset($get_form_data['transaction'])) {
            if ($get_form_data['transaction'] == 'success') {
                $errors[] = ErrorMessage($i18n->get('[[base-support.TicketSent]]'), 'alert_green', 'info_about');
            }
            elseif ($get_form_data['transaction'] == 'fail') {
                $errors[] = ErrorMessage($i18n->get("[[base-support.Err_problem_sending_ticket]]"));
            }
            elseif ($get_form_data['transaction'] == 'cancel') {
                $errors[] = ErrorMessage($i18n->get('[[base-support.TicketSent]]'), 'alert-warning', 'info_about');
            }
        }

        //
        //--- Get CODB-Object of interest: 
        //

        // Get settings
        $Support = $CI->cceClient->get($System['OID'], "Support", array('cce_nocache' => 'cce_nocache'));

        // Tempfile for the JSON encoded ticket:
        $TicketTmpPath = '/var/cache/admserv/' . $BX_SESSION['loginName'] . '_ticket.tmp';

        // Location (URLs) of the various NewLinQ query resources:
        $bluelinq_server    = 'newlinq.blueonyx.it';
        $newlinq_url        = "http://$bluelinq_server/showshops/";
        $serialNumber       = $System['serialNumber'];
        $client_email       = get_data("http://$bluelinq_server/username/$serialNumber");

        // Array for expiry pulldown:
        $sa_expiry_reverse = array(
            'never' => '0',
            '3_days' => '3',
            '5_days' => '5',
            '7_days' => '7',
            '10_days' => '10',
            '14_days' => '14',
            '30_days' => '30',
            '90_days' => '90',
            '180_days' => '180',
            '365_days' => '365'
        );

        $sa_expiry_forward = array(
            '0' => 'never',
            '3' => '3_days',
            '5' => '5_days',
            '7' => '7_days',
            '10' => '10_days',
            '14' => '14_days',
            '30' => '30_days',
            '90' => '90_days',
            '180' => '180_days',
            '365' => '365_days' 
        );

        $prio_forward_num = array(
            'prio_urgent'       => '0',
            'prio_high'         => '0',
            'prio_medium'       => '0',
            'prio_low'          => '0',
            'prio_unspecified'  => '1'
        );

        $severity_forward_num = array(
            'severity_urgent'       => '0',
            'severity_high'         => '0',
            'severity_medium'       => '0',
            'severity_low'          => '0',
            'severity_unspecified'  => '1'
        );

        //
        //--- Handle form validation:
        //

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

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            if (($client_email != "0") && ($client_email != "")) {
                $attributes['client_email'] = $client_email;
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // Check if we are online:
        if (areWeOnline($newlinq_url)) {

          // Get Serial:
          $serialNumber = $System['serialNumber'];

          // Poll NewLinQ about our status:
          $snstatus = "RED";
          $snstatus = get_data("http://$bluelinq_server/snstatus/$serialNumber");
          if (!$snstatus === "RED") {
             $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
          }
          else {
            if ($snstatus === "ORANGE") {
                $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
                $snstatusx = get_data("http://$bluelinq_server/snchange/$serialNumber");
            } 
            else {
                $ipstatus = get_data("http://$bluelinq_server/ipstatus/$serialNumber");
                $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                if ( $ipstatus === "ORANGE" ) {
                    $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                    $ipstatusx = get_data("http://$bluelinq_server/ipchange/$serialNumber");
                }
            }
          }
          // Are we online and in the green?
          if ($snstatus == "GREEN") {
                $online = "1";
                // Get existing ticket numbers (if there are any, newest to oldest):
                $existing_tickets = get_data("http://$bluelinq_server/ticketlist/$serialNumber");
                if (!preg_match('/error/', $existing_tickets)) {
                    if (strlen($existing_tickets) > '4') {
                        $existing_tickets = preg_replace('/"/', '', $existing_tickets);
                        $existing_tickets = preg_split("/\\r\\n|\\r|\\n/", $existing_tickets);
                    }
                }
          }
        }
        else {
            // Not online, poll of 'newlinq.blueonyx.it' failed. Show error message:
            $online = "0";
            $errors[] = ErrorMessage($i18n->get('[[base-support.Error_NewLinQ_Down]]'), 'alert_red', 'alarm_bell');
        }

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            $cleaned_attributes = array();

            // Clone $attributes:
            $attributes_clone = $attributes;

            //
            //-- Handle creation of 'Support-Account':
            //

            if ($attributes['allow_access'] == '1') {
                // Check if the 'Support-Account' already exists:
                $SAadmins = $CI->cceClient->findx('User', 
                                array("capLevels" => 'adminUser', 'name' => $Support['support_account']),
                                array(), 
                                "",
                                "");

                $SA_Password = createRandomPassword('15', 'alpha');

                if (count($SAadmins) == '0') {
                    // 'Support-Account' does not exists yet. Create it:
                    $big_ok = $CI->cceClient->create('User',
                                    array(
                                        'fullName' => $Support['support_account_name'],
                                        'sortName' => "",
                                        'name' => $Support['support_account'],
                                        'password' => $SA_Password,
                                        'capLevels' => '&adminUser&',
                                        'password' => $SA_Password
                                        ));
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // Get the OID of this transaction:
                    if ($big_ok) {
                        $_oid = $big_ok;
                    }

                    // Create succeeded. So we set the rest as well:
                    if ($big_ok) {
                        // Set the disk quota:
                        $CI->cceClient->set($_oid, 'Disk', array('quota' => '200'));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        // Set the rest of the settings:
                        $new_settings = array(
                                            'systemAdministrator' => '1',
                                            'ui_enabled' => '1'
                                            );
                        $big_ok = $CI->cceClient->set($_oid, '', $new_settings);
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        // Activate Shell Access:
                        $ok = $CI->cceClient->set($_oid, 'Shell', array('enabled' => 1));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        // Activate 'RootAccess':
                        $ok = $CI->cceClient->set($_oid, 'RootAccess', array('enabled' => '1'));
                        $errors = array_merge($errors, $CI->cceClient->errors());

                        // Set the 'Sites' settings as well just for sake of completness:
                        $Sites_settings = array(
                                            'quota' => '500000',
                                            'user' => '1',
                                            'max' => '1'
                                            );
                        $ok = $CI->cceClient->set($_oid, 'Sites', $Sites_settings);
                        $errors = array_merge($errors, $CI->cceClient->errors());
                    }
                }
                else {
                    // 'Support-Account' already exists. Modify it:
                    $_oid = $SAadmins[0];

                    // Set the disk quota:
                    $CI->cceClient->set($_oid, 'Disk', array('quota' => '200'));
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // Set the rest of the settings:
                    $new_settings = array(
                                        'systemAdministrator' => '1',
                                        'ui_enabled' => '1',
                                        'fullName' => $Support['support_account_name'],
                                        'sortName' => "",
                                        'capLevels' => '&adminUser&',
                                        'password' => $SA_Password
                                        );
                    $big_ok = $CI->cceClient->set($_oid, '', $new_settings);
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // Activate Shell Access:
                    $ok = $CI->cceClient->set($_oid, 'Shell', array('enabled' => 1));
                    $errors = array_merge($errors, $CI->cceClient->errors());

                    // Activate 'RootAccess':
                    $ok = $CI->cceClient->set($_oid, 'RootAccess', array('enabled' => '1'));
                    $errors = array_merge($errors, $CI->cceClient->errors());
                }

                //
                //-- Handle SSH Key/Certs:
                //

                // Defaults:
                $SA_account_name = $Support['support_account'];
                $runas = 'root';

                // Delete existing .ssh directory:
                $ret = $CI->serverScriptHelper->shell("/bin/ls --directory ~$SA_account_name/.ssh", $is_there, $runas, $BX_SESSION['sessionId']);
                if (!empty($is_there)) {
                    if ((preg_match('/^\/home\/\.users\/(.*)$/', $is_there)) || (preg_match('/^\/home\/\.sites\/(.*)$/', $is_there))) {
                        # ~$SA_account_name/.ssh exists
                        $full_path_to_dotsshdir = chop($is_there);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -Rf ~$SA_account_name/.ssh", $nfk, $runas, $BX_SESSION['sessionId']);
                    }
                }

                // Array for SSH-Key Reset:
                $ssh_reset = array(
                                    'bits' => '2048',
                                    'keycreate' => '0',
                                    'certcreate' => '0'
                                    );

                // Array for SSH-Key Generation:
                $ssh_creation = array(
                                    'bits' => '4096',
                                    'keycreate' => '1',
                                    'certcreate' => '1'
                                    );

                // Reset:
                $ok = $CI->cceClient->set($_oid, 'SSH', $ssh_reset);
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Key/Cert generation:
                //
                // NOTE: This takes a moment to finish.
                $ok = $CI->cceClient->set($_oid, 'SSH', $ssh_creation);
                $errors = array_merge($errors, $CI->cceClient->errors());

                // Include the PEM file:
                $action_file = $SA_account_name . '.pem';
                $ret = $CI->serverScriptHelper->shell("/bin/cat ~$SA_account_name/.ssh/$action_file", $output, $runas, $BX_SESSION['sessionId']);
                if ($ret != 0) {
                    # File not present.
                }
                else {
                    // Attach:
                    $attributes_clone['PEMcert'] = $output;
                }

                // Note down that a support account has been generated:
                $cleaned_attributes['access_generate'] = '1';

                //
                //-- Handle 'Support-Account' expiry:
                //
                if ($sa_expiry_reverse[$attributes['SAExpiry']] != '0') {
                    // Expire on a given date and time in the future:
                    $SAExpiry = $sa_expiry_reverse[$attributes['SAExpiry']]*24*60*60+time();
                    $cleaned_attributes['access_epoch'] = $SAExpiry;
                    $ndt = new DateTime("@$SAExpiry");
                    $reported_SAExpiry = $ndt->format('Y-m-d H:i:s');
                    $attributes_clone['SAExpiry'] = $reported_SAExpiry;
                }
                else {
                    // Set 'access_epoch' to '0' to mark it to never expire:
                    $cleaned_attributes['access_epoch'] = '0';
                    $attributes_clone['SAExpiry'] = 'Never';
                }
            }
            else {
                // Note down that a support account is NOT part of the ticket:
                $cleaned_attributes['access_generate'] = '0';
            }

            if (isset($attributes_clone['include_sos'])) {
                // Ticket includes SOS-Report:
                if ($attributes_clone['include_sos'] == '1') {
                    unset($attributes_clone['include_sos']);
                    $cleaned_attributes['sos_generate'] = time();
                    $cleaned_attributes['include_sos'] = '1';
                    $SOSreportUrl = 'http://' . $System['hostname'] . '.' . $System['domainname'] . ':444' . $Support['sos_external'];
                    $attributes_clone['sos_report'] = $SOSreportUrl;
                }
                else {
                    // Ticket does NOT include SOS-Report:
                    $cleaned_attributes['include_sos'] = '0';
                    $cleaned_attributes['ticket_trigger'] = time();
                }
            }

            // Prefix Ticket Subject with type of message and build number and append ticket ID of existing ticket:
            $attributes_clone['ticket_subject'] = 'Ticket(' . $System['productBuild'] . '): ' . $attributes_clone['ticket_subject'];

            // We use the raw 'ticketDescription', as GetFormAttributes() has stripped the formatting
            // turned it into a scalar. Which is not what we want to email:
            unset($attributes_clone['ticketDescription']);
            $attributes_clone['ticketDescription'] = $form_data['ticketDescription'];

            unset($attributes_clone['support_account']);

            if ($attributes_clone['allow_access'] == '1') {
                $attributes_clone['support_account'] = $Support['support_account'];
                $attributes_clone['password'] = $SA_Password;

                // Add Hostname:
                $attributes_clone['FQDN'] = $System['hostname'] . '.' . $System['domainname'];

                // Add IP-Address:
                $attributes_clone['ipaddr'] = get_primary_ipv4_ip();
                $attributes_clone['ipaddr_IPv6'] = get_primary_ipv6_ip();

                // Get SSH Settings and include them:
                $SSH = $CI->cceClient->get($System['OID'], "SSH");
                $attributes_clone['SSH_Enabled'] = $SSH['enabled'];
                $attributes_clone['SSH_Port'] = $SSH['Port'];
                $attributes_clone['XPasswordAuthentication'] = $SSH['XPasswordAuthentication'];
                $attributes_clone['PubkeyAuthentication'] = $SSH['PubkeyAuthentication'];
                $attributes_clone['PermitRootLogin'] = $SSH['PermitRootLogin'];
            }

            //
            //-- Add Paid Support Fields:
            //
            // These fields are for the new backend and not stored in CODB
            // Radio buttons submit the key directly (free, standard, priority, etc.)
            if (isset($form_data['support_type'])) {
                $attributes_clone['support_type'] = $form_data['support_type'];
            } else {
                $attributes_clone['support_type'] = 'free';
            }
            
            if (isset($form_data['price_offered'])) {
                $attributes_clone['price_offered'] = $form_data['price_offered'];
            } else {
                $attributes_clone['price_offered'] = '0';
            }
            
            // Map prepaid_hours display value to hours number
            $prepaid_hours_map = array(
                '5 hours (€275)' => '5',
                '10 hours (€500)' => '10',
                '20 hours (€900)' => '20'
            );
            if (isset($form_data['prepaid_hours'])) {
                $display_value = $form_data['prepaid_hours'];
                $attributes_clone['prepaid_hours'] = isset($prepaid_hours_map[$display_value])
                    ? $prepaid_hours_map[$display_value]
                    : '5';
            } else {
                $attributes_clone['prepaid_hours'] = '5';
            }
            
            error_log("DEBUG Ticket.php: Final support_type in attributes_clone: " . $attributes_clone['support_type']);
            
            //
            //-- Handle Recipient Selector:
            //
            if (isset($attributes_clone['recipient_selector'])) {
                // Had a selector for the email address:
                if ($attributes_clone['recipient_selector'] == $Support['isp_support_name']) {
                    // Email the ISP:
                    unset($attributes_clone['recipient_selector']);
                    $attributes_clone['recipient_name'] = $Support['isp_support_name'];
                    $attributes_clone['recipient_email'] = $Support['isp_support_email'];
                }
                else {
                    // Email BlueOnyx Support:
                    unset($attributes_clone['recipient_selector']);
                    $attributes_clone['recipient_name'] = $Support['bx_support_name'];
                    $attributes_clone['recipient_email'] = $Support['bx_support_email'];
                }
            }
            else {
                // ISP data hasn't been set and there was only the choice to mail BlueOnyx Support:
                $attributes_clone['recipient_name'] = $Support['bx_support_name'];
                $attributes_clone['recipient_email'] = $Support['bx_support_email'];
            }
            //
            //-- Priority/Severity:
            //

            $attributes_clone['Priority'] = $CI->cceClient->scalar_to_string($attributes_clone['Priority']);
            $attributes_clone['Severity'] = $CI->cceClient->scalar_to_string($attributes_clone['Severity']);

            //
            //-- Add serialNumber:
            //

            $attributes_clone['serialNumber'] = $serialNumber;

            //
            //-- Add Info about 2FA:
            //

            if (isset($System['gui_2fa'])) {
                $attributes_clone['gui_2fa'] = $System['gui_2fa'];
            }

            if (isset($System['gui_2fa_users'])) {
                $attributes_clone['gui_2fa_users'] = $System['gui_2fa_users'];
            }

            if (($attributes['allow_access'] == '1') && (isset($SAadmins[0]))) {
                $_oid = $SAadmins[0];
                $SupportAccount_SSH = $CI->cceClient->get($_oid, "SSH", array('cce_nocache' => 'cce_nocache'));

                // Support-Account needs 2FA:
                if (($System['gui_2fa'] == '1') && (($System['gui_2fa_users'] === 'ALL') || ($System['gui_2fa_users'] === 'ADMINS') || ($System['gui_2fa_users'] === 'PRIVILEGED'))) {

                    if ($SupportAccount_SSH['GoogleAuthentication'] == '0') {
                        $CI->cceClient->set($_oid, "SSH",  array('GoogleAuthentication' => '1'));
                        $errors = array_merge($errors, $CI->cceClient->errors());
                        sleep(3);
                    }

                    // Include the .google_authenticator file:
                    $output = '';
                    $ret = $CI->serverScriptHelper->shell("/bin/cat ~$SA_account_name/.google_authenticator", $output, $runas, $BX_SESSION['sessionId']);

                    if ($ret != 0) {
                        # File not present.
                    }
                    else {
                        // Attach:
                        $attributes_clone['google_authenticator'] = $output;
                    }
                }
            }

            //
            //--- Add info about GUI_PORT and GUI_URLs:
            //

            if (isset($System['GUI_PORT'])) {
                $attributes_clone['GUI_PORT'] = $System['GUI_PORT'];
            }

            if (isset($System['GUI_URLs'])) {
                $attributes_clone['GUI_URLs'] = $System['GUI_URLs'];
            }

            // Assemble JSON encoded Ticket:
            $ticket = json_encode($attributes_clone);

            // Write the Ticket temporary file:
            if (!write_file($TicketTmpPath, $ticket)) {
                $errors[] = ErrorMessage($i18n->get('[[base-support.Err_writing_tempfile]]'), 'alert_red', 'alarm_bell');
            }
            else {
                $ret = $CI->serverScriptHelper->shell("/bin/chmod 00640 $TicketTmpPath", $output, 'admserv', $BX_SESSION['sessionId']);
            }

            // Add Ticket tempfile path to CODB:
            $cleaned_attributes['ticket'] = $TicketTmpPath;

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Support",  $cleaned_attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No errors. Forward to new paid support backend:
            if ((count($errors) == "0")) {
                // DEBUG: Log what we're about to send
                error_log("DEBUG Ticket.php: Forwarding ticket to support-new. Ticket JSON length: " . strlen($ticket));
                
                // Build POST data
                $post_data = array('ticket' => $ticket);
                $post_string = http_build_query($post_data);
                error_log("DEBUG Ticket.php: POST string length: " . strlen($post_string));
                error_log("DEBUG Ticket.php: POST string (first 200 chars): " . substr($post_string, 0, 200));
                
                // Forward ticket to new backend via cURL
                $ch = curl_init('https://support.blueonyx.it/support-new');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
                curl_setopt($ch, CURLOPT_USERAGENT, 'BlueLinQ/1.0');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // DEBUG: Don't follow redirects
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_HEADER, true); // DEBUG: Include headers in output
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($post_string)
                ));
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                
                // DEBUG: Log cURL info
                error_log("DEBUG Ticket.php: cURL effective URL: " . $info['url']);
                error_log("DEBUG Ticket.php: cURL content type: " . $info['content_type']);
                error_log("DEBUG Ticket.php: HTTP Code: " . $http_code);
                error_log("DEBUG Ticket.php: cURL Error: " . ($curl_error ? $curl_error : "none"));
                error_log("DEBUG Ticket.php: Response length: " . strlen($response));
                error_log("DEBUG Ticket.php: Response headers: " . substr($response, 0, strpos($response, "\r\n\r\n") ?: 500));
                
                if ($curl_error) {
                    $errors[] = ErrorMessage("Failed to submit to support server: " . $curl_error, 'alert_red', 'alarm_bell');
                    $redirect_URL = "/support/ticket";
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }
                else {
                    // Output the response from the new backend (payment page or success message)
                    // Strip headers from response since we included them
                    $header_size = strpos($response, "\r\n\r\n");
                    if ($header_size !== false) {
                        $body = substr($response, $header_size + 4);
                    } else {
                        $body = $response;
                    }
                    echo $body;
                    exit;
                }
            }
            else {
                $errors[] = ErrorMessage($i18n->get('[[base-support.Err_problem_sending_ticket]]'), 'alert_red', 'alarm_bell');
                $redirect_URL = "/support/ticket";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //-- Own page logic:
        //

        if (($Support['client_name'] == "") || ($Support['client_email'] == "")) {
            $errors[] = ErrorMessage($i18n->get('[[base-support.Err_sender_contact_details]]'), 'alert_red', 'alarm_bell');
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/support/ticket");
        $BxPage->setErrors($errors);

        // Support type options (for reference):
        $support_type_labels = array(
            'free' => 'Free / As-Requested',
            'standard' => 'Standard Incident (€150+)',
            'priority' => 'Priority Incident (€300+)',
            'contract' => 'Monthly Contract (€250/mo)',
            'prepaid' => 'Prepaid Hours'
        );

        // Prepaid hours options:
        $prepaid_hours_array = array(
            '5_hours' => '5 hours (€275)',
            '10_hours' => '10 hours (€500)', 
            '20_hours' => '20 hours (€900)'
        );

        // Set Menu items:
        $BxPage->setVerticalMenu('base_support');
        $page_module = 'base_software';

        $defaultPage = 'default';

        $block = $factory->getPagedBlock("ticket", array($defaultPage));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- defaultPage:
        //

        // Check if we're here after a submit transaction:
        if (isset($get_form_data['sent'])) {
            if ($get_form_data['sent'] == 'TRUE') {
                // Report has been sent:
                $report_sent = $factory->getHTMLField("report_sent", "<br>" . $i18n->getHtml("[[base-support.TicketSent]]"), "r");
                $report_sent->setLabelType("nolabel");
                $block->addFormField(
                  $report_sent,
                  $factory->getLabel("report_sent"),
                  $defaultPage
                );
            }
        }
        else {

            // Show the form:

            // Add divider:
            $xxx = $factory->addBXDivider("sender", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("sender", false),
                    $defaultPage
                    );

            $client_name = $factory->getTextField("client_name", $Support['client_name'], 'r');
            $client_name->setType("");
            $block->addFormField(
              $client_name,
              $factory->getLabel("client_name"),
              $defaultPage
            );

            $client_email = $factory->getEmailAddress("client_email", $Support['client_email'], 'r');
            $block->addFormField(
              $client_email,
              $factory->getLabel("client_email"),
              $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("recipient", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("recipient", false),
                    $defaultPage
                    );

            if (($Support['isp_support_name'] != "") && ($Support['isp_support_email'] != "")) {

                // Recipient seclector:
                $recipient_selector_array = array(
                    'blueonyx' => $Support['bx_support_name'],
                    'isp' => $Support['isp_support_name']
                );

                // Add pulldown for recipient selector:
                $recipient_selector = $factory->getMultiChoice("recipient_selector", array_values($recipient_selector_array));
                $recipient_selector->setSelected('blueonyx', true);
                $recipient_selector->setOptional(false);
                $block->addFormField(
                    $recipient_selector, 
                    $factory->getLabel("recipient_selector"), 
                    $defaultPage
                );

            }
            else {
                $recipient_name = $factory->getTextField("recipient_name", $Support['bx_support_name'], 'r');
                $client_name->setType("");
                $block->addFormField(
                  $recipient_name,
                  $factory->getLabel("recipient_name"),
                  $defaultPage
                );

                $recipient_email = $factory->getEmailAddress("recipient_email", $Support['bx_support_email'], 'r');
                $block->addFormField(
                  $recipient_email,
                  $factory->getLabel("recipient_email"),
                  $defaultPage
                );
            }

            // Add divider:
            $xxx = $factory->addBXDivider("ticketType", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("ticketType", false),
                    $defaultPage
                    );

            // Support Type selector (for paid support) - Using Radio buttons:
            // The array keys are the labels, values are what gets submitted
            //$id_support_type_options = array(
            //    'free' => 'Free / As-Requested',
            //    'standard' => 'Standard Incident (€150+)',
            //    'priority' => 'Priority Incident (€300+)',
            //    'contract' => 'Monthly Contract (€250/mo)',
            //    'prepaid' => 'Prepaid Hours'
            //);

            $id_support_type_options = array(
                'free' => 'free',
                'standard' => '1',
                'priority' => 'priority',
                'contract' => 'contract',
                'prepaid' => 'prepaid'
            );

            $support_type_radio = $factory->getRadio("support_type", $id_support_type_options, "rw");
            //$support_type_radio->setSelected('standard', true);
            $block->addFormField(
                $support_type_radio,
                $factory->getLabel("support_type"),
                $defaultPage
            );

            // Info text about paid support:
            $incident_definition_formal = $i18n->get('[[base-support.incident_definition_formal]]');
            $incident_definition_help = $i18n->get('[[base-support.incident_definition_help]]');
            $incident_examples = $i18n->get('[[base-support.incident_examples]]');
            $incident_non_examples = $i18n->get('[[base-support.incident_non_examples]]');

            $out_elmer =<<<EOF
                        <div class="well card-view">
                            <p>$incident_definition_formal</p>
                            <p>$incident_definition_help</p>
                            <p>$incident_examples</p>
                            <p>$incident_non_examples</p>
                        </div>
            EOF;

            $out_adminica = "<span mb-10\">$incident_definition_formal<br>" .
                            "<span mb-10\">$incident_definition_help<br>" .
                            "<span mb-10\">$incident_examples<br>" .
                            "<span mb-10\">$incident_non_examples</small>";


            $explainer_table = $out_elmer;
            if ($BxPage->getGuiTheme() === 'adminica') {
                $explainer_table = $out_adminica;
            }

            $support_info = $factory->getHTMLField("support_info", $explainer_table);
            $support_info->setLabelType("nolabel");
            $block->addFormField(
                $support_info,
                $factory->getLabel("support_info", false),
                $defaultPage
            );

            // Price offered (for standard/priority incidents - user sets amount above minimum):
            $price_offered = $factory->getInteger("price_offered", 0, 'rw');
            $price_offered->setOptional(TRUE);
            $price_offered->setType("");
            $block->addFormField(
                $price_offered,
                $factory->getLabel("price_offered"),
                $defaultPage
            );

            // Prepaid hours selector (for contract/prepaid options):
            $prepaid_hours = $factory->getMultiChoice("prepaid_hours", array_values($prepaid_hours_array));
            $prepaid_hours->setSelected('5_hours', true);
            $prepaid_hours->setOptional(false);
            $block->addFormField(
                $prepaid_hours,
                $factory->getLabel("prepaid_hours"),
                $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("ticketTitle", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("ticket", false),
                    $defaultPage
                    );

            $ticket_subject = $factory->getTextField("ticket_subject", '', 'rw');
            $ticket_subject->setType("");
            $block->addFormField(
              $ticket_subject,
              $factory->getLabel("ticket_subject"),
              $defaultPage
            );

            $server_model = $factory->getTextField("server_model", $System['productName'] . ' (' . $System['productBuildString'] . ')', 'r');
            $server_model->setType("");
            $block->addFormField(
              $server_model,
              $factory->getLabel("server_model"),
              $defaultPage
            );

            // Priority:
            $xxx = $factory->getRadio("Priority", $prio_forward_num, "rw");
            $block->addFormField(
                $xxx,
                $factory->getLabel("Priority"),
                $defaultPage
            );

            // Severity:
            $xxx = $factory->getRadio("Severity", $severity_forward_num, "rw");
            $block->addFormField(
                $xxx,
                $factory->getLabel("Severity"),
                $defaultPage
            );

            $ticketURL = $factory->getTextField("ticketURL", '', 'rw');
            $ticketURL->setOptional(TRUE);
            $ticketURL->setType("");
            $block->addFormField(
              $ticketURL,
              $factory->getLabel("ticketURL"),
              $defaultPage
            );

            $include_sos = $factory->getBoolean("include_sos", '0', "");
            $block->addFormField(
              $include_sos,
              $factory->getLabel("include_sos"),
              $defaultPage
            );

            //
            //-- Enable alter-admin account:
            //

            // This is a bit of a cheat: Within a getMultiChoice() we can't use read-only formfields.
            // So we do a getHTMLField() instead:
            $support_account = $factory->getHTMLField("support_account", $Support['support_account'], "r");

            // Prepare getMultiChoice():
            $allow_accessToggle = $factory->getMultiChoice('allow_access');
            $enable = $factory->getOption('enable', '1');
            $xxx = $factory->getLabel('enable', false);
            $enable->setLabel($xxx);

            // Add FormFields to it:
            $enable->addFormField($support_account, $factory->getLabel("support_account"), $defaultPage);

            // Add pulldown for 'alter-admin' expiry:
            $SAExpiry = $factory->getMultiChoice("SAExpiry", array_values($sa_expiry_forward));
            $SAExpiry->setSelected($sa_expiry_forward['30'], true);
            $SAExpiry->setOptional(false);
            $enable->addFormField(
                $SAExpiry, 
                $factory->getLabel("SAExpiry"), 
                $defaultPage);

            // Add it all:
            $allow_accessToggle->addOption($enable);

            // Out with the constructed getMultiChoice():
            $block->addFormField(
                    $allow_accessToggle,
                    $factory->getLabel('allow_access'),
                    $defaultPage
                );

            $ticketDescription = $factory->getTextList("ticketDescription", '', 'rw');
            $ticketDescription->setOptional(FALSE);
            $ticketDescription->setType("");
            $block->addFormField(
              $ticketDescription,
              $factory->getLabel("ticketDescription"),
              $defaultPage
            );

            //
            //--- Add the buttons
            //

            // Disable the Save-Button if the Support-Settings haven't been configured yet:
            $save_button = $factory->getSaveButton($BxPage->getSubmitAction());
            if (($Support['client_name'] == "") || ($Support['client_email'] == "")) {
                $save_button->setDisabled(TRUE);
            }

            $block->addButton($save_button);
            $block->addButton($factory->getCancelButton("/support/ticket"));
        }

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