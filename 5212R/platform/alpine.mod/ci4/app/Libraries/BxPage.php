<?php

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use CodeIgniter\Cookie\Cookie;

/**
 * BxPage($page_module, $page_body)
 *
 * A Class that renders the entire GUI page past the login procedure.
 *
 * This is the main course. Pretty much every sensible page of the new GUI
 * uses this class to render GUI pages. The exception only being supporting
 * pages for dynamically generated 'fluff' loaded in the HTML headers of 
 * BxPage generated pages.
 *
 * BxPage is a complete rewrite from scratch, but takes over functions that
 * the original Cobalt Networks Page.php carried out. Namely the output of 
 * the body of a page. But as we no longer have a frameset, our new BxPage 
 * also needs to render the menu, needs to open menu entries to expose 
 * sub-menu entries (and mark one as active!) if we're on a page that is 
 * located in a sub-menu. Furthermore it needs to check and show Active
 * Monitor status.
 *
 * Another complication is the localization. BxPage determines the locale of
 * the user and based on it does the localization into the desired language
 * and sets the correct page headers for the page to render correctly.
 *
 * One complication arose out of the Label Objects for FormFields. In the old
 * Cobalt Networks code Labels and FormFields are separate Object entities.
 * We had to keep it that way, but as we no longer can rely on Register Globals
 * to act as 'scratchpad' this temporary data had to be stored somewhere else.
 * Otherwise we would loose the association between Labels and FormFields.
 * Therefore BxPage has been modified to act as temporary storage for these 
 * object associations. Whenever an UIFC Class looses track of which Label it
 * is supposed to use, it can ask BxPage for the Label (and Description) 
 * associated with the ID of the FormField. If that turns up blank, UIFC 
 * Classes will - as last resort - use their ID as a Label instead.
 *
 * BxPage can also be called in a way that supresses the output of the 
 * supporting menu structure. If called with setOutOfStyle(TRUE) BxPage
 * will not show the supporting menu-framework. Instead the page payload will
 * be embedded in the respective HTML framework without any eye-candy.
 *
 * Lastly BxPage also does the error handling. Any page that uses BxPage will
 * pass it's errors to BxPage for visualization. BxPage then decides if the 
 * error is either shown inline in GUI elements that have their own area for 
 * displaying such errors. The commonly used pagedBlock() has such an area, 
 * for example. If no pagedBlock (or other Class with error display area) is
 * used, then BxPage will display the error message on top of the page output.
 *
 * Last but not least there are ACLs. Users can have finely tuned Capabilities.
 * Which define which menu entries, submenu entries and pages they are allowed
 * to see. On an indivdual page level this is checked via calls to the
 * Capability Class. But BxPage renders the menus, so our entire mechanism for
 * rendering menus inside BxPage needs to take the ACL's into account as well.
 * That is done via the functions generateSiteMap(), MenuChildren() and 
 * getURLofFirstChild(), which partially use other functions as well.
 *
 * So BxPage is pretty much our new Swiss Army knife and therefore the heart and
 * soul of the new 'Chorizo GUI'. Which is a Colombian sausage and the name was
 * chosen to keep in theme with the original engine name of 'Sausalito'.
 *
 * @param VAR   $page_module    : module that the page belongs to
 * @param ARR   $page_body      : An array containing HTML output
 */

// To do list:
//
// Create mechanism that hides "Add Vsite" menu entry if user has exceeded creation limits

class BxPage extends Controller {

    public $page_body;
    public $locale;
    public $charset;
    public $vertical_menu_override;
    public $horizontal_menu_override;
    public $ActiveMenuItem;
    public $extra_debug;
    private $extra_headers;
    private $extra_footers;
    private $delete_dialog;
    public $form;
    public $i18n;
    public $stylist;
    public $onLoad;
    public $BXLabel;
    public $BXErrors = array();
    public $ff_extra_headers;
    public $BXErrorDisplayArea;
    public $Overlay;
    public $PrimaryColor;
    public $ID;
    public $Label;
    public $body_open_tag;
    public $CSRF_ignore;
    public $vertical_menu_child_override;
    public $cceClient;

    public $FORM_GET;
    public $FORM_POST;
    public $AGENT;
    public $GUI_THEME;

    public $PAGEDBLOCKS;

    // description: constructor
    // param: stylist: a Stylist object that defines the style
    // param: i18n: an I18n object for internationalization
    // param: formAction: the action of the Form object this Page has. Optional

    public function __construct(&$stylist = array(), &$i18n = array(), $formAction = "") {

        $this->setStylist($stylist);
        $this->setI18n($i18n);

        $CI = get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $this->setGuiTheme('elmer');
        include_once("uifc2/Form.php");
        $this->form = new Form($this, $formAction);
        $this->onLoad = false;
        $this->body_open_tag = "<body>";

        // Do we ignore CSRF for this GUI-Page?
        $this->CSRF_ignore = FALSE;

        // <---- START: API exception by IP:
        // Start sane:
        $API_URLS = array();
        
        // Check JSON encoded config file with IPs that are allowed to use the API:
        $API_CFG_FILE = '/usr/sausalito/configs/api/ips.json';
        if (is_file($API_CFG_FILE)) {
            $CFG_read = fopen($API_CFG_FILE, "r");
            $json_API_CFG_IPS = fgets($CFG_read);
            fclose($CFG_read);
            $API_URLS = json_decode($json_API_CFG_IPS, true);
        }
        
        // Fast bypass of CSRF if $_SERVER['REMOTE_ADDR'] is a whitelisted IP:
        if (isset($_SERVER['REMOTE_ADDR'])) {
            if (in_array($_SERVER['REMOTE_ADDR'], $API_URLS)) {
                $this->CSRF_ignore = TRUE;
            }
        }
        
        // Check $_SERVER['HTTP_REFERER'], extract the domain name and do an IP lookup
        // to get the IP address of the refering host.
        $url_ref = '';
        if (isset($_SERVER['HTTP_REFERER'])) {
            $url_ref = $_SERVER['HTTP_REFERER'];
            $url_ref = preg_replace('/^https:\/\//', '', $url_ref);
            $url_ref = preg_replace('/^http:\/\//', '', $url_ref);
            $url_ref = preg_replace('/\/(.*)$/', '', $url_ref);
            $req_ip = gethostbyname($url_ref);
        
            // Check if determined IP of referer is whitelisted. If so, bypass CSRF:
            if (isset($req_ip)) {
                if (in_array($req_ip, $API_URLS)) {
                    $this->CSRF_ignore = TRUE;
                }
            }
        }
        // <---- END: API exception by IP

        // Set default Wait Overlay:
        $this->Overlay = '';

        $this->PAGEDBLOCKS = array();
    }

    // Keep track of how many getPagedBlock() elements we have:
    public function setPagedBlock($var) {
        $this->PAGEDBLOCKS[] = $var;
    }

    // Return the info about how many getPagedBlock() elements we have:
    public function getPagedBlock() {
        return $this->PAGEDBLOCKS;
    }


    // Set which GUI Theme is being used:
    public function setGuiTheme($theme = 'elmer') {
        $this->GUI_THEME = 'elmer';
    }

    // Return the info which GUI Theme is being used:
    public function getGuiTheme() {
        if (isset($this->GUI_THEME)) {
            return $this->GUI_THEME;
        }
        else {
            return 'elmer';
        }
    }

    private function isTwoFactorSetupRestrictionActive() {
        $CI = get_instance();
        return (method_exists($CI, 'isTwoFactorSetupRestrictionActive') && $CI->isTwoFactorSetupRestrictionActive());
    }

    private function renderTwoFactorSetupRestrictedMenu($i18n) {
        $label = $i18n->getHtml('[[base-user.personalTwoFactor_menu]]');
        $description = $i18n->getHtml('[[base-user.personalTwoFactor_help]]');

        return '<li class="active"><a href="/user/personalTwoFactor" class="tooltip hover" title="'
            . $description
            . '">'
            . $label
            . '</a></li>' . "\n";
    }

    // Function to set form URL to a new destination after FORM Object has already been instanciated:
    public function setFormUrl($url='') {
        $this->form->setAction($url);
    }

    public function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }

        // First sanitize
        $sanitized = filter_var($input, FILTER_UNSAFE_RAW);
        $sanitized = strip_tags($sanitized);

        // Then escape everything EXCEPT ampersands:
        return str_replace(
            ['<', '>', '"', "'"],
            ['&lt;', '&gt;', '&quot;', '&#039;'],
            $sanitized
        );
        return $input;
    }

    // Function to pass GET and POST requests and our User-Agent from Controllers to BxPage:
    public function setGETPOST($val) {
        if (isset($val['FORM_GET'])) {
            $this->FORM_GET = $this->sanitizeInput($val['FORM_GET']);
        }

        // Special sanitation of 'group' param:
        if (isset($val['FORM_GET']['group'])) {
            // Only allow alphanumeric characters for 'group'
            if (isset($val['FORM_GET']['group']) && preg_match('/^[a-zA-Z0-9]+$/', $val['FORM_GET']['group'])) {
                // 'group' contains only alphanumeric characters
                $this->FORM_GET['group'] = $val['FORM_GET']['group'];
            }
            else {
                // Handle invalid input (e.g., reject or sanitize)
                $CI =& get_instance();
                $BX_SESSION = $CI->getBX_SESSION();

                if ($BX_SESSION['loginUser']['systemAdministrator'] === '1') {
                    // We are 'systemAdministrator' and have an invalid group. Assume 'server':
                    $this->FORM_GET['group'] = 'server';
                }
                else {
                    if ((isset($BX_SESSION['loginUser']['site'])) && (!empty($BX_SESSION['loginUser']['site']))) {
                        // User belongs to a Vsite AND has invalid group URL-param. Reset to the Group that he belongs to:
                        $this->FORM_GET['group'] = $BX_SESSION['loginUser']['site'];
                    }
                    else {
                        // User does not belong to a Vsite AND has invalid group URL-param. Strip invalid chars:
                        $this->FORM_GET['group'] = preg_replace('/[^a-zA-Z0-9]/', '', $val['FORM_GET']['group']);
                    }
                }
            }
        }

        if (isset($val['FORM_POST'])) {
            $this->FORM_POST = $this->sanitizeInput($val['FORM_POST']);
        }
        if (isset($val['AGENT'])) {
            $this->AGENT = $val['AGENT'];
        }
    }

    public function getGETPOST($what = '') {
        if ($what == 'GET') {
            return $this->FORM_GET;
        }
        elseif ($what == 'POST') {
            return $this->FORM_POST;
        }
        elseif ($what == 'AGENT') {
            return $this->AGENT;
        }
        else {
            return [
                'FORM_GET' => $this->FORM_GET,
                'FORM_POST' => $this->FORM_POST,
                'AGENT' => $this->AGENT
            ];
        }
    }

    // Allow certain GUI pages to set the flag 'CSRF_ignore' via $BxPage->setCSRF_ignore(TRUE);
    // That ignore works only for GET requests, though. POST requests *must* be configured via URI
    // exclusions as well.
    public function setCSRF_ignore($val) {
        if (($val == TRUE) || ($val == FALSE)) {
            $this->CSRF_ignore = $val;
        }
    }

    // Set an Array containing extra header information for a particular page:
    public function setExtraHeaders($val) {
        $this->extra_headers[] = $val;
        $this->extra_headers = array_unique($this->extra_headers);
    }

    public function setExtraFooters($val) {
        $this->extra_footers[] = $val;
        $this->extra_footers = array_unique($this->extra_footers);
    }

    // Sometimes the standard <body> opening tag won't do and we might want to add stuff to it:
    public function setExtraBodyTag($val) {
        $this->body_open_tag = $val;
    }

    // Set an Array containing extra information for the delete dialog in the page footer:
    public function setDeleteDialog($key, $val) {
        $this->delete_dialog[$key] = $val;
    }

    // Set an Array containing Label information of FormFields:
    public function setLabel($id, $label, $description) {
        $this->BXLabel[$id] = array($label => $description);
    }

    // Return the Array containing Label information of FormFields:
    public function getLabel($id) {
        if (isset($this->BXLabel[$id])) {           
            return $this->BXLabel[$id];
        }
        else {
            return NULL;
        }
    }

    // Override for setting the active page in the horizontal Menu. This is needed when we view 
    // a page that's not defined in the menu.schema of the respective menu. Or a page which has
    // an URL that ends with [[VAR....]].
    public function setHorizontalMenu($val) {
        $this->horizontal_menu_override = $val;
    }

    // Override for setting the active page in the vertical Menu. This is needed when we view 
    // a page that's not defined in the menu.schema of the respective menu.
    public function setVerticalMenu($val) {
        $this->vertical_menu_override = $val;
    }

    // Override for setting the active child page in the vertical Menu. This is needed when we view 
    // a page that's not defined in the menu.schema of the respective menu. Like /email/secondarymx,
    // which is a subpage reachable only through a button in a tab of /email/emailsettings#tabs-3
    public function setVerticalMenuChild($val) {
        $this->vertical_menu_child_override = $val;
    }

    // There are cases when we want to display page content without our usual theme, but still
    // need some of the logic that BxPage provides. Using setOutOfStyle() allows us to do so.
    public function setOutOfStyle($val) {
        $this->style_override = $val;
    }

    // Pass om extra debug information:
    public function setDebug($val) {
        $this->extra_debug = $val;
    }

    public function &getDefaultStyle(&$stylist) {
        return $stylist->getStyle("Page");
    }

    // description: get the form embedded in the page
    // returns: a Form object
    public function getForm() {
        return $this->form;
    }

    // description: get the I18n object used to internationalize this page
    // returns: an I18n object
    // see: setI18n()
    public function getI18n() {
        return $this->i18n;
    }

    // description: set the I18n object used to internationalize this page
    // param: i18n: an I18n object
    // see: getI18n()
    public function setI18n(&$i18n) {
        $this->i18n = $i18n;
    }

    // description: set Javascript to be performed when the page loads
    // param: js: a string of Javascript code
    public function setOnLoad($js) {
        $this->onLoad = $js;
        $this->body_open_tag = '<BODY onLoad=\"$this->onLoad\">';
    }

    // description: get the stylist that stylize the page
    // returns: a Stylist object
    // see: setStylist()
    public function getStylist() {
        return $this->stylist;
    }

    // description: set the stylist that stylize the page
    // param: stylist: a Stylist object
    // see: getStylist()
    public function setStylist(&$stylist) {
        $this->stylist = $stylist;
    }

    // description: get the submit action that submits the form in this page
    // returns: a string
    public function getSubmitAction() {
        $form = $this->getForm();
        return $form->getSubmitAction();
    }

    // description: get the target of the embedded form to submit to
    // returns: a string
    // see: setSubmitTarget()
    public function getSubmitTarget() {
        $form = $this->getForm();
        return $form->getTarget();
    }

    // description: set the target of the embedded form to submit to
    // returns: a string
    // see: getSubmitTarget()
    public function setSubmitTarget($target) {
        $this->form->setTarget($target);
    }

    // description: Set error message and return URL after a POST transaction:
    // returns: Ends cceClient() and serverScriptHelper() and loads $returnUrl
    public function ReturnToThisPage($errors, $returnUrl = "") {

        $CI = get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        // Set $errors:
        if (empty($errors)) {
            $errors = array();
        }
        $this->setErrors($errors);

        // Get connection details:
        $this->cceClient = $CI->getCCE();
        $serverScriptHelper = $CI->getSSH();

        // End connections:
        $this->cceClient->bye();
        $serverScriptHelper->destructor();

        // Fallback for $returnURL to current URL if empty:
        if (empty($returnUrl)) {
            $returnUrl = $_SERVER['REQUEST_URI'];
        }

        // Redirect and exit:
        header("Location: $returnUrl");
        exit;
    }

    // Function to strip HTML tags from a string
    public function stripHtmlTags($htmlString) {
        return strip_tags($htmlString);
    }

    // Set an Array containing the errors:
    public function setErrors($errors) {
        $cleaned_errors = [];
        $Theme = $this->getGuiTheme();

        # Start: Remove empty and double entries:
        $keysToRemove = [];
        $seenValues = [];

        foreach ($errors as $key => $value) {
            // Check if the value is an array and if it is empty
            if (is_array($value) && empty($value)) {
                $keysToRemove[] = $key;
            }

            // Check if the value is a string (for identical strings)
            elseif (is_string($value)) {
                if (in_array($value, $seenValues)) {
                    $keysToRemove[] = $key;
                } else {
                    $seenValues[] = $value;
                }
            }
        }

        // Remove the collected keys
        foreach ($keysToRemove as $key) {
            unset($errors[$key]);
        }
        # End: Remove empty and double entries:

        if ($Theme === 'elmer') {
            if (is_array($errors)) {
                foreach ($errors as $key => $that_error) {
                    $cleaned_errors[] = convert_adminica_error_to_elmer_error($that_error);
                }
            }
            else {
                $cleaned_errors = convert_adminica_error_to_elmer_error($errors);
            }
        }
        else {
            $cleaned_errors = $errors;
        }

        if (is_array($cleaned_errors)) {

            //
            //--- Remove all duplicate VALUES from $cleaned_errors:
            //

            // Count the occurrences of each value
            $valueCounts = array_count_values(array_map('serialize', $cleaned_errors));

            // Use array_filter to remove keys with duplicate values
            $this->BXErrors = array_filter($cleaned_errors, function ($value) use ($valueCounts) {
                return $valueCounts[serialize($value)] === 1; // Keep only values with count 1 (no duplicates)
            });
        }
        else {
            $this->BXErrors = $cleaned_errors;
        }
        $this->BXErrors = array_map("unserialize", array_unique(array_map("serialize", $this->BXErrors)));
        $data['Errors'] = $this->BXErrors;
        session()->set($data);
    }

    // Return the Array containing our Errors:
    public function getErrors() {
        $this->BXErrors = session()->get('Errors');
        if (!is_array($this->BXErrors)) {
            $this->BXErrors = array();
        }
        return $this->BXErrors;
    }

    // Set the Wait Overlay that shows on Saving:
    public function setOverlay($ovl) {
        $this->Overlay = $ovl;
    }

    // Return the Wait Overlay that shows on Saving:
    public function getOverlay() {
        return $this->Overlay;
    }

    // Set primary color of the GUI based on the used Theme:
    public function setPrimaryColor($color) {
        $this->PrimaryColor = $color;
    }

    // Return the Wait Overlay that shows on Saving:
    public function getPrimaryColor() {
        // Get Theme information from Cookie:
        if (isset($_COOKIE['theme_switcher_php-style'])) {
            $primaryColor = $_COOKIE['theme_switcher_php-style'];
            if ($primaryColor != "") {
                if (preg_match('/^theme_(.*)\.css$/', $primaryColor, $treffer)) {
                    $colorArray = array("blue", "navy", "red", "green", "magenta", "brown");
                    if (in_array($treffer[1], $colorArray)) {
                        $this->setPrimaryColor($treffer[1]);
                    }
                    else {
                        $this->setPrimaryColor('blue');
                    }
                }
                if (preg_match('/^switcher\.css$/', $primaryColor)) {
                    $this->setPrimaryColor('black');
                }
            }
        }
        else {
            // No cookie for color. Return default color:
            return 'blue';
        }
        return $this->PrimaryColor;
    }

    // We have certain display elements (pagedBlock) which have a built in and pre-defined location for 
    // displaying error messages. However, we're not using these elements everywhere. So if we're not
    // having an element on the page with built in display of error messages, we need to display them 
    // in front of the page body. This function allows us to keep track if an element has such a 
    // built in error message area or not:
    public function HaveErrorMsgDisplayArea($display) {
        $this->BXErrorDisplayArea = $display;
    }

    // Check if we have elements with error message display area:
    public function getErrorMsgDisplayArea() {
        if (isset($this->BXErrorDisplayArea)) {
            return $this->BXErrorDisplayArea;
        }
        else {
            return FALSE;
        }
    }

    //
    //--- These *are* the droids you're looking for:
    //
    public function render ($page_module = "", $page_body = "") {

        // Start with blank debug info:
        $debug = "";

        $CI = get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        // Extra Header handling:
        if (!isset($this->extra_headers)) {
            $this->extra_headers = array();
        }
        if (!is_array($this->extra_headers)) {
            $this->extra_headers = array();
        }

        // Extra Footer handling:
        if (!isset($this->extra_footers)) {
            $this->extra_footers = array();
        }
        if (!is_array($this->extra_footers)) {
            $this->extra_footers = array();
        }

        // Delete Dialog handling:
        if (!isset($this->delete_dialog)) {
            $this->delete_dialog = array();
        }
        if (!is_array($this->delete_dialog)) {
            $this->delete_dialog = array();
        }

        //
        //--    Carabine Module for CSS minification.
        //      We're not using this at the moment as
        //      It has a few drawbacks.
        //
        //$this->load->library('carabiner');
        //$this->load->library('cssmin');
        //$this->load->library('jsmin');

        // Get $sessionId and $loginName from Cookie (if they are set):
        $sessionId = $BX_SESSION['sessionId'];
        $loginName = $BX_SESSION['loginName'];

        // Make sure we have a sessionId. If not? Back to /login it is!
        if ($sessionId == '') {
            $uri = $CI->bx_get_gui_url();
            bx_error_log("BxPage: Redirect to /login as we have no sessionId for request-URI: " . $uri['actual']);
            return redirect()->to(base_url() . $uri['expired']);
        }

        // Find out if serverScriptHelper has already been initialized:
        $serverScriptHelper = $CI->getSSH();

        if (!$serverScriptHelper) {
            // It has not been initialized yet, so we do it here:
            $serverScriptHelper = new ServerScriptHelper($sessionId, $loginName);
            $this->cceClient = $serverScriptHelper->getCceClient();
        }
        else {
            // Was already initialized. Reuse it:
            $this->cceClient = $CI->getCCE();
        }

        // CSRF security measure: 
        //
        // The CI integration of CSRF only protects against POST requests. We extend this to also work during GET requests.
        // If CSRF is enabled and a GUI page is accessed via GET *or* POST request with missing or incorrect 'csrf_cookie',
        // then we expire the relevant cookies. Which will bump the user back to the login page with an expired session.
        //        if (($BX_SESSION['csrf_protection'] == '1') && (!$this->CSRF_ignore)) {
        //
        //            // Check if URI has been whitelisted from CSRF checks:
        //            $csrf_whitelisted_uri = '0';
        //            if ($exclude_uris = config_item('csrf_exclude_uris')) {
        //                $uri = load_class('URI', 'core');
        //                foreach ($exclude_uris as $excluded) {
        //                    if (preg_match('#^'.$excluded.'$#i'.(UTF8_ENABLED ? 'u' : ''), $uri->uri_string())) {
        //                        $csrf_whitelisted_uri = '1';
        //                    }
        //                }
        //            }
        //
        //            // CSRF is enabled and URI is not whitelisted:
        //            if ($csrf_whitelisted_uri == '0') {
        //
        //                // CSRF cookie empty or populated with something that doesn't match expectations: Bye bye!
        //                if (($BX_SESSION['csrf_cookie'] == '') || ($BX_SESSION['csrf_cookie'] != $CI->security->get_csrf_hash())) {
        //                    delete_cookie("loginName");
        //                    delete_cookie("sessionId");
        //                    delete_cookie("userip");
        //                    delete_cookie($BX_SESSION['csrf_cookie_name']);
        //                    //$CI->cceClient->endkey();
        //                    bx_error_log("ServerScriptHelper.ServerScriptHelper(): CSRF is enabled, but user '$loginName' with session ID '$sessionId' had a missing or invalid CSFR-cookie."); 
        //                    // Redirect to login page with proper targets populated to be able to return after successful login:
        //                    header("cache-control: no-cache");
        //                    print("
        //                    <HTML>
        //                    <HEAD>
        //                    <META HTTP-EQUIV=\"expires\" CONTENT=\"-1\">
        //                    <META HTTP-EQUIV=\"Pragma\" CONTENT=\"no-cache\">
        //                    </HEAD>
        //                    <BODY onLoad=\"redirect()\">
        //                    <SCRIPT LANGUAGE=\"javascript\">
        //                    function redirect() {
        //                    var pathname = top.location.pathname;
        //                    // IE4.0 has a bug that location.pathname contains port at the beginning
        //                    if(top.location.port != null && top.location.port != \"\" && pathname.indexOf(\"/:\"+top.location.port) == 0)
        //                    pathname = pathname.substring(2+top.location.port.length);
        //                    var url = \"/expired/true/target\"+escape(pathname+top.location.search+top.location.hash);
        //
        //                    top.location = url;
        //                    top.focus();
        //                    }
        //                    </SCRIPT>
        //                    </BODY>
        //                    </HTML>");
        //                    exit;
        //                }
        //            }
        //        }

        $user = $BX_SESSION['loginUser'];
        $access = $serverScriptHelper->getAccessRights($this->cceClient);

        // Special case: shellAccessEnabled
        if ($BX_SESSION['userShell'] > "0") {
            $access[] = 'shellAccessEnabled';
        }

        // In our menus we have [[VAR.hostname]] and [[VAR.group]] which need to be 
        // substituted with the correct values. This is done by the subroutine
        // fixInternalURLs() which is called via generateSiteMap(). To do so, we 
        // need to find out which hostname should be used and which group we want to
        // set. The group is determined based on the URL parameter 'group'. The
        // hostname is set to the FQDN of the Vsite that the user belongs to.
        // If the user doesn't belong to a Vsite, we leave it empty:

        $get_form_data = $this->FORM_GET;
        if (isset($get_form_data['group'])) {
            // We have a group set via URL parameter:
            $vsite = $this->cceClient->getObject("Vsite", array("name" => $get_form_data['group']));
            if (!isset($vsite['fqdn'])) {
                $vsite['fqdn'] = gethostname();
            }
            $hostName = $vsite['fqdn'];
            $group = $get_form_data['group'];
        }
        elseif (isset($get_form_data['instance'])) {
            // We have a group set via URL parameter:
            $instance = $get_form_data['instance'];
            $vsite['fqdn'] = $instance;
            $hostName = $instance;
            $group = $instance;
        }
        else {
            // We don't have the URL parameter set via URL. So we just determine the FQDN
            // based on the Vsite the user belongs to and set the group based on the Vsite
            // the user belongs to:
            $vsite = $this->cceClient->getObject("Vsite", array("name" => $user['site']));
            if (isset($vsite['fqdn'])) {
                $hostName = $vsite['fqdn'];
            }
            else {
                // User doesn't belong to a Vsite. So we leave this empty:
                $hostName = "";
                $vsite['fqdn'] = gethostname();
            }
            $group = $user['site'];
        }

        // ActiveMonitor warning defaults:
        $AM_yellow_items = '0';
        $AM_red_items = '0';
        $ActiveMonitorErrors = [];
        $activeMonitorObj = array(
            'enabled' => '0',
            'globalState' => 'G'
        );
        $ActiveMonitorData = array();
        $AMnames = array();

        // Only poll "ActiveMonitor" if the GUI user actually has ACL rights for 'ActiveMonitor':
        if (in_array("serverShowActiveMonitor", $access)) {
            // Get Active Monitor Alerts (fast way):
            $all_AM_data = $this->cceClient->getAll("ActiveMonitor", array());
            $all_AM_data = reset($all_AM_data);
            if ((isset($all_AM_data['OBJECT'])) && (is_array($all_AM_data['OBJECT']))) {
                $activeMonitorObj = $all_AM_data['OBJECT'];
                unset($all_AM_data['OBJECT']);
                $ActiveMonitorData = $all_AM_data;
                $AMnames = array_keys($all_AM_data);
            }
            else {
                // Fallback to slow method:
                $activeMonitorObj = $this->cceClient->getObject("ActiveMonitor");
                if (is_array($activeMonitorObj) && isset($activeMonitorObj["OID"])) {
                    $AMnames = $this->cceClient->names($activeMonitorObj["OID"]);
                    $oid_list = '["' . $activeMonitorObj['OID'] . '"]';
                    $output = '';
                    $ret = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/external_cce_get.pl --oid $oid_list", $output, 'root', $BX_SESSION['sessionId']);
                    if ($ret != 0) {
                        // Failed!
                        $ActiveMonitorData = array();
                    }
                    else {
                        $JSON_AM = json_decode($output, true);
                        $ActiveMonitorData = $JSON_AM[$activeMonitorObj['OID']];
                    }
                }
                else {
                    $activeMonitorObj = array(
                        'enabled' => '0',
                        'globalState' => 'G'
                    );
                }
            }
            foreach ($ActiveMonitorData as $key => $item) {
                if (isset($item['NAMESPACE'])) {
                    if ((isset($item['monitor'])) && (isset($item['currentState']))) {
                        if ($item['monitor'] === '1' && ($item['currentState'] === 'Y' || $item['currentState'] === 'R')) {
                            $ActiveMonitorErrors[$key] = $item;
                            if ($item['currentState'] === 'Y') {
                                $AM_yellow_items++;
                            }
                            if ($item['currentState'] === 'R') {
                                $AM_red_items++;
                            }
                        }
                    }
                }
            }

            // See if any monitored item is in bad state
            if ($activeMonitorObj["enabled"] == "0") {
                // If AM is disabled, then we don't show the AM-Status:
                $isAlert = "light";
            }
            else {
                // If AM is enabled, then we start without active error and everything in the blue:
                $colorBGarray = array(
                                    "black" => "alert_black",
                                    "blue" => "alert_blue",
                                    "navy" => "alert_navy",
                                    "red" => "alert_magenta",
                                    "green" => "alert_green",
                                    "magenta" => "alert_magenta",
                                    "brown" => "alert_brown"
                                    );
                $isAlert = $colorBGarray[$this->getPrimaryColor()];

                if ($activeMonitorObj["globalState"] == "R") {
                    // We have at least one 'red' item. Stop further checks and show the red alert:
                    $isAlert = "alert_red";
                }
                if ($activeMonitorObj["globalState"] == "Y") {
                    // Give a yellow warning:
                    $isAlert = "alert_orange";
                }

                // Start: RAID work-around:
                // Yes. This is dirty. Remind me to fix /usr/sausalito/swatch/bin/raid_amdetails.pl, though.
                if (is_file("/proc/mdstat")) {
                    //helper(['raid_helper']);
                    list($array_health, $array_fail) = fast_raid_check($this->cceClient, $serverScriptHelper);
                    if ((count($array_fail) > 0) && (count($array_health) == 0)) {
                        $state = "fail";
                        $isAlert = "alert_red";
                    }
                    elseif ((count($array_fail) == 0) && (count($array_health) > 0)) {
                        $state = "syncing";
                        $isAlert = "alert_orange";
                    }
                    elseif ((count($array_fail) == 0) && (count($array_health) == 0)) {
                        $state = "raidOK";
                    }
                    else {
                        // Both are >1:
                        $state = "syncing";
                        $isAlert = "alert_orange";
                    }
                }
                // End: RAID work-around:
            }
        }
        else {
            // Fallback to not leave it undefined:
            $isAlert = "light";
        }

        // Get 'Support' object:
        $Support = $CI->getSupport();

        // Reset errors:
        $this->setErrors(array());

        $this->cceClient->bye();

        // Find out the browser locale and display a message in our supported languages:
        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        // Now set the locale based on the users localePreference - if specified and known:
        if ($user['localePreference']) {
            $locale = $user['localePreference'];
        }

        // Set headers:
        $CI->response->setHeader('Cache-Control', 'must-revalidate');

        // Get 'System' object
        $system = $CI->getSystem();

        // Set page title:
        preg_match("/^([^:]+)/", $_SERVER['HTTP_HOST'], $matches);
        $hostname = $matches[0];
        // Strip out the :444 or :81 from the hostname - if present:
        if (preg_match('/:/', $hostname)) {
            $hn_pieces = explode(":", $hostname);
            $hostname = $hn_pieces[0];
        }
        $i18n = new I18n("palette", $locale);
        preg_match("/([^:]+):?.*/", $hostname, $matches);
        $hostname_new = $matches[1] ? $matches[1] : `/bin/hostname --fqdn`;
        $page_title = $i18n->getHtml("navigationTitle", "", array("hostName" => $hostname_new, "userName" => $serverScriptHelper->getLoginName()));

        // Connect to CCE if possible. If not, display that CCE is down:
        if(!$this->cceClient->connect()) {
          if($this->locale == "") {
            $CI->load->library('System');
            $system = new System();
            $defaultLocale = $system->getConfig("defaultLocale");
          }
          $i18n = new I18n("palette", $defaultLocale);
          // Display the error message and quit:
          $cceDown = "<div style=\"text-align: center;\"><br><br><br><br><span style=\"color: #990000;\">"
              . $i18n->getHtml("cceDown") . "</span></div>";
            echo "$cceDown";
            bx_error_log("loginHandler.php: $cceDown");
            $this->cceClient->bye();
            $serverScriptHelper->destructor();
            exit;
        }

        // Construct URLs:
        $servername = $system['hostname'] . '.' . $system['domainname'];
        $http_server_name = $_SERVER['SERVER_NAME'];
        $http_server_name = preg_replace('/\[/', '', $http_server_name);
        $http_server_name = preg_replace('/\]/', '', $http_server_name);
        if (filter_var($http_server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // SERVER_NAME is an IPv6 IP! Need to escape the IPv6 IP before using it in an URL:
            $https_url = 'https://[' . $_SERVER['SERVER_NAME'] . ']:' . $BX_SESSION['GUI_PORT'] . $_SERVER['REQUEST_URI'];
        }
        else {
            // SERVER_NAME is an IPv4 IP or FQDN, so we can use it directly:
            $https_url = 'https://' . $http_server_name . ':' . $BX_SESSION['GUI_PORT'] . $_SERVER['REQUEST_URI'];
        }

        // Handle redirects to HTTP(S) and/or FQDN of server:
        if ((isset($system['GUIaccessType'])) && (isset($system['GUIredirects']))) {
            if ($system['GUIredirects'] === "1") {
                if ($servername != $_SERVER['SERVER_NAME']) {
                    // Correct $https_url to servername:
                    $https_url = 'https://' . $servername . ':' . $BX_SESSION['GUI_PORT'] . $_SERVER['REQUEST_URI'];
                    bx_error_log("BxPage: Redirect to: $https_url");
                    header("Location: $https_url");
                    $this->cceClient->bye();
                    $serverScriptHelper->destructor();
                    exit;
                }
            }
        }

        $twoFactorSetupRestricted = $this->isTwoFactorSetupRestrictionActive();
        if ($twoFactorSetupRestricted && ($page_module == 'gui')) {
            header("Location: /user/personalTwoFactor");
            exit;
        }
        if ($twoFactorSetupRestricted && (uri_string() == 'user/personalTwoFactor')) {
            $page_module = 'base_personalTwoFactor';
        }

        // If web based setup has not been completed, then redirect to /wizard
        if ( ! $system['isLicenseAccepted'] ) {
            // As $system['isLicenseAccepted'] comes from the session cache, we double check in case it is zero:
            $system = $this->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));

            // Check if the current URL is already '/wizard' or starts with '/wizard'
            if ($system['isLicenseAccepted'] == "0" && current_url() != site_url('wizard')) {
                // Web based setup has *NOT* been completed. Redirect to /wizard
                header("Location: /wizard");
                exit;
            }
        }

        // auth failed? Yeah it is redundant. Just to be really sure!
        if($sessionId == "") {
            // Login failed. We need to show the login form again with error message.
            $this->cceClient->bye();
            $serverScriptHelper->destructor();
            header("Location: /");
            exit;
        }
        else {
            //
            // If we get this far, the auth based on the cookie still works and we're good.
            // Gandalf is not shouting "Thou shallst not pass!", so we proceed:
            //

            //---- Start: Menu Display

            // If User has 'ManageVPS' but NOT 'ManageIncus' access rights? Then we need to manually remove
            // all siteManagement related access rights:
            if ((in_array('ManageVPS', $access)) && (!in_array('ManageIncus', $access))) {
                $stray_reseller_caps = array('siteAnonFTP', 'manageSite', 'siteShell', 'siteSSL', 'siteAdmin');
                foreach ($stray_reseller_caps as $key => $value) {
                    // Remove all reseller caps from currently used caps:
                    if (($key = array_search($value, $access)) !== false) {
                        unset($access[$key]);
                    }
                }
            }

            // Load the Menu XML files and generate the $_SiteMap_items object:
            timer('BxPage: generateSiteMap()');
            //$_SiteMap_items = generateSiteMap(FALSE, $access, $this->cceClient, array('group' => $group, 'fqdn' => $hostName));
            
            // Use Redis-Cache with TTL 1h for a checksummed XML sitemap:
            $_SiteMap_items = generateSiteMapCached($access, ['group' => $group, 'fqdn' => $hostName], FALSE);
            timer('BxPage: generateSiteMap()');

            //
            //-- Beyond this point we do NOT need cceClient anymore!
            //
            //-- Therefore we disconnect from CCE.
            //
            // I cannot stress how important this is: Say 'bye' and use the deconstructor() whenever
            // you are done talking to CCE. If you don't and the script buggers out, the cced-child
            // process will hang around forever. So we do this religiously here, just to be damn sure:
            $this->cceClient->bye();
            $serverScriptHelper->destructor();

            // Populate output:
            $profile_text = $i18n->getHtml("[[base-alpine.base-personalProfile]]");

            $profile_link = '/user/personalAccount';
            $settings_text = $i18n->getHtml("[[base-vsite.sitemail]]");
            $settings_link = '#';
            $logout_text = $i18n->get("logout", "palette");
            $logout_link = '/logout/true';
            $parent_root = '';

            if ((in_array("adminUser", $access)) || ((in_array("siteAdmin", $access)) && (count($access) >= "2"))) {

                // Wizard exception:
                if (($system['isLicenseAccepted'] == '0') && ((current_url() === site_url('wizard')) || (current_url() === site_url('login')))) {
                    $page_module = 'gui';
                    $active_menu_item = 'base_personalProfile';
                    $parent_root = 'root';
                    $ignore_items = array('base_manualButton');
                    // Use function MenuChildren to get a sorted list of children for our menu entries:
                    $root_children_sort_order = array();
                }
                else {
                    // Not a Wizard page:
                    $parent_root = 'root';
                    $ignore_items = array('base_manualButton', 'base_updateLight');
                    // Use function MenuChildren to get a sorted list of children for our menu entries:
                    $root_children_sort_order = MenuChildren($parent_root, $ignore_items, $_SiteMap_items, $access);

                    if ($page_module == "gui") {
                        $url = getURLofFirstChild($root_children_sort_order, $ignore_items, $_SiteMap_items, $access);
                        header("Location: $url");
                        exit;
                    }
                    else {
                        $active_menu_item = $page_module;
                    }
                }
            }
            elseif (in_array("siteAdmin", $access)) { 
                if ($page_module == "gui") {
                    $parent_root = 'root';
                    $ignore_items = array('base_manualButton', 'base_updateLight');
                    $url = getURLofFirstChild($parent_root, $ignore_items, $_SiteMap_items, $access);
                    // Redirect:
                    header("Location: $url");
                    exit;
                }
                else {
                    $active_menu_item = $page_module;   
                }
                $parent_root = 'root';
                $ignore_items = array('base_manualButton');
                // Use function MenuChildren to get a sorted list of children for our menu entries:
                $root_children_sort_order = MenuChildren($parent_root, $ignore_items, $_SiteMap_items, $access);
            }
            else {
                $active_menu_item = 'base_personalProfile';
                if ($page_module == "gui") {
                    $ignore_items = array();
                    $url = getURLofFirstChild($active_menu_item, $ignore_items, $_SiteMap_items, $access);
                    header("Location: $url");
                    exit;
                }
                else {
                    $active_menu_item = $page_module;
                }
                $parent_root = $active_menu_item;
                $ignore_items = array();

                // Use function MenuChildren to get a sorted list of children for our menu entries:
                $root_children_sort_order = MenuChildren($active_menu_item, $ignore_items, $_SiteMap_items, $access);
            }

            //
            //- Start: Horizontal Menu Assembly
            //

            // This is a stupid and brain-dead work around:
            // Please recall: Horizontal menus can have only one branch. To get around
            // this limitation we have two "Site Management" menu entries. One is named
            // 'base_sitemanage' and has all the submenu entries you see when editing a
            // Vsite. The other one is named 'base_sitemanageVSL' and has the menu entries
            // "Site Management", "Add Site" and "Template". Of course we only want to show
            // *one* "Site Management" entry in the horizontal menu. So with the code below
            // we remove the unwanted one based on which $page_module is selected. If it's
            // stupid and works ... then maybe it ain't *that* stupid:
            if ($page_module == "solarspeed_ave_instance_mod") {
                if (isset($root_children_sort_order['base_sitemanage'])) {
                    unset($root_children_sort_order['base_sitemanage']);
                }
            }
            else {
                if (isset($root_children_sort_order['base_sitemanageVSL'])) {
                    unset($root_children_sort_order['base_sitemanageVSL']);
                }
            }

            // Same for solarspeed-aventurine:
            if ($page_module == "solarspeed_ave_menu_SINGLE") {
                if (isset($root_children_sort_order['solarspeed_ave_menu'])) {
                    unset($root_children_sort_order['solarspeed_ave_menu']);
                }
            }
            else {
                if (isset($root_children_sort_order['solarspeed_ave_menu_SINGLE'])) {
                    unset($root_children_sort_order['solarspeed_ave_menu_SINGLE']);
                }
            }

            // Now loop through $root_children_sort_order and print out the horizontal
            // menu. As it has no sub-elements we can do it in a really simple fashion:
            $nav_html_menu = '';

            $num_of_entry = "1";
            $active_top_menu = "1";
            $active_side_menu = "1";
            $active_menu_item_for_display = "";
            $root_children_sort_order_internal = array();

            // Find out what page we're currently on. For this we match the active URL against the URLs in the menu schemas:
            $currently_active_page = "/" . uri_string();

            foreach ($_SiteMap_items as $key => $value) {
                if ((isset($value['url'])) && ($active_menu_item_for_display == "") && (uri_string() != "gui")) {
                    if ($value['url'] == $currently_active_page) {
                        $active_menu_item_for_display = $value['id'];
                        if (isset($value['parents']['id'])) {
                            $active_menu_item_parent_for_display = $value['parents']['id'];
                        }
                        else {
                            $active_menu_item_parent_for_display = $parent_root;
                        }
                    }
                }
            }

            if (isset($this->vertical_menu_child_override)) {
                $active_menu_item_for_display = $this->vertical_menu_child_override;
            }

            // This array helps us to convert Adminica icons to Elmer icons:
            $icon_adminica_to_elmer = array(
                'cog_2' => 'fa fa-gears',
                'frames' => 'fa fa-th-list',
                'refresh_3' => 'fa fa-repeat',
                'admin_user' => 'fa fa-user',
                'globe' => 'fa fa-globe',
                'users_2' => 'fa fa-user',
                'download_to_computer' => 'fa fa-laptop',
                'preview' => 'fa fa-eye',
                'books' => 'fa fa-th',
                'shuffle' => 'fa fa-wrench',
                'robot' => 'fa fa-android',
                'graph' => 'ti ti-stats-up',
                'alert' => 'fa fa-warning',
                'bended_arrow_right' => 'fa fa-plus-square-o',
                'settings' => 'fa fa-sliders',
                'shopping_cart' => 'fa fa-shopping-cart',
                'magnifying_glass' => 'fa fa-download',
                'phone_3' => 'fa fa-phone',
                'v_card_2' => 'fa fa-envelope',
                'address_book' => 'fa fa-calendar',
                'chart_8' => 'ti ti-stats-down',
                'linux' => 'fa fa-hashtag',
                'wifi_signal' => 'fa fa-key',
                'robot' => 'fa fa-wrench',
                'firefox' => 'fa fa-sitemap',
                'users' => 'fa fa-users',
                'locked_2' => 'fa fa-lock',
                'create_write' => 'fa fa-edit'
            );

            //
            //-- Assemble 'Categories' menu for Elmer:
            //
            if (count($access) != "0") {

                foreach ($root_children_sort_order as $MenuItem => $MenuSort) {
                    $menu_iten_is_active = '0';
                    if (isset($_SiteMap_items[$MenuItem]['url'])) {
                        $u = $_SiteMap_items[$MenuItem]['url'];
                    }
                    else {
                        $u = getURLofFirstChild($_SiteMap_items[$MenuItem]['id'], array("base_siteName"), $_SiteMap_items, $access);
                    }
                    // Set active horizontal menu based on what the $page_module says:
                    if ($page_module == $_SiteMap_items[$MenuItem]['id']) {
                        $menu_iten_is_active = '1';
                    }

                    // HTML-Output for Menu entry:
                    if ($_SiteMap_items[$MenuItem]['icononly']) {
                        // We skip items that are set to 'icononly' (such as 'base_monitorLight' and 'base_logout')
                    }
                    elseif (($MenuItem == 'personalBackup') || ($MenuItem == 'solarspeed_personalOpenVPN')) {
                        // Ignore Solarspeed additions from horizontal menu.
                    }
                    else {
                        $id = $_SiteMap_items[$MenuItem]['id'];
                        $label = $i18n->getHtml($_SiteMap_items[$MenuItem]['label']);
                        $label_help = $i18n->getHtml($_SiteMap_items[$MenuItem]['description']);

                        $icon = 'fa fa-circle';
                        if (isset($_SiteMap_items[$MenuItem]['icon'])) {
                            if ($_SiteMap_items[$MenuItem]['elmer_icon'] != '') {
                                // Menu XML has 'elmer_icon' - use it:
                                $icon = $_SiteMap_items[$MenuItem]['elmer_icon'];
                            }
                            else {
                                // Menu XML has no 'elmer_icon' - translate Adminica icon to Elmer:
                                $old_icon = $_SiteMap_items[$MenuItem]['icon'];
                                if (isset($icon_adminica_to_elmer[$old_icon])) {
                                    $icon = $icon_adminica_to_elmer[$old_icon];
                                }
                            }
                        }

                        // Is Menu entry active?
                        $menu_state = 'inactive';
                        if ($menu_iten_is_active == '1') {
                            $menu_state = 'active';
                        }

                        // Assemble menu entry:
                        $nav_html_menu .= "                <li>\n                    ";
                        $nav_html_menu .= <<<HTML
                                <a class="$menu_state" href="$u" data-toggle="collapse" data-target="#$id"><div class="pull-left" data-toggle="tooltip" data-placement="right" title="$label_help" data-original-title="$label_help" data-container="body"><i class="$icon mr-10"></i><span class="right-nav-text">$label</span></div><div class="pull-right"><i class="zmdi"></i></div><div class="clearfix"></div></a>
                        HTML;
                            $nav_html_menu .= "\n                </li>\n";
                    }
                    // Bump the number:
                    $num_of_entry++;
                }
            }

            //- End: Horizontal Menu Assembly

            //- Start: Vertical Menu Assembly

            //
            //-- Assemble 'Options' menu for Elmer:
            //

            // Use function MenuChildren to get a sorted list of children for our menu entries:
            $root_children_sort_order = MenuChildren($active_menu_item, array(), $_SiteMap_items, $access);

                // Now loop through $root_children_sort_order and print out the horizontal
                // menu. As it has no sub-elements we can do it in a really simple fashion:
                $side_html_menu = '';
                $iteration = '1';
                $active_side_menu_entry = " ";
                $active_nav_inner_entry = " ";

                foreach ($root_children_sort_order as $MenuItem => $MenuSort) {
                    $menutext = $i18n->getHtml($_SiteMap_items[$MenuItem]['label'], "", array("hostname" => $vsite['fqdn']));

                    if (isset($_SiteMap_items[$MenuItem]['url'])) {
                      $u = $_SiteMap_items[$MenuItem]['url'];
                    }
                    else {
                      $u = getURLofFirstChild($_SiteMap_items[$MenuItem]['id'], array(), $_SiteMap_items, $access);
                    }

                    // Get the second level children of the currently active horizontal menu entry:
                    $submenu_entry = MenuChildren($MenuItem, array(), $_SiteMap_items, $access);

                    // Check if the current menu item requires children and has none:
                    if (($_SiteMap_items[$MenuItem]['requiresChildren'] == "1") && (count($submenu_entry) == "0")) {
                        // Being lazy
                    }
                    else {
                        if (!in_array($MenuItem, $ignore_items)) {
                            if ($menutext) {
                                // Store order of the menu entry and it's ID in $root_children_sort_order_internal as well:
                                $z = $_SiteMap_items[$MenuItem]['id'];
                                $root_children_sort_order_internal[$z] = $iteration;

                                if (!count($submenu_entry) == "0") {
                                    $u = 'javascript:void(0);';
                                    $data_target = $MenuItem;
                                    $caret_down = 'zmdi zmdi-caret-down';
                                    $item_has_children = '1';
                                }
                                else {
                                    $data_target = 'dashboard_dr';
                                    $caret_down = 'zmdi';
                                    $item_has_children = '0';
                                }

                                // Description may contain variables. Deal with them:
                                $label_help = fixInternalURLs($i18n->getHtml($_SiteMap_items[$MenuItem]['description']), array('group' => $group, 'fqdn' => $hostName));
                                $label_HTML = 'data-toggle="tooltip" data-placement="right" title="' . $label_help . '" data-original-title="' . $label_help . '" data-container="body"';

                                $icon = 'fa fa-circle';
                                if (isset($_SiteMap_items[$MenuItem]['icon'])) {
                                    if ($_SiteMap_items[$MenuItem]['elmer_icon'] != '') {
                                        // Menu XML has 'elmer_icon' - use it:
                                        $icon = $_SiteMap_items[$MenuItem]['elmer_icon'];
                                    }
                                    else {
                                        // Menu XML has no 'elmer_icon' - translate Adminica icon to Elmer:
                                        $old_icon = $_SiteMap_items[$MenuItem]['icon'];
                                        if (isset($icon_adminica_to_elmer[$old_icon])) {
                                            $icon = $icon_adminica_to_elmer[$old_icon];
                                        }
                                    }
                                }

                                if (!array_key_exists($active_menu_item_for_display, $submenu_entry)) {
                                    $option_state_menu = 'inactive';

                                    if (($active_menu_item_for_display === 'base_personalProfile') && ($_SiteMap_items[$MenuItem]['id'] === 'base_personalAccount')) {
                                        // Make sure "base_personalAccount" triggers as active if we're on 'base_personalProfile':
                                        $option_state_menu = 'active';
                                    }
                                    elseif ($active_menu_item_for_display == $_SiteMap_items[$MenuItem]['id']) {
                                        $option_state_menu = 'active';
                                    }

                                    $side_html_menu .= "\n                <li>" . "\n                    ";
                                    $side_html_menu .= <<<HTML
                                        <a class="$option_state_menu" href="$u" data-toggle="collapse" data-target="#$data_target"><div class="pull-left truncate" $label_HTML><i class="$icon mr-10"></i><span class="right-nav-text">$menutext</span></div><div class="pull-right"><i class="$caret_down"></i></div><div class="clearfix"></div></a>
                                    HTML;
                                    if ($item_has_children == '0') {
                                        $side_html_menu .= "\n                </li>" . "\n";
                                    }
                                }
                                else {
                                    $side_html_menu .= "\n                <li>" . "\n                    ";
                                    $side_html_menu .= <<<HTML
                                            <a class="active" href="$u" data-toggle="collapse in" data-target="#$data_target"><div class="pull-left truncate" $label_HTML><i class="$icon mr-10"></i><span class="right-nav-text">$menutext</span></div><div class="pull-right"><i class="$caret_down"></i></div><div class="clearfix"></div></a>
                                    HTML;
                                    if ($item_has_children == '0') {
                                        $side_html_menu .= "\n                </li>" . "\n";
                                    }
                                }
                                $iteration++;
                            }

                            // Get the second level children of the currently active horizontal menu entry:
                            $submenu_entry = MenuChildren($MenuItem, array(), $_SiteMap_items, $access);

                            // Print the HTML for second level menu entries (if there are any):
                            $sme = "1";
                            if (!count($submenu_entry) == "0") {

                                $SUBMENU_active_menu_item_for_display = $active_menu_item_for_display;
                                if (isset($_SiteMap_items[$active_menu_item_for_display]['parents']['id'])) {
                                    $SUBMENU_active_menu_item_for_display = $_SiteMap_items[$active_menu_item_for_display]['parents']['id'];
                                }

                                //if ($active_menu_item_for_display == $MenuItem) {
                                if ($SUBMENU_active_menu_item_for_display == $MenuItem) {
                                    //$side_html_menu .= "\n                    <ul id=\"$MenuItem\" class=\"\">\n";
                                    $side_html_menu .= "\n                    <ul id=\"$MenuItem\" class=\"collapse collapse-level-1 in\">\n";
                                }
                                else {
                                    $side_html_menu .= "\n                    <ul id=\"$MenuItem\" class=\"collapse collapse-level-1\">\n";
                                }
                                foreach ($submenu_entry as $MenuItem => $MenuSort) {
                                    $menutext = $i18n->getHtml($_SiteMap_items[$MenuItem]['label']);
                                    $menu_help = $i18n->getHtml($_SiteMap_items[$MenuItem]['description']);
                                    if (isset($_SiteMap_items[$MenuItem]['url'])) {
                                        $u = $_SiteMap_items[$MenuItem]['url'];
                                    }
                                    else {
                                        $u = getURLofFirstChild($_SiteMap_items[$MenuItem]['id'], array(), $_SiteMap_items, $access);
                                    }

                                    $extra_class_for_active_menuitem = '';
                                    if ($MenuItem == $active_menu_item_for_display) {
                                        // Set currently visited Submenu Item as active:
                                        $extra_class_for_active_menuitem = ' class="active-page"';
                                    }

                                    $side_html_menu .= "                        <li>" . "\n";
                                    $side_html_menu .= "                            ";
                                    // class="active-page"
                                    $side_html_menu .= <<<HTML
                                                            <a href="$u" id="$MenuItem" $extra_class_for_active_menuitem data-toggle="tooltip" data-placement="right" title="$menu_help" data-original-title="$menu_help" data-container="body">$menutext</a>
                                                          HTML;
                                    $side_html_menu .= "\n                        </li>" . "\n";

                                    // At this time we do not want to support more than two layers of menus. Deal with it.
                                }
                                $side_html_menu .= "                    </ul>\n";
                                $side_html_menu .= "                </li>\n";
                                $sme++;
                            }
                        } // Ignore check
                    } // Children check
                }

                // Define which menu entry is active in the leftside menu and set $active_side_menu accordingly:
                $just_the_keys = array_keys($root_children_sort_order);

                if (in_array($active_menu_item_for_display, $just_the_keys)) {
                    $active_side_menu = $root_children_sort_order_internal[$active_menu_item_for_display];
                }
                else {
                    // If we have an override set via BxPage->setVerticalMenu(), then we use that one instead:
                    if (isset($this->vertical_menu_override)) {
                        if (in_array($this->vertical_menu_override, $just_the_keys)) {
                            $active_side_menu = $root_children_sort_order_internal[$this->vertical_menu_override];
                        }                   
                    }
                }

            if (!$active_menu_item_for_display) {
                $active_menu_item_for_display = $active_menu_item;
            }

            //- Stop: Vertical Menu Assembly

            // Hard coded for the moment - need to fix this later:
            if (isset($_SiteMap_items[$active_menu_item_for_display]['label'])) {
                $active_page_title = $i18n->getHtml($_SiteMap_items[$active_menu_item_for_display]['label']);
            }
            else {
                $active_page_title = "";
            }
            if (isset($_SiteMap_items[$active_menu_item_for_display]['description'])) {
                $active_page_help = $i18n->getHtml($_SiteMap_items[$active_menu_item_for_display]['description']);
            }
            else {
                $active_page_help = "";
            }

            // Set ChorizoStyle cookies from session data:
            if (session()->get('ChorizoStyle')) {
                $Session_ChorizoStyle = session()->get('ChorizoStyle');
                $layout = $Session_ChorizoStyle['layout_switcher_php-style'];
            }
            else {
                // Check if the visitor is using a browser or a mobile device:
                if (!isset($this->AGENT)) {
                    // Someone forgot to set $BxPage->setGETPOST() in his GUI page. So we assume fixed layout:
                    $layout = "layout_fixed.css";
                }
                else {
                    $mobile = $this->AGENT->isMobile();
                    if (!$mobile) {
                        $layout = "layout_fixed.css";
                        $agent = $this->AGENT->getBrowser() . ' ' . $this->AGENT->getVersion();
                    }
                    else {
                        $mobile = TRUE;
                        $layout = "layout_fluid.css";
                        $agent = $this->AGENT->getBrowser() . ' ' . $this->AGENT->getVersion();
                    }
                    //$debug .= "<p>Debug: " . $agent . "</p>";
                }
            }

            // Make the users fullName safe for all charsets:
            $user['fullName'] = bx_charsetsafe($user['fullName']);

            // Extra debugging output:
            if (is_array($this->extra_debug)) {
                foreach ($this->extra_debug as $key => $value) {
                    $debug .= "<p>" . $key . " - " . $value . "<br></p>";
                }
            }

            // Merge 'extra_headers' and 'ff_extra_headers':
            // Note: ff_extra_headers are generated by uifc/MultiChoice.php
            // where we (for one reason or another) cannot use $BxPage->setExtraHeaders()
            // So we use the more direct method of ...
            //  $this->BxPage->ff_extra_headers[$id] = $extraheader;
            // ... for that. Hence this work around here:
            if (isset($this->ff_extra_headers)) {
                if (is_array($this->ff_extra_headers)) {
                    $this->total_extra_headers = array_merge($this->extra_headers, $this->ff_extra_headers);
                    $this->extra_headers = $this->total_extra_headers;
                }
            }

            // Wiki Support:
            if (!isset($Support['wiki_enabled'])) {
                $Support['wiki_enabled'] = '0';
            }

            if ($Support['wiki_enabled'] == '1') {

                // HTTPS check:
                $web_url_pref = 'http';
                if (isset($_SERVER['HTTPS'])) {
                    if ($_SERVER['HTTPS'] == "on") {
                        $web_url_pref = 'https';
                    }
                }

                if ($Support['wiki_tabbed'] == '1') {
                    // Use FancyButton:
                    $this->setExtraHeaders('
                        <!-- Start: BxPage.php -->
                        <script>
                          $(document).ready(function() {
                            $(".various").fancybox({
                              overlayColor: "#000",
                              fitToView : false,
                              width   : "80%",
                              height    : "80%",
                              autoSize  : false,
                              fixed   : false,
                              closeClick  : false,
                              openEffect  : "none",
                              closeEffect : "none"
                            });
                          });
                        </script>
                        <!-- End: BxPage.php -->
                        ');

                    $wikibutton_helptext = $i18n->getWrapped("[[base-support.wiki_help]]");
                    $wiki = '<a class="various fancybox" target="_self" href="' . $web_url_pref . '://' . $Support['wiki_baseURL'] . '/userguide/' . uri_string() . '" data-fancybox-type="iframe">' . "\n";
                    $wiki .= '                            <button class="btn btn-default btn-anim" data-toggle="tooltip" data-placement="right" title="' . $wikibutton_helptext . '" data-original-title="' . $wikibutton_helptext . '" data-container="body"><i class="ti-new-window"></i><span class="btn-text">' . $i18n->getHtml("[[base-support.wiki]]") . '</span></button>';
                    $wiki .= '</a>' . "\n";
                }
                else {
                    // Use Link-Button to open in new tab:
                    $wiki = '<a target="_blank" href="' . $web_url_pref . '://' . $Support['wiki_baseURL'] . '/userguide/' . uri_string() . '">' . "\n";
                    $wiki .= '                            <button class="btn btn-default btn-anim" data-toggle="tooltip" data-placement="right" title="' . $wikibutton_helptext . '" data-original-title="' . $wikibutton_helptext . '" data-container="body"><i class="ti-new-window"></i><span class="btn-text">' . $i18n->getHtml("[[base-support.wiki]]") . '</span></button>';
                    $wiki .= '</a>' . "\n";
                }
            }
            else {
                $wiki = '&nbsp;';
            }

            // Use Elmer:

            //
            //-- MS-DEBUG NEW GUI:
            //
            //$side_html_menu = '';
            $right_sidebar_menu = '';
            // $nav_html_menu ='';

            $active_page_category = $i18n->getHtml($_SiteMap_items[$active_menu_item]['label']);
            $active_page_category_link = getURLofFirstChild($active_menu_item, $ignore_items, $_SiteMap_items, $access);

            if ($twoFactorSetupRestricted) {
                $profile_text = $i18n->getHtml("[[base-user.personalTwoFactor_menu]]");
                $profile_link = '/user/personalTwoFactor';
                $settings_link = '#';
                $nav_html_menu = '';
                $side_html_menu = $this->renderTwoFactorSetupRestrictedMenu($i18n);
                $active_page_category = $i18n->getHtml("[[base-user.personalTwoFactor_menu]]");
                $active_page_category_link = '/user/personalTwoFactor';
            }

            // Disabled vars:
            $overlay = $this->getOverlay();
            $overlay = '';
            $layout = '';

            // Elmer related variables:
            $ElmerStyle_Default_Array = array('header_color' => 'theme-6-active', 'primaryColor' => 'pimary-color-blue', 'css' => 'style.css');

            // Sense check:
            if (count($BX_SESSION['elmer_theme']) === 0) {
                $BX_SESSION['elmer_theme'] = $ElmerStyle_Default_Array;
            }

            $elmer_css = $BX_SESSION['elmer_theme']['css'];
            $elmer_active_theme = $BX_SESSION['elmer_theme']['header_color'];
            $elmer_primary_color = $BX_SESSION['elmer_theme']['primaryColor'];
            $elmer_style_css = '/.elm/dist/css/' . $elmer_css . '?refresh=' . time();
            $IP_of_webserver = $_SERVER['SERVER_ADDR'];

            if (($elmer_active_theme == 'theme-1-active') || ($elmer_active_theme == 'theme-4-active') || ($elmer_active_theme == 'theme-5-active') || ($elmer_active_theme == 'theme-7-active')) {
                $bx_logo_color = '#000000';
            }
            else {
                $bx_logo_color = '#ffffff';
            }
            // Merge error messages onto the top of the page body. But only do so if 
            // the rendered elements don't have their own location for showing them:
            if (isset($this->BXErrors)) {
                $e_cnt = count($this->BXErrors);
                if ($e_cnt >= "1") {
                    $this->BXErrors = array_map("unserialize", array_unique(array_map("serialize", $this->BXErrors)));
                    if ($this->getErrorMsgDisplayArea() == FALSE) {
                        $page_body = array_merge($this->BXErrors, $page_body);
                    }
                }
            }

            // Populate output:
            $logoutConfirm = $i18n->getHtml("[[palette.logoutConfirm]]");
            $cancel_text = $i18n->getHtml("[[palette.cancel]]");
            $Categories_text = $i18n->getHtml("[[palette.Categories]]");
            $Options_text = $i18n->getHtml("[[palette.Options]]");

            // Vsite/User Quicksearch:
            $vsite_and_user_quicksearch_menu = '';
            if ((in_array('systemAdministrator', $access)) && (in_array('adminUser', $access)) && (in_array('siteAdmin', $access))) {
                $vsite_and_user_quicksearch_dataTableInit = <<<HTML
                    <script>
                    $(document).ready(function() {
                        $('#vsites_table').DataTable({
                            "paging": true, // Turn on pagination
                            "iDisplayLength": 8,
                            "lengthChange": false, // Turn off length menu selector
                            bAutoWidth: false,
                              "aoColumns": [
                               { "sWidth": "85%" },

                               { "sWidth": "35px" }
                            ],
                            "escape": false,
                        });
                    });
                    </script>
                    <script>
                    $(document).ready(function() {
                        $('#users_table').DataTable({
                            "paging": true, // Turn on pagination
                            "iDisplayLength": 8,
                            "lengthChange": false, // Turn off length menu selector
                            bAutoWidth: false,
                              "aoColumns": [
                               { "sWidth": "85%" },

                               { "sWidth": "35px" }
                            ],
                            "escape": false,
                        });
                    });
                    </script>
                HTML;
                //$this->setExtraHeaders($vsite_and_user_quicksearch_dataTableInit);
            }

            // Disabled for now:
            $vsite_and_user_quicksearch_menu = '';
            $vsite_and_user_quicksearch_html = '';

            // Handle Active Monitor:
            $ActiveMonitorDisplay = '';
            if (in_array("serverShowActiveMonitor", $access)) {

                $AM_warncolor = 'bg-green';
                $AM_total_warnings = '0';
                if ($AM_yellow_items > '0') {
                    $AM_total_warnings += $AM_yellow_items;
                    $AM_warncolor = 'bg-yellow';
                }
                if ($AM_red_items > '0') {
                    $AM_total_warnings += $AM_red_items;
                    $AM_warncolor = 'bg-red';
                }

                $AM_Header = $i18n->getHtml("[[base-am.activeMonitor]]");

                $AM_Error_Output = '';
                $AM_Error_Output .= '    <div class="streamline message-nicescroll-bar">' . "\n";
                foreach ($ActiveMonitorErrors as $Error_NameSpace => $ErrorData) {
                    if (!isset($ErrorData['currentState'])) {
                        next;
                    }
                    if (isset($ErrorData['currentState'])) {
                        if ($ErrorData['currentState'] == 'G') {
                            next;
                        }
                    }
                    if (($Error_NameSpace == 'Email') || ($Error_NameSpace == 'OpenDKIM') || ($Error_NameSpace == 'SpamAssassin') || ($Error_NameSpace == 'CLAMAV') || ($Error_NameSpace == 'MilterGeoIP') || ($Error_NameSpace == 'AVSPAM') || ($Error_NameSpace == 'EmailTraffic')) {
                        $AM_error_icon = 'fa fa-envelope';
                    }
                    elseif ($Error_NameSpace == 'Vsite') {
                        $AM_error_icon = 'fa fa-sitemap';
                    }
                    elseif (($Error_NameSpace == 'Disk') || ($Error_NameSpace == 'DiskIntegrity') || ($Error_NameSpace == 'SMART')) {
                        $AM_error_icon = 'ti-harddrive';
                    }
                    elseif ($Error_NameSpace == 'Network') {
                        $AM_error_icon = 'ti-pulse';
                    }
                    elseif ($Error_NameSpace == 'mysql') {
                        $AM_error_icon = 'fa-database';
                    }
                    elseif ($Error_NameSpace == 'Time') {
                        $AM_error_icon = 'fa fa-clock-o';
                    }
                    elseif ($Error_NameSpace == 'CPU') {
                        $AM_error_icon = 'ti-stats-up';
                    }
                    elseif ($Error_NameSpace == 'Memory') {
                        $AM_error_icon = 'ti-stats-down';
                    }
                    elseif ($Error_NameSpace == 'Network') {
                        $AM_error_icon = 'ti-pulse';
                    }
                    elseif (($Error_NameSpace == 'base') || ($Error_NameSpace == 'Updates')) {
                        $AM_error_icon = 'ti-cloud-down';
                    }
                    elseif (($Error_NameSpace == 'fail2ban') || ($Error_NameSpace == 'firewall') || ($Error_NameSpace == 'apf')) {
                        $AM_error_icon = 'ti-alert';
                    }
                    elseif (($Error_NameSpace == 'Nginx') || ($Error_NameSpace == 'Apache') || ($Error_NameSpace == 'Admserv') || (strpos($Error_NameSpace, "FPM") === 0)) {
                        $AM_error_icon = 'ti-flag-alt-2';
                    }
                    else {
                        $AM_error_icon = 'ti-server';
                    }

                    if (($ErrorData['currentState'] == 'Y') || ($ErrorData['currentState'] == 'R')) {

                        $AM_error_icon_color = 'bg-red';
                        if ($ErrorData['currentState'] == 'Y') {
                            $AM_error_icon_color = 'bg-yellow';
                        }

                        $AM_nameTag = $i18n->getHtml($ErrorData['nameTag']);
                        $AM_Message = $i18n->getHtml($ErrorData['currentMessage']);

                        $AM_Error_Output .= "\n";

                        $AM_Error_Output .= <<<HTML
                                                            <div class="sl-item">
                                                            <a href="javascript:void(0)">
                                                                <div class="icon $AM_error_icon_color">
                                                                    <i class="$AM_error_icon"></i>
                                                                </div>
                                                                <div class="sl-content">
                                                                    <span class="inline-block capitalize-font  pull-left truncate head-notifications txt-danger">$AM_nameTag</span>
                                                                    <div class="clearfix"></div>
                                                                    <p class="">$AM_Message</p>
                                                                </div>
                                                            </a>
                                                        </div>
                                                       <hr class="light-grey-hr ma-0"/>
                        HTML;
                        $AM_Error_Output .= "\n";
                    }
                }
                $AM_Error_Output .= "\n" . '                </div><!-- End div for: message-nicescroll-bar -->' . "\n";

                $ActiveMonitorDisplay = <<<HTML
                <li class="dropdown alert-drp">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="zmdi zmdi-notifications top-nav-icon"></i><span class="top-nav-icon-badge"><span class="label label-warning $AM_warncolor">$AM_total_warnings</span></span></a>
                    <!-- Display Active Monitor -->
                    <ul class="dropdown-menu alert-dropdown" data-dropdown-in="bounceIn" data-dropdown-out="bounceOut">
                        <li>
                            <div class="notification-box-head-wrap">
                                <span class="notification-box-head pull-left inline-block">$AM_Header</span>
                                <div class="clearfix"></div>
                                <hr class="light-grey-hr ma-0"/>
                            </div>
                        </li>
                        <li>
                            <!-- AM Messages -->
                            $AM_Error_Output
                            <!-- /AM Messages -->
                        </li>
                        <li>
                            <div class="notification-box-bottom-wrap">
                                <hr class="light-grey-hr ma-0"/>
                                <a class="block text-center read-all" href="/am/amStatus">$AM_Header</a>
                                <div class="clearfix"></div>
                            </div>
                        </li>
                    </ul>
                    <!-- /Display Active Monitor -->
                </li>
                HTML;

            }

            //
            //-- Daemon.pm Icon Spinner (shows pending transactions)
            //

            $daemon_spinner_element = '';

            // Only show this when we're not overriding the default page style:
            if (!isset($this->style_override) && !$twoFactorSetupRestricted) {

                $saving_wait_txt = $i18n->get('[[base-alpine.saving_text]]');
                $please_wait_txt = $i18n->get("[[base-alpine.service_restart_in_progress]]");
                $yum_dnf_wait_txt = $i18n->get('[[base-yum.yum_is_pulling_updates]]');

                $daemon_spinner_element =<<<HTML
                    <li id="bxSavingNav" style="display:none;">
                        <a href="javascript:void(0);" data-toggle="tooltip"
                           data-placement="bottom"
                           title="$saving_wait_txt"
                           data-container="body">
                            <i class="fa fa-circle-o-notch fa-spin top-nav-icon"></i>
                        </a>
                    </li>
                    <li>
                        <a name="DaemonSpin" id="daemon_spin_sidebar" href="javascript:void(0);" data-toggle="tooltip" data-placement="bottom" title="" data-original-title="" data-container="body"><i class="DAEMON_SPIN_ICON top-nav-icon"></i></a>
                    </li>

                    <script>
                        $(document).ready(function(){
                            // Initialize tooltip formatting for DaemonSpin:
                            $('[name="DaemonSpin"]').tooltip({
                                html: true
                            });
                        });
                    </script>

                HTML;

                $daemon_spinner_script =<<<HTML
                    <!-- Start: Daemon Spinner Scripts -->
                    <script>
                        $(document).ready(function(){
                            // Initialize tooltip formatting for DaemonSpin:
                            $('[name="DaemonSpin"]').tooltip({
                                html: true
                            });
                        });
                    </script>

                    <script>
                        $(document).ready(function(){
                            // Function to fetch and process data
                            function fetchData() {
                                $.ajax({
                                    url: '/gui/services?q=services',
                                    method: 'GET',
                                    dataType: 'json',
                                    success: function(data) {
                                        if (data.file_count > 0) {
                                            // Update the icon class
                                            $('#daemon_spin_sidebar i').addClass('fa fa-spin fa-spinner');

                                            // Prepare the tooltip text
                                            let tooltipText = "";
                                            data.event_files.forEach(function(service) {
                                                if (service === 'dnf' || service === 'yum') {
                                                    tooltipText = "$yum_dnf_wait_txt<br>";
                                                }
                                                else {
                                                    tooltipText = "$please_wait_txt<br>";
                                                    tooltipText += service + "<br>";
                                                }
                                            });

                                            // Update the tooltip
                                            $('#daemon_spin_sidebar').attr('title', tooltipText).tooltip('fixTitle').tooltip('show');
                                        } else {
                                            // Remove the icon class
                                            $('#daemon_spin_sidebar i').removeClass('fa fa-spin fa-spinner');

                                            // Clear the tooltip
                                            $('#daemon_spin_sidebar').attr('title', '').tooltip('fixTitle').tooltip('hide');
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        console.error('Error fetching data:', status, error);
                                    }
                                });
                            }

                            // Fetch data initially
                            fetchData();

                            // Fetch data every 5 seconds
                            setInterval(fetchData, 5000);
                        });
                    </script>
                    <!-- End: Daemon Spinner Scripts -->
                HTML;

                $this->setExtraFooters($daemon_spinner_script);
            }

            //
            //-- Out with the page:
            //

            $page_variables = array(
                'charset' => $charset,
                'localization' => $localization,
                'loginName' => $user['name'],
                'sessionId' => $sessionId,
                'page_title' => $page_title,
                'fullName' => $user['fullName'],
                'layout' => $layout,
                'page_title' => $page_title,
                'extra_headers' => implode("\n", $this->extra_headers),
                'extra_footers' => implode("\n", $this->extra_footers),
                'body_open_tag' => $this->body_open_tag,
                'profile_text' => $profile_text,
                'profile_link' => $profile_link,
                'settings_text' => $settings_text,
                'settings_link' => $settings_link,
                'logout_text' => $logout_text,
                'logout_link' => $logout_link,
                'nav_html_menu' => $nav_html_menu,
                'side_html_menu' => $side_html_menu,
                'active_top_menu' => $active_top_menu,
                'active_side_menu' => $active_side_menu,
                'active_page_title' => $active_page_title,
                'active_page_help' => $active_page_help,
                'overlay' => $overlay,
                'right_sidebar_menu' => $right_sidebar_menu,
                'active_page_category' => $active_page_category,
                'active_page_category_link' => $active_page_category_link,
                'debug' => $debug,
                'loginName' => $loginName,
                'page_body' => implode("", $page_body),
                'logout_text' => $logout_text,
                'logoutConfirm' => $logoutConfirm,
                'page_title' => $page_title,
                'cancel_text' => $cancel_text,
                'wiki' => $wiki,
                'page_render_part_one' => $i18n->getHtml("[[palette.page_render_part_one]]"),
                'page_render_part_two' => $i18n->getHtml("[[palette.page_render_part_two]]"),
                'elmer_style_css' => $elmer_style_css,
                'elmer_active_theme' => $elmer_active_theme,
                'elmer_primary_color' => $elmer_primary_color,
                'bx_logo_color' => $bx_logo_color,
                'IP_of_webserver' => $IP_of_webserver,
                'vsite_and_user_quicksearch_menu' => $vsite_and_user_quicksearch_menu,
                'vsite_and_user_quicksearch_html' => $vsite_and_user_quicksearch_html,
                'ActiveMonitorDisplay' => $ActiveMonitorDisplay,
                'Categories_text' => $Categories_text,
                'Options_text' => $Options_text,
                'daemon_spinner_element' => $daemon_spinner_element,
            );

            // Publish page:
            if (isset($this->style_override)) {
                if ($this->style_override === 'WIZARD') {
                    // Use alternate Style for Wizard:
                    return view('../../Modules/Base/Wizard/Views/wizard_view_elmer_proper', $page_variables);
                }
                elseif ($this->style_override === TRUE) {
                    // Display using the alternate theme without menu ballast:
                    return view('../../Modules/Base/Gui/Views/elmer_clean_view', $page_variables);
                    //return view('../../Modules/Base/Gui/Views/elmer_simplified_view', $page_variables);
                }
                else {
                    // Use the even simpler theme without header and footer:
                    return view('../../Modules/Base/Gui/Views/elmer_clean_view', $page_variables);
                }
            }
            else {
                // Display using the usual BlueOnyx theming:
                return view('../../Modules/Base/Gui/Views/elmer_full_view', $page_variables);
            }
        }
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
