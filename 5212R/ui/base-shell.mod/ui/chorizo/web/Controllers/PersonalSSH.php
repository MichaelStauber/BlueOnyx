<?php 
namespace Shell\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class personalSSH extends BaseController {

    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    public function data_uri($rawdata, $mime)  {  
        $base64   = base64_encode($rawdata); 
        return ('data:' . $mime . ';base64,' . $base64);
    }
    
    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        // Most basic ACL:
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        // Users without Shell Access don't get to see SSH related options:
        $userHasShell = 0;
        if ($CI->getAllowed('shellAccessEnabled')) {
            $userHasShell = 1;
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $System_SSH = $CI->cceClient->get($System['OID'] , 'SSH');

        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-ssh", "/shell/personalSSH");
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

        // Define who runs CCEwrap:
        $runas = 'root';

        // Group ID:
        if ($user['site'] == "") {
            $group = 'users';
        }
        else {
            $group = $user['site'];
        }

        //
        //--- Handle GET Rrequests (create or download actions):
        //

        $get_form_data = $BxPage->getGETPOST('GET');
        if (isset($get_form_data['action'])) {
            $action = $get_form_data['action'];
        }
        if (isset($get_form_data['type'])) {
            $action_file = $get_form_data['type'];
        }
        if (isset($get_form_data['id'])) {
            $key_id = $get_form_data['id'];
        }

        $loginName = $BX_SESSION['loginName'];

        $allowed_files = array('id_rsa.pub', "$loginName.pem");

        // On a Demo server we don't want to delete anything:
        if (is_file('/etc/DEMO')) {
            unset($action);
        }

        // Some defaults:
        $available_ssh_key_length = array('2048 bit', '4096 bit', '8192 bit');
        $available_ssh_key_length_selector = array(
                '2048 bit' => '2048', 
                '4096 bit' => '4096', 
                '8192 bit' => '8192'
            );

        // Find out if ~$loginName/.ssh/ exists:
        if ($userHasShell === 1) {
            $is_there = '';
            $ret = $CI->serverScriptHelper->shell("/bin/ls --directory ~$loginName/.ssh", $is_there, $runas, $BX_SESSION['sessionId']);
            if (empty($is_there)) {
                # ~$loginName/.ssh does not exists
                $full_path_to_dotsshdir = "~$loginName/.ssh";

                # Create it:
                $ret = $CI->serverScriptHelper->shell("/bin/mkdir ~$loginName/.ssh", $nfk, $runas, $BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/touch ~$loginName/.ssh/authorized_keys", $nfk, $runas, $BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/chmod 00700 -R ~$loginName/.ssh", $nfk, $runas, $BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/chown $loginName:$group -R ~$loginName/.ssh", $nfk, $runas, $BX_SESSION['sessionId']);
                $ret = $CI->serverScriptHelper->shell("/bin/chmod 00644 ~$loginName/.ssh/authorized_keys", $nfk, $runas, $BX_SESSION['sessionId']);
            }
            else {
                $is_there = '';
                if ((preg_match('/^\/home\/\.users\/(.*)$/', $is_there)) || (preg_match('/^\/home\/\.sites\/(.*)$/', $is_there))) {
                    # ~$loginName/.ssh exists
                    $full_path_to_dotsshdir = chop($is_there);
                }
            }
        }

        // Find out if user has the Google Authenticator config and QR image in his homedir:
        $is_there = '';
        $dotgoogle_authenticator = '0';
        $dotgoogle_authenticator_png = '0';
        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/gauth_check.sh $loginName" . "$is_there", $output, $runas, $BX_SESSION['sessionId']);
        if ($ret == 0) {
            // Get Google Authenticator Config:
            $output = '';
            $full_path_to_dotgoogle_authenticator = "~$loginName/.google_authenticator";
            $tempname = tempnam("/var/cache/admserv/", $loginName . "-google_authenticator_");
            $ret = $CI->serverScriptHelper->shell("/bin/cat $full_path_to_dotgoogle_authenticator " . '|/bin/grep -v ^\" >' . "$tempname", $output, $runas, $BX_SESSION['sessionId']);
            if ($ret == 0) {
                $dotgoogle_authenticator_content = file_get_contents($tempname);
                $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $res, $runas, $BX_SESSION['sessionId']);
                $dotgoogle_authenticator = '1';
            }

            // Get Google Authenticator PNG:
            $full_path_to_dotgoogle_authenticator_png = "~$loginName/.google_authenticator.png";
            $dotgoogle_authenticator_png = '1';
            $output = '';
            $tempnamePNG = tempnam("/var/cache/admserv/", $loginName . "-google_authenticator_") . '.png';
            $ret = $CI->serverScriptHelper->shell("/bin/cp $full_path_to_dotgoogle_authenticator_png  $tempnamePNG", $output, $runas, $BX_SESSION['sessionId']);
            if ($ret == 0) {
                    $ret = $CI->serverScriptHelper->shell("/bin/chown admserv:admserv $tempnamePNG", $nfk, $runas, $BX_SESSION['sessionId']);
                    $dotgoogle_authenticator_png_content = file_get_contents($tempnamePNG);
                    $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempnamePNG", $res, $runas, $BX_SESSION['sessionId']);
                    $dotgoogle_authenticator_png = '1';
            }
        }

        if ((isset($action)) && (isset($action_file)) && ($userHasShell === 1)) {

            // We need to be a bit selective as to what filename we allow. Hence
            // the in_array() check here, which weeds out all illegal input
            // and other shenannigans:
            if (($action == 'export') && (in_array($action_file, $allowed_files))) {

                $output = '';
                $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/$action_file", $output, $runas, $BX_SESSION['sessionId']);
                if ($ret != 0) {
                    $errors[] = ErrorMessage($i18n->get('[[palette.404title]]'), 'alert_red', 'alert');
                }
                else {
                    // Force download:
                    $exp_filename = $loginName . '_at_' . $System['hostname'] . '.' . $System['domainname'] . '.' . $action_file;
                    return $this->response->download($exp_filename, $output);
                }
            }

            // Redirect to correct Tab:
            $redirect_URL = '/shell/personalSSH';
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // Handle authorized_key item deletion:
        if ((isset($action)) && (isset($key_id)) && ($userHasShell === 1)) {
            if ($action == 'akremove') {
                $key_id = urldecode($key_id);

                // Create a unique temporary file name:
                $tempname = tempnam("/var/cache/admserv/", $loginName . "_");

                $ret = $CI->serverScriptHelper->shell("/bin/cat $full_path_to_dotsshdir/authorized_keys|/bin/grep -v $key_id", $finder, $runas, $BX_SESSION['sessionId']);
                if ($ret == 0) {
                    write_file($tempname, $finder);
                    $ret = $CI->serverScriptHelper->shell("/bin/cp $tempname ~$loginName/.ssh/authorized_keys", $res, $runas, $BX_SESSION['sessionId']);
                    $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $res, $runas, $BX_SESSION['sessionId']);
                }
                else {
                    // Check if this is the only key in there:
                    $ret = $CI->serverScriptHelper->shell("/bin/cat $full_path_to_dotsshdir/authorized_keys|/usr/bin/wc -l", $finder, $runas, $BX_SESSION['sessionId']);
                    $finder = chop($finder);
                    if (($finder == "") || ($finder == "0") || ($finder == "1")) {
                        // Seems so: Delete it and recreate it:
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $full_path_to_dotsshdir/authorized_keys", $finder, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/touch $full_path_to_dotsshdir/authorized_keys", $nfk, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/chown $loginName:$group -R $full_path_to_dotsshdir", $nfk, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/chmod 00644 $full_path_to_dotsshdir/authorized_keys", $nfk, $runas, $BX_SESSION['sessionId']);
                    }
                    $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $res, $runas, $BX_SESSION['sessionId']);
                }
            }
            // Redirect to correct Tab:
            $redirect_URL = '/shell/personalSSH';
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
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

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            // Receive upload:
            $data = $this->request->getFile('UploadPubkey');

            // Do we have an uploaded file to work with?
            if ((!empty($data->getName())) && ($userHasShell === 1)) {

                // Check if the upload is of type 'application/vnd.ms-publisher':
                $mime_type = $data->getClientMimeType();

                if (($mime_type != 'application/vnd.ms-publisher') && ($mime_type != 'text/plain')) {
                    $redirect_URL = '/shell/shellconfig?advancedSettingsTab#tabs-2';
                    $errors[] = ErrorMessage($i18n->get("[[palette.errorOccuredWhilereceivingFile]]"));
                    $BxPage->ReturnToThisPage($errors, $redirect_URL);
                }

                if ($data->isValid() && ! $data->hasMoved()) {
                    $newName = $data->getRandomName();
                    $tmp_cert = '/tmp/' . $newName;
                    $data->move('/tmp/', $newName);

                    // Check if it is a valid public key:
                    $keylength = '';
                    $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf $tmp_cert", $keylength, $runas, $BX_SESSION['sessionId']);
                    $kl = preg_split('/[\ \n\,]+/', $keylength);

                    if ((in_array('(RSA)', $kl)) || (in_array('(DSA)', $kl))) {

                        // Get current authorized_keys:
                        $authorized_keys = '';
                        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/authorized_keys", $authorized_keys, $runas, $BX_SESSION['sessionId']);

                        // Read uploaded file:
                        $tmp_cert_data = file_get_contents($tmp_cert);

                        // Combine both:
                        $out_data = $authorized_keys . $tmp_cert_data;

                        // This contraption makes sure that there are no blank lines or joint lines
                        // between the two joined files:
                        $out_data_cleaned = implode("\n", array_filter(explode("\n", $out_data)));

                        // Create a unique temporary file name:
                        $tempnameShort = tempnam("/var/cache/admserv/", $loginName . "_");
                        $tempname =  $tempnameShort . ".tmp";

                        // Write the new joint authorized_keys as temporary file:
                        write_file($tempname, $out_data_cleaned);

                        // Move it to the right location and delete the temporary files:
                        $ret = $CI->serverScriptHelper->shell("/bin/cp $tempname ~$loginName/.ssh/authorized_keys", $output, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/chmod 00644 ~$loginName/.ssh/authorized_keys", $output, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $output, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempnameShort", $output, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tmp_cert", $output, $runas, $BX_SESSION['sessionId']);

                        // Redirect to correct Tab:
                        $redirect_URL = "/shell/personalSSH";
                        $BxPage->ReturnToThisPage($errors, $redirect_URL);
                    }
                    else {
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tmp_cert", $output, $runas, $BX_SESSION['sessionId']);
                        $errors[] = ErrorMessage($i18n->get("[[base-ssl.sslImportError4]]"));
                    }
                }
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.

            // Transform GUI friendly setting to CODB friendly format:
            $bits = $available_ssh_key_length_selector[$attributes['SSH_keylength']];
            $SSHCODB = array("bits" => $bits);

            // Set trigger for key-create:
            if (isset($attributes['key_present'])) {
                if ($attributes['key_present'] == "1") {
                    $SSHCODB['keycreate'] = time();
                }
            }

            // Set trigger for cert-create:
            if (isset($attributes['cert_present'])) {
                if ($attributes['cert_present'] == "1") {
                    $SSHCODB['certcreate'] = time();
                }
            }

            $SSHCODB['GoogleAuthentication'] = ($dotgoogle_authenticator == '1') ? "1" : "0";

            // Actual submit to CODB:
            $CI->cceClient->set($user['OID'], 'SSH', $SSHCODB);

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            if (count($errors) == "0") {
                // Redirect to correct Tab:
                $redirect_URL = "/shell/personalSSH";
                $BxPage->ReturnToThisPage($errors, $redirect_URL);
            }
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/shell/personalSSH");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_personalProfile';

        $certKeyPage = "advancedSettingsTab";

        $block = $factory->getPagedBlock("shell", array($certKeyPage));
        $block->setLabel($factory->getLabel('[[base-shell.shell]]', false));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");
        $block->setDefaultPage($certKeyPage);

        //
        //-- SSH Key Management:
        //

        // Start sane:
        $ret = $CI->serverScriptHelper->shell("/bin/rm -f /var/cache/admserv/*.tmp", $junk, $runas, $BX_SESSION['sessionId']);
        $ret = $CI->serverScriptHelper->shell("/bin/rm -f /var/cache/admserv/*.pub", $junk, $runas, $BX_SESSION['sessionId']);

        # authorized_keys:
        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/authorized_keys", $authorized_keys, $runas, $BX_SESSION['sessionId']);

        if (($ret != 0) || (empty($authorized_keys))) {
            # File not present.
        }
        else {
            # Turn authorized_keys in an array of arrays:
            $authorized_keys_array = array_filter(explode("\n", $authorized_keys));
            $authorized_keys = array();
            foreach ($authorized_keys_array as $key => $value) {
                $split_lines = preg_split('/[\ \n\,]+/', $value);

                // Detect key length:
                $kl = array();
                $keylength = "";
                // Make sure the line in authorized_keys contains valid data:
                if ((isset($split_lines[0])) && (isset($split_lines[1])) && (isset($split_lines[2]))) {
                    // Make sure it contains an RSA or at the worst a DSA key:
                    if (($split_lines[0] == "ssh-rsa") || ($split_lines[0] == "ssh-dsa")) {

                        // Create a unique temporary file name:
                        $tempnameShort = tempnam("/var/cache/admserv/", $loginName . "_");
                        $tempname =  $tempnameShort . ".tmp";

                        // Continue: Write it to a temporary file and parse it:
                        write_file($tempname, $split_lines[0] . " " . $split_lines[1] . " " . $split_lines[2]);
                        $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf $tempname", $keylength, $runas, $BX_SESSION['sessionId']);
                        $kl = preg_split('/[\ \n\,]+/', $keylength);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $junk, $runas, $BX_SESSION['sessionId']);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempnameShort", $junk, $runas, $BX_SESSION['sessionId']);

                        if (is_file('/etc/DEMO')) {
                            // On a Demo server we don't even want to show the partial payload:
                            $split_lines[1] = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
                        }

                        $authorized_keys[$key] = array(
                                                    'key_userhost' => $split_lines[2], 
                                                    'key_payload' => $split_lines[1], 
                                                    'key_type' => $split_lines[0],
                                                    'key_lenght' => $kl[0]
                                                    );
                    }
                }
            }
        }

        # id_rsa:
        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/id_rsa", $id_rsa, $runas, $BX_SESSION['sessionId']);
        if ($ret != 0) {
            # File not present.
            unset($id_rsa);
        }
        else {
            // Detect private key length:
            $id_rsa_length;
            $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf ~$loginName/.ssh/id_rsa|/usr/bin/awk '{ print \$1 }'", $id_rsa_length, $runas, $BX_SESSION['sessionId']);
            if (!empty($id_rsa_length)) {
                $id_rsa_length = chop($id_rsa_length);
            }
            $id_rsa_present = '1';
        }

        # id_rsa.pub:
        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/id_rsa.pub", $id_rsa_pub, $runas, $BX_SESSION['sessionId']);
        if ($ret != 0) {
            # File not present.
        }
        else {
            # Turn id_rsa.pub in an array of arrays:
            $id_rsa_pub_array = array_filter(explode("\n", $id_rsa_pub));
            $id_rsa_pub = array();
            foreach ($id_rsa_pub_array as $key => $value) {
                $split_lines = preg_split('/[\ \n\,]+/', $value);

                // Detect key length:
                $kl = array();
                $keylength = "";

                // Create a unique temporary file name:
                $tempnameShort = tempnam("/var/cache/admserv/", $loginName . "_");
                $tempname =  $tempnameShort . ".tmp";

                // Make sure the line in authorized_keys contains valid data:
                if ((isset($split_lines[0])) && (isset($split_lines[1])) && (isset($split_lines[2]))) {
                    // Make sure it contains an RSA or at the worst a DSA key:
                    if (($split_lines[0] == "ssh-rsa") || ($split_lines[0] == "ssh-dsa")) {

                        write_file($tempname, $split_lines[0] . " " . $split_lines[1] . " " . $split_lines[2]);
                        $keylength = '';
                        $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf $tempname", $keylength, $runas, $BX_SESSION['sessionId']);
                        $kl = preg_split('/[\ \n\,]+/', $keylength);
                        $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $junk, $runas, $BX_SESSION['sessionId']);

                        $id_rsa_pub = array(
                                                    'key_userhost' => $split_lines[2], 
                                                    'key_payload' => $split_lines[1], 
                                                    'key_type' => $split_lines[0],
                                                    'key_lenght' => $kl[0]
                                                    );
                        $id_rsa_pub_present = '1';
                    }
                }
                else {
                    $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempname", $junk, $runas, $BX_SESSION['sessionId']);
                }
                $ret = $CI->serverScriptHelper->shell("/bin/rm -f $tempnameShort", $junk, $runas, $BX_SESSION['sessionId']);
            }
        }

        //
        //-- SSH Cert Management:
        //

        # $loginName.pem:
        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/$loginName.pem", $root_pem, $runas, $BX_SESSION['sessionId']);
        if ($ret != 0) {
            # File not present.
            unset($root_pem);
            $root_pem_present = '0';
        }
        else {
            // Detect private key length:
            $root_pem_length = '';
            $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf ~$loginName/.ssh/$loginName.pem|/usr/bin/awk '{ print \$1 }'", $root_pem_length, $runas, $BX_SESSION['sessionId']);
            if (!empty($root_pem_length)) {
                $root_pem_length = chop($root_pem_length);
            }
            $root_pem_present = '1';
        }

        # $loginName.pem.pub:
        $ret = $CI->serverScriptHelper->shell("/bin/cat ~$loginName/.ssh/$loginName.pem.pub", $root_pem_pub, $runas, $BX_SESSION['sessionId']);
        if ($ret != 0) {
            $root_pem_pub_present = '0';
        }
        else {
            # Turn id_rsa.pub in an array of arrays:
            $root_pem_pub_array = array_filter(explode("\n", $root_pem_pub));
            $root_pem_pub = array();
            foreach ($root_pem_pub_array as $key => $value) {
                $split_lines = preg_split('/[\ \n\,]+/', $value);

                // Detect key length:
                $kl = array();
                $keylength = "";
                $ret = $CI->serverScriptHelper->shell("/usr/bin/ssh-keygen -lf ~$loginName/.ssh/$loginName.pem.pub", $keylength, $runas, $BX_SESSION['sessionId']);
                $kl = preg_split('/[\ \n\,]+/', $keylength);

                $root_pem_pub = array(
                                            'key_userhost' => $split_lines[2], 
                                            'key_payload' => $split_lines[1], 
                                            'key_type' => $split_lines[0],
                                            'key_lenght' => $kl[0]
                                            );
                $root_pem_pub_present = '1';
            }
        }

        //---

        $SSHsettings = $CI->cceClient->get($user['OID'], 'SSH');

        //
        //-- Sense check:
        //

        if (($System_SSH['GoogleAuthentication']) && ($dotgoogle_authenticator == '1') && ($SSHsettings['GoogleAuthentication'] == '0')) {
            // Server has 'GoogleAuthentication' enabled. 
            // User has dotgoogle_authenticator present
            // User CODB data says he hasn't. Fix it:
            $CI->cceClient->set($user['OID'], 'SSH', array('GoogleAuthentication' => '1'));
            $SSHsettings['GoogleAuthentication'] = '1';
        }
        if (($System_SSH['GoogleAuthentication'] == '0') || (($dotgoogle_authenticator == '0') && ($SSHsettings['GoogleAuthentication'] == '1'))) {
            // Server has 'GoogleAuthentication' disabled.
            // OR
            // User has dotgoogle_authenticator absent
            // User CODB data says he has it active. Fix it:
            $CI->cceClient->set($user['OID'], 'SSH', array('GoogleAuthentication' => '0'));
            $SSHsettings['GoogleAuthentication'] = '0';
        }

        if ($userHasShell === 1) {

            // Show selector for SSH key length:
            $available_ssh_key_length_selector = array_flip($available_ssh_key_length_selector);
            $bits = $available_ssh_key_length_selector[$SSHsettings['bits']];
            $SSHkeyLength = $factory->getMultiChoice("SSH_keylength", array_values($available_ssh_key_length));
            $SSHkeyLength->setSelected($bits, true);
            $SSHkeyLength->setOptional(false);
            $block->addFormField(
                    $SSHkeyLength, 
                    $factory->getLabel("SSH_keylength"), 
                    $certKeyPage
                );

            // Do we have a public and private key?
            if ((isset($id_rsa_length)) && (isset($id_rsa_present)) && (isset($id_rsa_pub_present)))  {

                // If we're currently using a key length that is not yet
                // listed, then we add it to the array:
                if (!in_array($id_rsa_length, $available_ssh_key_length)) {
                    $available_ssh_key_length[] = $id_rsa_length;
                    sort($available_ssh_key_length);
                }

                $nokey_info = $i18n->getClean("[[base-ssh.keys_present_msg]]", false, array("bits" => $id_rsa_length));
                $xxx = $factory->getHtmlField("key_present", $nokey_info , 'r');
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("key_present"),
                    $certKeyPage
                );
                $gotKey = '1';
            }
            else {
                // Create Key:
                $xxx = $factory->getBoolean("key_present", '0' , 'rw');
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("key_present"),
                    $certKeyPage
                );
            }

            // Do we have a public and private certificate?
            if (($root_pem_present == '1') && ($root_pem_pub_present == '1') && (isset($root_pem_pub['key_lenght'])))  {

                $Cert_info = $i18n->getClean("[[base-ssh.certs_present_msg]]", false, array("bits" => $root_pem_pub['key_lenght']));
                $xxx = $factory->getHtmlField("cert_present", $Cert_info , 'r');
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("cert_present"),
                    $certKeyPage
                );
                $gotCert = '1';
            }
            else {
                // Create Cert:
                $xxx = $factory->getBoolean("cert_present", '0' , 'rw');
                $block->addFormField(
                    $xxx,
                    $factory->getLabel("cert_present"),
                    $certKeyPage
                );
            }
        }
        else {
            // User has no shell access:
            $no_shell_Field = $factory->getRawHTML("no_shell_Field", '<p>' . $i18n->get('[[base-shell.userEnableShell_help]]') . '</p>' , 'r');
            $block->addFormField(
                $no_shell_Field,
                $factory->getLabel("no_shell_Field"),
                $certKeyPage
            );
        }

        //
        //-- Generate authorized_keys scrollList:
        //

        if ($userHasShell === 1) {

            $scrollList = $factory->getScrollList("AuthKeyList", array("key_type", "key_payload", "key_userhost", "bits", "listAction"), array()); 
            $scrollList->setAlignments(array("left", "left", "left", "center", "center"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('4'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            $scrollList->setColumnWidths(array("100", "400", "170", "34", "35")); // Max: 739px

            // Populate Scrollist:
            if (is_array($authorized_keys)) {
                foreach ($authorized_keys as $key => $kdata) {

                    $deleteButton = $factory->getModifyButton('/shell/personalSSH?action=akremove&id=' . urlencode($kdata['key_userhost']));
                    $deleteButton->setButtonSize("small");
                    $deleteButton->setButtonSpecialStyle('square_animated');
                    $deleteButton->setIcon('fa fa-trash-o');
                    $deleteButton->setButtonColor('danger');
                    $deleteButton->setImageOnly(TRUE);
                    $deleteButton->setTarget('_self');
                    $deleteButton->setDescription($i18n->getHtml("AKRemove"));
                    $deleteButton->addButtonClass('dialog_button');
                    $deleteButton->setModal('dialog', '/shell/personalSSH?action=akremove&id=' . urlencode($kdata['key_userhost']));

                    $scrollList->addEntry(array(
                                $kdata['key_type'],
                                substr($kdata['key_payload'], 0, 15). " ... " . substr($kdata['key_payload'], -15),
                                $kdata['key_userhost'],
                                $kdata['key_lenght'],
                                $deleteButton->toHtml()
                                ));
                }
            }

            // Add divider:
            $didi = $i18n->getHtml("[[base-ssh.AuthKeyList]]", false, array("authkey_file" => "~$loginName/.ssh/authorized_keys"));
            $xxx = $factory->addBXDivider('AuthKeyList', "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel('AuthKeyList', false, array('authkey_file' => "~$loginName/.ssh/authorized_keys")),
                    $certKeyPage
                    );

            // Push out the Scrollist:
            $xxx = $factory->getRawHTML("AuthKeyList", $scrollList->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("AuthKeyList"),
                $certKeyPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider('UploadPubKeyHead', "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel('UploadPubKeyHead', false, array('authkey_file' => "~$loginName/.ssh/authorized_keys")),
                    $certKeyPage
                    );

            $xxx = $factory->getFileUpload('UploadPubkey', "");
            $block->addFormField(
                $xxx,
                $factory->getLabel('UploadPubkey'),
                $certKeyPage
                );

            // Create Buttons for downloading (if we have something to download!)
            $export_buttons = array();
            if (is_array($id_rsa_pub)) {
                $pubkey_dl_Button = $factory->getButton('/shell/personalSSH?action=export&type=id_rsa.pub', 'export_id_rsa_pub');
                $pubkey_dl_Button->setIcon('fa fa-download');
                $export_buttons[] = $pubkey_dl_Button;
            }
            if ($root_pem_present == "1") {
                $pem_dl_Button = $factory->getButton("/shell/personalSSH?action=export&type=$loginName.pem", 'export_root_pem');
                $pem_dl_Button->setIcon('fa fa-download');
                $export_buttons[] = $pem_dl_Button;
            }

            // Add Button-Container with download buttons:
            if (count($export_buttons) > '0') {

                // Add divider:
                $xxx = $factory->addBXDivider('keyDownloadHeader', "");
                $block->addFormField(
                        $xxx,
                        $factory->getLabel('keyDownloadHeader', false),
                        $certKeyPage
                        );

                $buttonContainer_a = $factory->getButtonContainer("", $export_buttons);

                // Push out the Button-Container:
                $xxx = $factory->getRawHTML("", $buttonContainer_a->toHtml());
                $block->addFormField(
                    $xxx,
                    $factory->getLabel(""),
                    $certKeyPage
                );
            }

            // Add the buttons
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/shell/personalSSH"));

            if ($BX_SESSION['gui_theme'] === 'adminica') {
                $BxPage->setExtraHeaders('
                    <script type="text/javascript">
                    $(document).ready(function () {
                        // Initialize the dialog with the "Remove" and "Cancel" buttons
                        $("#modalDeleteButton").dialog({
                            modal: true,
                            bgiframe: true,
                            width: 500,
                            height: 280,
                            autoOpen: false,
                            buttons: {
                                "' . $i18n->getHtml("[[palette.remove]]") . '": function() {
                                    // Action for the "Remove" button goes here
                                    // At this point, we don\'t have the URL yet, it will be set later
                                },
                                "' . $i18n->getHtml("[[palette.cancel]]") . '": function() {
                                    $(this).dialog("close");
                                }
                            }
                        });

                        // Attach click event to your delete button
                        $(".dialog_button").click(function (e) {
                            e.preventDefault();
                            
                            // Get the URL from the data-link attribute of the clicked button
                            var deleteUrl = $(this).data("link");

                            // Update the "Remove" button\'s click action dynamically to use the deleteUrl
                            var buttons = $("#modalDeleteButton").dialog("option", "buttons"); // Get the current buttons
                            buttons["' . $i18n->getHtml("[[palette.remove]]") . '"] = function() { // Modify the "Remove" button action
                                window.location.href = deleteUrl; // Redirect to the URL
                                $(this).dialog("close"); // Optionally close the dialog
                            };
                            $("#modalDeleteButton").dialog("option", "buttons", buttons); // Set the updated buttons back

                            // Now open the dialog
                            $("#modalDeleteButton").dialog("open");
                        });
                    });
                    </script>');

                // Add hidden Modal for Delete-Confirmation:
                $page_body[] = '
                    <div class="display_none">
                                <div id="modalDeleteButton" class="dialog_content narrow no_dialog_titlebar" title="' . $i18n->getHtml("[[base-ssh.AKRemoveConfirmNeutral]]") . '">
                                    <div class="block">
                                            <div class="section">
                                                    <h1>' . $i18n->getHtml("[[base-ssh.AKRemoveConfirmNeutral]]") . '</h1>
                                                    <div class="dashed_line"></div>
                                                    <p>' . $i18n->getHtml("[[base-ssh.removeConfirmInfo]]") . '</p>
                                            </div>
                                    </div>
                                </div>
                    </div>';

            }
            else {

                // Add hidden Modal for Delete-Confirmation for Elmer:
                $modal_title = $i18n->getHtml("[[base-ssh.AKRemoveConfirmNeutral]]");
                $modal_body = $i18n->getHtml("[[base-ssh.AKRemoveConfirmNeutral]]");
                $modal_remove = $i18n->getHtml("[[palette.remove]]");
                $modal_cancel = $i18n->getHtml("[[palette.cancel]]");
                $modal_html =<<<HTML

                            <!-- Delete-Confirm modal -->
                            <div id="dialog" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="dialogLabel" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                                            <h5 class="modal-title" id="dialogLabel">$modal_title</h5>
                                        </div>
                                        <div class="modal-body">
                                            <p>$modal_body</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button class="btn btn-danger btn-anim link_button" id="modalDeleteButton"><i class="fa fa-trash-o"></i><span class="btn-text">$modal_remove</span></button>
                                            <button class="btn btn-primary btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text">$modal_cancel</span></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- /Delete-Confirm modal -->

                HTML;
                $page_body[] = $modal_html;

                // Set extra-footers for do you really want to delete" dialog for Elmer:
                $BxPage->setExtraFooters('
                    <script>
                        // Activate the tooltip
                        $(\'[data-toggle="tooltip"]\').tooltip();

                        // Add a click event to open the modal
                        $(\'.dialog_button\').click(function () {
                            var url = $(this).data(\'url\');
                            $(\'#modalDeleteButton\').data(\'url\', url);
                            $(\'#dialog\').modal(\'show\');
                        });

                        // Add a click event to the modal\'s deletion button
                        $(\'#modalDeleteButton\').click(function () {
                            var url = $(this).data(\'url\');
                            // Perform your deletion action or redirect to the specified URL
                            window.location.href = url; // Example: Redirect to the URL
                        });
                    </script>
                ');
            }
        }

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }       
}
/*
Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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
