<?php 
namespace Email\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Emailsettings extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    private function isInteger($input){
        return(ctype_digit(strval($input)));
    }

    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        if (!$CI->getAllowed('serverEmail')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();

        // Get System Object WITHOUT using the cache:
        $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        
        // Update 'System' Object in Redis/Valgrind cache:
        $sys_key = 'admserv:cache:cce:System';
        $this->rset($sys_key, $System, 15); // 15s is safe
        
        // Update 'System' Object in BaseController:
        $CI->setSystem($this->BX_System);

        $System = $CI->getSystem();
        $email = $CI->cceClient->get($System['OID'], "Email");
        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-email", "/email/emailsettings");
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
        //--- Fetch list of available OpenDKIM info:
        //

        $output = '';
        $failed_to_fetch = '0';
        $opendkim_array = array();
        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/bin/get_opendkim.pl --domain all", $output, 'root', $BX_SESSION['sessionId']);
        if ($ret != 0) {
            // File not present.
            $failed_to_fetch = '1';
        }
        else {
            $opendkim_array = json_decode($output, true, JSON_FORCE_OBJECT);
            $json_error = json_last_error();
            if ($json_error == '1') {
                // Failed to decode JSON:
                $failed_to_fetch = '1';
            }
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array( "maxRecipientsPerMessage", "queueTime");

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
            // Data transformation:
            if (isset($attributes['maxMessageSize'])) {
                $max = $attributes['maxMessageSize'] ? $attributes['maxMessageSize']*1024 : "";
                $attributes['maxMessageSize'] = $max;
            }

            $Selected_MTA = 'POSTFIX';
            if (isset($attributes['MTA'])) {
                $Selected_MTA = strtoupper($attributes['MTA']);
                unset($attributes['MTA']);
            }

            $Selected_MBOX = 'MBOX';
            if (isset($attributes['Mailbox'])) {
                $Selected_MBOX = strtoupper($attributes['Mailbox']);
                unset($attributes['Mailbox']);
            }

            $MBCONVERT = $System['Mailbox_convert'];
            if (isset($attributes['MBCONVERT'])) {
                if ($attributes['MBCONVERT'] == '1') {
                    $MBCONVERT = time();
                }
                unset($attributes['MBCONVERT']);
            }

            // Reverse display value for OpenDKIM to proper storage value again:
            if (isset($attributes['OpenDKIM_Mode'])) {
                $OpenDKIM_Mode_Choices_reversed = array('verify' => 'v', 'sign' => 's', 'sign_verify' => 'sv');
                if (isset($OpenDKIM_Mode_Choices_reversed[$attributes['OpenDKIM_Mode']])) {
                    // We do have a known good value? Use it:
                    $attributes['OpenDKIM_Mode'] = $OpenDKIM_Mode_Choices_reversed[$attributes['OpenDKIM_Mode']];
                }
                else {
                    // Assume safe default:
                    $attributes['OpenDKIM_Mode'] = 's';
                }
            }

            // For security reasons the only 'queueTime' we still allow is 'immediate':
            //$queueTimeMap = array("queue0" => "immediate", "queue15" => "quarter-hourly", "queue30" => "half-hourly", "queue60" => "hourly", "queue360" => "quarter-daily", "queue1440" => "daily");
            //$queueTimeMap = array("queue0" => "immediate");
            //$attributes['queueTime'] = $queueTimeMap[$attributes['queueTime']];
            $attributes['queueTime'] = 'immediate';

            $maxRecipientsPerMessageMap = 
                array(
                "unlimited" => "0", 
                    "5" => "5", 
                    "10" => "10", 
                    "15" => "15", 
                    "20" => "20", 
                    "25" => "25", 
                    "50" => "50", 
                    "75" => "75", 
                    "100" => "100", 
                    "125" => "125", 
                    "150" => "150", 
                    "175" => "175", 
                    "200" => "200"
                );
            $attributes['maxRecipientsPerMessage'] = $maxRecipientsPerMessageMap[$attributes['maxRecipientsPerMessage']];

            // Smart Relay Checks:
            if ($attributes['enable_relay'] === '1') {

                // Check if we have 'smartRelay':
                if (empty($attributes['smartRelay'])) {
                    $errors[] = ErrorMessage($i18n->getHtml("[[base-email.no_smartRelay_set]]"));
                }

                // Check if we have a Port:
                if (empty($attributes['relay_port'])) {
                    $errors[] = ErrorMessage($i18n->getHtml("[[base-email.no_smartRelay_port]]"));
                }

                // Password from attributes may be empty. If so, check if we already have one in CODB:
                if (((!empty($attributes['relay_user'])) && (empty($attributes['relay_pass']))) && (!empty($email["relay_pass"]))) {
                    $attributes['relay_pass'] = $email["relay_pass"];
                }

                // Username + Password: If one is given, the other must be present, too:
                if (!empty($attributes['relay_user']) xor !empty($attributes['relay_pass'])) {
                    $errors[] = ErrorMessage(
                        $i18n->getHtml("[[base-email.smartRelay_credentials_missing]]")
                    );
                }
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Submit to CODB to set MTA and Mailbox:
            $CI->cceClient->set($System['OID'], "",  array('MTA' => $Selected_MTA, 'Mailbox' => $Selected_MBOX, 'Mailbox_update' => time()));

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            if ($System['Mailbox_convert'] != $MBCONVERT) {
                // Submit to CODB to set conditionally trigger an Mbox conversion:
                $CI->cceClient->set($System['OID'], "",  array('Mailbox_convert' => time()));

                // CCE errors that might have happened during submit to CODB:
                $CCEerrors = $CI->cceClient->errors();
                foreach ($CCEerrors as $object => $objData) {
                    // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                    $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
                }
            }

            // Actual submit to CODB:
            $CI->cceClient->set($System['OID'], "Email",  $attributes);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }
            // Replace the CODB obtained values in our Form with the one we just posted to CCE:
            $email = $form_data;
            $System['MTA'] = $Selected_MTA;
            $System['Mailbox'] = $Selected_MBOX;

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors);
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/email/emailsettings");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_sysmanage';

        $defaultPage = "basic";

        if ($System['MTA'] == 'POSTFIX') {
            $array_pages = array($defaultPage, "advanced", "blacklist", "OpenDKIM");
            $postfix_acl = '';
        }
        else {
            $array_pages = array($defaultPage, "advanced", "mx", "blacklist", "OpenDKIM");
            $postfix_acl = 'rw';
        }

        $block = $factory->getPagedBlock("emailSettings", $array_pages);

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($defaultPage);

        //
        //--- Basic Tab
        //

        // Add divider:
        $xxx = $factory->addBXDivider("SMTP", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("SMTP", false),
                $defaultPage
                );

        //
        //-- MTA: Sendmail or Postfix
        //

        //$System
        $MTA_Choices = array("POSTFIX" => "Postfix", "SENDMAIL" => "Sendmail");
        $MTA_select = $factory->getMultiChoice("MTA", array_values($MTA_Choices));
        $MTA_select->setSelected($MTA_Choices[$System['MTA']], true);
        $block->addFormField($MTA_select, $factory->getLabel("MTA"), $defaultPage);

        $xxx = $factory->getBoolean("enableSMTP", $email["enableSMTP"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("enableServersField"),
            $defaultPage
        );

        $xxx = $factory->getBoolean("enableSMTPS", $email["enableSMTPS"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableSMTPSField"),
          "basic"
        );

        $xxx = $factory->getBoolean("enableSMTPAuth", $email["enableSMTPAuth"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableSMTPAuthField"),
          "basic"
        );

        $xxx = $factory->getBoolean("enableSubmissionPort", $email["enableSubmissionPort"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableSubmissionPortField"),
          "basic"
        );

        //
        //-- Email Security: 
        //

        $access = 'rw';
        if ($System['MTA'] == 'SENDMAIL') {
            $access = '';
        }
        else {
            $xxx = $factory->addBXDivider("EmailSecurity_header", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("EmailSecurity_header", false),
                    $defaultPage
                    );
        }

        // Authenticated Sender Protection:
        $xxx = $factory->getBoolean("authsend_protect", $email["authsend_protect"], $access);
        $block->addFormField(
          $xxx,
          $factory->getLabel("authsend_protect"),
          "basic"
        );


        //
        //-- MAILBOX: MBOX or MAILDIR
        //

        $xxx = $factory->addBXDivider("Mailbox_header", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("Mailbox_header", false),
                $defaultPage
                );

        // Mbox selector:
        $Mailbox_Choices = array("MBOX" => "Mbox", "MAILDIR" => "Maildir");
        $Mailbox_select = $factory->getMultiChoice("Mailbox", array_values($Mailbox_Choices));
        $Mailbox_select->setSelected($Mailbox_Choices[$System['Mailbox']], true);
        $block->addFormField($Mailbox_select, $factory->getLabel("Mailbox"), $defaultPage);

        // Convert Mailboxes
        $xxx = $factory->getBoolean("MBCONVERT", '0');
        $block->addFormField(
          $xxx,
          $factory->getLabel("MBCONVERT"),
          "basic"
        );

        //
        //-- Dovecot:
        //

        // imap
        $xxx = $factory->addBXDivider("IMAP", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("IMAP", false),
                $defaultPage
                );      

        $xxx = $factory->getBoolean("enableImap", $email["enableImap"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableImapField"),
          "basic"
        );

        $xxx = $factory->getBoolean("enableImaps", $email["enableImaps"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableImapsField"),
          "basic"
        );

        // pop
        $xxx = $factory->addBXDivider("POP", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("POP", false),
                $defaultPage
                );      

        $xxx = $factory->getBoolean("enablePop", $email["enablePop"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enablePopField"),
          "basic"
        );

        $xxx = $factory->getBoolean("enablePops", $email["enablePops"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enablePopsField"),
          "basic"
        );

        // Z-Push has been removed from 5211R:
        //
        //    // Z-Push
        //    $xxx = $factory->addBXDivider("ZPushActiveSync", "");
        //    $block->addFormField(
        //            $xxx,
        //            $factory->getLabel("ZPushActiveSync", false),
        //            $defaultPage
        //            );
        //
        //    $xxx = $factory->getBoolean("enableZpush", $email["enableZpush"]);
        //    $block->addFormField(
        //      $xxx,
        //      $factory->getLabel("enableZpushField"),
        //      "basic"
        //    );


        //
        //--- Advanced Tab
        //

        if ($System['MTA'] == 'POSTFIX') {
            $queueTimeMap = array("immediate" => "queue0");
        }
        else {
            //$queueTimeMap = array("immediate" => "queue0", "quarter-hourly" => "queue15", "half-hourly" => "queue30", "hourly" => "queue60", "quarter-daily" => "queue360", "daily" => "queue1440");
            // For security reasons we the only queueTime still allowed is 'immediate':
            $queueTimeMap = array("immediate" => "queue0");
        }
        $queueSelectedMap = array("immediate" => "queue0", "quarter-hourly" => "queue15", "half-hourly" => "queue30", "hourly" => "queue60", "quarter-daily" => "queue360", "daily" => "queue1440");

        $maxRecipientsPerMessageMap = 
            array(
            "0" => "unlimited", 
                "5" => "5", 
                "10" => "10", 
                "15" => "15", 
                "20" => "20", 
                "25" => "25", 
                "50" => "50", 
                "75" => "75", 
                "100" => "100", 
                "125" => "125", 
                "150" => "150", 
                "175" => "175", 
                "200" => "200" 
            );
          
        $queue_select = $factory->getMultiChoice("queueTime", array_values($queueTimeMap));
        $queue_select->setSelected($queueSelectedMap[$email['queueTime']], true);
        $block->addFormField($queue_select, $factory->getLabel("queueTimeField"), 'advanced');

        // convert from KB to MB
        if (Emailsettings::isInteger($email["maxMessageSize"])) {
            $max = $email["maxMessageSize"]/1024;
        }
        else {
            $max = '0';
        }

        // No maximum size limit if it is 0
        if (($max == "0") || ($max == '')) {
            $max = '';
        }

        $maxEmailSize = $factory->getInteger("maxMessageSize", $max, 1);
        $maxEmailSize->setOptional(true);
        $block->addFormField(
          $maxEmailSize,
          $factory->getLabel("maxEmailSizeField"),
          "advanced"
        );

        // maxRecipientsPerMessage
        $maxRecipientsPerMessage_select = $factory->getMultiChoice("maxRecipientsPerMessage", array_values($maxRecipientsPerMessageMap));
        $maxRecipientsPerMessage_select->setSelected($maxRecipientsPerMessageMap[$email['maxRecipientsPerMessage']], true);
        $block->addFormField($maxRecipientsPerMessage_select, $factory->getLabel("maxRecipientsPerMessageField"), 'advanced');

        // Enable delay_checks
        $xxx = $factory->getBoolean("delayChecks", $email["delayChecks"], $postfix_acl);
        $block->addFormField(
          $xxx,
          $factory->getLabel("delayChecksField"),
          "advanced"
        );

        $masqAddress = $factory->getTextField("masqAddress", $email["masqAddress"], $postfix_acl);
        $masqAddress->setType('IP_or_FQDN');
        $masqAddress->setOptional(true);
        $block->addFormField(
          $masqAddress,
          $factory->getLabel("masqAddressField"),
          "advanced"
        );

        $xxx = $factory->getBoolean("accept_unresolv", $email["accept_unresolv"]);
        $block->addFormField(
            $xxx,
            $factory->getLabel("accept_unresolv"),
            "advanced"
        );

        // Smart Relay Server:
        $enable_relay = $factory->getMultiChoice('enable_relay');
        $enable = $factory->getOption('enable_relay', $email['enable_relay'], 'rw');
        $xxx = $factory->getLabel('enable_relay', false);
        $enable->setLabel($xxx);
        $enable_relay->addOption($enable);

        // Smart Relay Server:
        if ($System['MTA'] === 'POSTFIX') {
            // MTA is Postfix:
            $smartRelay = $factory->getDomainName("smartRelay", $email["smartRelay"]);
            $smartRelay->setType("fqdn");
            $smartRelay->setOptional(true);
            $enable->addFormField($smartRelay, $factory->getLabel('smartRelayField'));

            // Port:
            $relay_port_Field = $factory->getInteger("relay_port", $email["relay_port"], "1", "65535");
            $relay_port_Field->setWidth(5);
            $relay_port_Field->showBounds(1);
            $enable->addFormField($relay_port_Field, $factory->getLabel('relay_port'));

            // Username or Email-Address:
            $relay_user_Field = $factory->getTextField("relay_user", $email["relay_user"], 'rw');
            $relay_user_Field->setOptional(true);
            $relay_user_Field->settype("relay_user");
            $enable->addFormField($relay_user_Field, $factory->getLabel('relay_user'));

            // Password:
            $relay_pass_Field = $factory->getPassword("relay_pass", $email["relay_pass"]);
            $relay_pass_Field->setOptional(TRUE);
            $relay_pass_Field->setConfirm(FALSE);
            $relay_pass_Field->setCheckPass(FALSE);
            $enable->addFormField($relay_pass_Field, $factory->getLabel('relay_pass'));

            // TLS:
            $RELAY_SECURITY = array("enforced" => "enforced", "optional" => "optional", "insecure" => "insecure");
            $relay_security_select = $factory->getMultiChoice("relay_security", array_values($RELAY_SECURITY));
            $relay_security_select->setSelected($RELAY_SECURITY[$email['relay_security']], true);
            $enable->addFormField($relay_security_select, $factory->getLabel('relay_security'));

            // Out with the enabler:
            $block->addFormField($enable_relay, $factory->getLabel('enable_relay'), 'advanced');
        }
        else {
            // MTA is Sendmail:
            $smartRelay = $factory->getDomainName("smartRelay", $email["smartRelay"]);
            $smartRelay->setType("fqdn");
            $smartRelay->setOptional(true);
            $enable->addFormField($smartRelay, $factory->getLabel('smartRelayField'));

            // Out with the enabler:
            $block->addFormField($enable_relay, $factory->getLabel('enable_relay'), 'advanced');

            // Port (hidden and ignored):
            $relay_port_Field = $factory->getTextField("relay_port", '587', '');
            $block->addFormField(
              $relay_port_Field,
              $factory->getLabel("relay_port"),
              "advanced"
            );
        }

        // Hide prior received headers:
        $xxx = $factory->getBoolean("hideHeaders", $email["hideHeaders"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("hideHeadersField"),
          "advanced"
        );

        $poprelay = $factory->getBoolean("popRelay", $email["popRelay"], $postfix_acl);
        $poprelay->setOptional(true);
        $block->addFormField(
          $poprelay,
          $factory->getLabel("popRelayField"),
          "advanced"
        );

        $relay = $factory->getNetAddressList("relayFor", $email["relayFor"]);
        $relay->setOptional(true);
        $block->addFormField(
          $relay,
          $factory->getLabel("relayField"),
          "advanced"
        );

        //if ( ! $product->isRaq() ) { // This is no longer needed and never was needed for BlueOnyx.
        //  $receive = $factory->getDomainNameList("acceptFor", $email["acceptFor"]);
        //  $receive->setOptional(true);
        //  $block->addFormField(
        //    $receive,
        //    $factory->getLabel("receiveField"),
        //    "advanced"
        //  );
        //}

        $blockHost = $factory->getDomainNameList("deniedHosts", $email["deniedHosts"]);
        $blockHost->setOptional(true);
        $block->addFormField(
          $blockHost,
          $factory->getLabel("blockHostField"),
          "advanced"
        );

        $blockUser = $factory->getEmailAddressList("deniedUsers", $email["deniedUsers"]);
        $blockUser->setOptional(true);
        $block->addFormField(
          $blockUser,
          $factory->getLabel("blockUserField"),
          "advanced"
        );

        //
        //-- Secondary MX
        //

        $oids = $CI->cceClient->findx("mx2",array(),array(), 'ascii', 'domain');
        $oidsNum = count($oids);

        $addmod = '/email/secondarymx';

        $scrollList = $factory->getScrollList("mx2List", array("secondaryDomain", " "), array()); 
        $scrollList->setAlignments(array("left", "right"));
        $scrollList->setDefaultSortedIndex('0');
        $scrollList->setSortOrder('ascending');
        $scrollList->setSortDisabled(array('1'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths(array("498", "120")); // Max: 739px

        for($i=0; $i < count($oids); $i++) {
            $oid = $oids[$i];
            $domains = $CI->cceClient->get($oid);
            $domain = $domains['domain'];
            $mapto = $domains['mapto'];

            $modify_button_mx = $factory->getModifyButton("$addmod?_TARGET=$oid");
            $modify_button_mx->setButtonSize("small");
            $modify_button_mx->setButtonSpecialStyle('square_animated');
            $modify_button_mx->setImageOnly(TRUE);
            $modify_button_mx->setTarget('_self');

            $remove_button_mx = $factory->getRemoveButton("$addmod?_RTARGET=$oid");
            $remove_button_mx->setButtonSize("small");
            $remove_button_mx->setButtonSpecialStyle('square_animated');
            $remove_button_mx->setIcon('fa fa-trash-o');
            $remove_button_mx->setButtonColor('danger');
            $remove_button_mx->setImageOnly(TRUE);
            $remove_button_mx->setTarget('_self');

            // Add ButtonContainer with the buttons:
            $buttonContainer_mx = $factory->getButtonContainer("", array($modify_button_mx, $remove_button_mx));
            $buttonContainer_mx->setMargin('pull-right');

            $scrollList->addEntry(array(
                        $domain,
                        $buttonContainer_mx
                        ));
        }

        // generate add mx button:
        $script_siteAdd = "/email/secondarymx";
        $settings = $factory->getAddButton($script_siteAdd, '[[base-email.addmx]]', "DEMO-OVERRIDE");

        $buttonContainer = $factory->getButtonContainer("mx2List", $settings);

        $block->addFormField(
            $buttonContainer,
            $factory->getLabel("mx2List"),
            "mx"
        );

        $xxx = $factory->getRawHTML("mx2List", $scrollList->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("mx2List"),
            "mx"
        );

        //
        //-- Blacklisting
        //

        $oids = $CI->cceClient->findx("dnsbl",array(),array(), 'ascii', 'blacklistHost');
        $oidsNum = count($oids);

        $addmod = '/email/blacklist';

        $blacklist = $factory->getScrollList("blackList", array("blackList", "activated", " "), array()); 
        $blacklist->setAlignments(array("left", "left", "right"));
        $blacklist->setDefaultSortedIndex('0');
        $blacklist->setSortOrder('ascending');
        $blacklist->setSortDisabled(array('2'));
        $blacklist->setPaginateDisabled(FALSE);
        $blacklist->setSearchDisabled(FALSE);
        $blacklist->setSelectorDisabled(FALSE);
        $blacklist->enableAutoWidth(FALSE);
        $blacklist->setInfoDisabled(FALSE);
        $blacklist->setColumnWidths(array("*", "200", "120")); // Max: 739px

        for($i=0; $i < count($oids); $i++) {
            $oid = $oids[$i];
            $hosts = $CI->cceClient->get($oid);
            $host = $hosts['blacklistHost'];
            $active = $hosts['active'];

            // State icon:
            if ($active) {
                $activeStatus = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.enabled_short]]"));
                $activeStatus->MakeTooltip($i18n->getHtml("[[palette.enabled]]"), 'No');
                $activeStatus->setTextOnly(TRUE);
                $activeStatus->setButtonSize('xs');
                $activeStatus->setButtonColor('success');
            }
            else {
                $activeStatus = $factory->getButton('javascript:void(0);', $i18n->getHtml("[[palette.disabled_short]]"));
                $activeStatus->MakeTooltip($i18n->getHtml("[[palette.disabled]]"), 'top');
                $activeStatus->setTextOnly(TRUE);
                $activeStatus->setButtonSize('xs');
                $activeStatus->setButtonColor('danger');
            }

            $modify_button_rbl = $factory->getModifyButton("$addmod?_TARGET=$oid");
            $modify_button_rbl->setButtonSize("small");
            $modify_button_rbl->setButtonSpecialStyle('square_animated');
            $modify_button_rbl->setButtonColor('success');
            $modify_button_rbl->setImageOnly(TRUE);
            $modify_button_rbl->setTarget('_self');

            $remove_button_rbl = $factory->getRemoveButton("$addmod?_RTARGET=$oid");
            $remove_button_rbl->setButtonSize("small");
            $remove_button_rbl->setButtonSpecialStyle('square_animated');
            $remove_button_rbl->setIcon('fa fa-trash-o');
            $remove_button_rbl->setButtonColor('danger');
            $remove_button_rbl->setImageOnly(TRUE);
            $remove_button_rbl->setTarget('_self');

            // Add ButtonContainer with the buttons:
            $buttonContainer_rbl = $factory->getButtonContainer("", array($modify_button_rbl, $remove_button_rbl));
            $buttonContainer_rbl->setMargin('pull-right');

            $blacklist->addEntry(array(
                        $host,
                        $activeStatus,
                        $buttonContainer_rbl
                        ));
        }

        // generate add blacklist button:
        $rblscript_siteAdd = "/email/blacklist";
        $rblsettings = $factory->getAddButton($rblscript_siteAdd, '[[base-email.addmx]]', "DEMO-OVERRIDE");

        $rblbuttonContainer = $factory->getButtonContainer("blackList", $rblsettings);

        $block->addFormField(
            $rblbuttonContainer,
            $factory->getLabel("blackList"),
            "blacklist"
        );

        $xxx = $factory->getRawHTML("blackList", $blacklist->toHtml());
        $block->addFormField(
            $xxx,
            $factory->getLabel("blackList"),
            "blacklist"
        );

        //
        //--- OpenDKIM:
        //

        // OpenDKIM header:
        $xxx = $factory->addBXDivider("OpenDKIM_Header", "");
        $block->addFormField(
                $xxx,
                $factory->getLabel("OpenDKIM_Header", false),
                'OpenDKIM'
                );

        $xxx = $factory->getBoolean("enableOpenDKIM", $email["enableOpenDKIM"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("enableOpenDKIM"),
          "OpenDKIM"
        );

        $OpenDKIM_Mode_Choices = array('v' => 'verify', 's' => 'sign', 'sv' => 'sign_verify');
        $OpenDKIM_Mode_select = $factory->getMultiChoice("OpenDKIM_Mode", array_values($OpenDKIM_Mode_Choices));
        $OpenDKIM_Mode_select->setSelected($OpenDKIM_Mode_Choices[$email['OpenDKIM_Mode']], true);
        $block->addFormField($OpenDKIM_Mode_select, $factory->getLabel("OpenDKIM_Mode"), 'OpenDKIM');

        $xxx = $factory->getBoolean("OpenDKIM_SendReports", $email["OpenDKIM_SendReports"]);
        $block->addFormField(
          $xxx,
          $factory->getLabel("OpenDKIM_SendReports"),
          "OpenDKIM"
        );

        // Add the buttons
        $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
        $block->addButton($factory->getCancelButton("/email/emailsettings"));

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