<?php
/**
 * CCE.php
 *
 * BlueOnyx PHP connector for interfacing Chorizo directly via UNIX Socket with CCEd.
 *
 * Description:
 * ============
 *
 * So far BlueOnyx (and the predecessors BlueQuartz and the RaQ550) used a loadable PHP
 * Zend API module to interface PHP with CCEd. This was done for obvious performance reasons
 * on the really limited (ancient) hardware. The drawback of this is naturally that the
 * PHP module must be compatible to the PHP version that AdmServ uses and it must be compiled
 * against said PHP version. Upgrading PHP then (naturally) was out of the question as it
 * would have required a recompile of the 'cce.so' module again.
 *
 * Now with PHP-5.4 (and later) we have the problem that the Zend API for modules has changed.
 * The current code of the cce.so module is no longer compatible and (as is) this is a bit
 * beyond our limited capabilities of fixing.
 *
 * Steven Howes asked the right question on the BlueOnyx developer list:
 *
 * "I wonder ... is there any reason we can’t communicate with CCE in pure PHP?"
 *
 * Well, actually we can. So I modified CceClient.php to check if the cce.so module is loaded
 * and available. If it is, we use it (for performance reasons). If it is not available, then
 * CceClient.php will load CCE.php and will use the functions within to communicate via PHP
 * over the Unix socket of CCEd.
 *
 * This class here simply mimicks all ccephp_*() functions that the cce.so PHP Zend API module
 * would normally provide. But instead of calling the module functions we use the PHP commands
 * stream_socket_client(), fwrite() and stream_get_contents() to connect to the Unix socket of
 * CCEd, to send our commands and to listen for the response.
 *
 * There are several catches:
 *
 * - This is 3-4 times slower than doing it via cce.so
 * - This is more complicated because the stream socket functions require us to do all our
 *   magick before we send the BYE to CCEd. Only when we send the BYE we actually get any
 *   response from CCEd that tell us if the transaction(s) went through or not. But by always
 *   sending a BYE we also make sure that no CCEd child processes linger around if a GUI page
 *   forgets to do so. Which certainly is a bonus.
 * - Another bonus is that for the first time ever it is now possible to upgrade PHP on
 *   BlueOnyx a hell of a lot easier. We will still actually discourage to do so, because we
 *   can only support the GUI on PHP versions that we actually tested it under.
 *
 * This code might still need some work and it sure needs a hell of a lot of testing.
 *
 * @package   CCE
 * @author    Michael Stauber
 * @link      http://www.blueonyx.it
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   2.0
 */

class CCE {
    var $socketPath;
    var $errorno;
    var $errorstr;
    var $CODB;
    var $self;
    var $DTS;
    var $DelayedTransactions;

    // Username:
    public $Username;

    // SessionId:
    public $SessionId;

    // Password:
    public $Password;

    // ERRORS:
    public $ERRORS;

    function __construct() {
        $this->ERRORS = array();
    }

    // DTS state: Keep a record of the delayed transactions
    // for later execution:
    function addDelayedTransaction($transaction) {
        if (!is_array($this->DelayedTransactions)) {
            $this->DelayedTransactions = array();
        }
        $this->DelayedTransactions[] = $transaction;
    }

    // DTS state: Return an array of all stored delayed transactions:
    function getDelayedTransaction() {
        if (isset($this->DelayedTransactions)) {
            // We have delayed transactions. Return them:
            return $this->DelayedTransactions;
        }
        else {
            // We don't have delayed transactions.
            // Return empty array to play it safe.
            return array();
        }
    }

    // Change DTS state:
    function setDTS($bgn) {
        $this->DTS = $bgn;
    }

    // Get DTS state:
    function getDTS() {
        // Define the three states of DTS:
        $beginStates = array(
            "TRUE",
            "FALSE",
            "COMMIT"
        );
        // If DTS is not set, DTS is in default state
        // and transactions are NOT delayed.
        if (!isset($this->DTS)) {
            // Return default:
            $this->DTS = "FALSE";
        }
        // Check if the current state is one of the defined (possible)
        // states. If so, return the current state:
        if (in_array($this->DTS, $beginStates)) {
            return $this->DTS;
        }
        else {
            // State is not one of the three default states.
            // So return the default state just to play it safe:
            return "FALSE";
        }
    }

    // Set path to the Unix socket of CCEd:
    function setSocketPath($socketPath) {
        $this->socketPath = $socketPath;
    }

    // Get path to the Unix socket of CCEd:
    function getSocketPath() {
        if (!isset($this->socketPath)) {
            $system = new System();
            $this->socketPath = $system->getConfig("ccedSocketPath");
        }
        return $this->socketPath;
    }

    function setERRNO($errorno) {
        $this->errorno = $errorno;
    }

    function getERRNO() {
        if (isset($this->errorno)) {
            return $this->errorno;
        }
        else {
            return '';
        }
    }

    function setERRSTR($errorstr) {
        $this->errorstr = $errorstr;
    }

    function getERRSTR() {
        if (isset($this->errorstr)) {
            return $this->errorstr;
        }
        else {
            return '';
        }
    }

    // Initialize CCE:
    //function ccephp_new($message = "WHOAMI") {
    function ccephp_new($message = '') {
        // Note on $message:
        //
        // If no $message is set, we default on sending a "WHOAMI". The simple
        // reason is that on each connect we need to execute at least one
        // successful command to set self[success] to TRUE to determine if CCEd
        // is working or not.
        //
        // Actually: Screw that! If we have no $message, we have success!
        if ($message == '') {
            $this->CODB['success'] = '1';
            return true;
        }

        // DTS state:
        // Start sane and check if we are in delayed transaction state (DTS).
        // For more info on that see ccephp_begin() and ccephp_commit()
        // Get DTS state:
        $this->DTS = CCE::getDTS();

        // Get all delayed transactions (if there are any):
        $this->DelayedTransactions = CCE::getDelayedTransaction();

        // Initialize Socket:
        $socketPath = CCE::getSocketPath();

        $this->CODB['rdsock'] = $socketPath;
        $this->CODB['wrsock'] = $socketPath;
        $this->CODB['version'] = '';
        $this->CODB['suspendedmsg'] = '';
        $this->CODB['debug'] = '0';
        $this->CODB['rollbackflag'] = '0';
        $this->CODB['event'] = '';
        $this->CODB['event_oid'] = '';
        $this->CODB['event_namespace'] = '';
        $this->CODB['opt_namespace'] = '';
        $this->CODB['event_property'] = '';
        $this->CODB['event_class'] = '';
        $this->CODB['event_object'] = '';
        $this->CODB['event_old'] = '';
        $this->CODB['event_new'] = '';
        $this->CODB['event_create'] = '0';
        $this->CODB['event_destroy'] = '0';
        $this->CODB['msgref'] = '';
        $this->CODB['domain'] = '';

        if ($this->DTS == "FALSE") {
            // We are *not* in delayed transaction mode.
            // Execute the commands directly:
            $result = CCE::_cceclient($message);
        }
        elseif ($this->DTS == "COMMIT") {
            // Delayed transaction state has reached the stage where we
            // want to commit the entire set of stored transactions:
            // Add the final "COMMIT" to our array of delayed transactions:
            CCE::addDelayedTransaction("COMMIT");

            // Combine the stored delayed transactions into a single call to CCE:
            if (isset($this->DelayedTransactions)) {
                $combinedMessage = "";
                foreach ($this->DelayedTransactions as $num => $message) {
                    $combinedMessage .= $message . "\n";
                }
                // Push out the combined delayed transactions in one go:
                $result = CCE::_cceclient($combinedMessage);
            }
            // Leave delayed transaction state:
            CCE::setDTS("FALSE");

        }
        else {
            // We are in delayed transaction state. We do not execute queries
            // directly. Instead we wait for the COMMIT and just store the
            // delayed transactions away for later execution:
            CCE::addDelayedTransaction($message);
        }

        if (isset($this->CODB['success'])) {
            if ($this->CODB['success'] == "1") {
                // Ok, this is somewhat strange. Maybe I'm shooting myself in the
                // foot here: On a "SET <SYSTEM> . Time" transaction the partial
                // epoch ends up in the ['info']. This raises an Error object, but
                // an incomplete one. A successful transaction doesn't have an
                // ['info'] set anyway. So if the transaction IS a success, we can
                // (in theory) wipe the ['info'] field clean. So let's do that here:
                $this->CODB['info'] = '';
                return true;
            }
            return false;
        }
        else {
            return false;
        }
    }

    // Connect to CCE:
    function ccephp_connect($socketPath) {
        // Set socketPath:
        CCE::setSocketPath($socketPath);
        return CCE::ccephp_new();
    }

    // Disconnect from CCE:
    function ccephp_bye() {
        // We do a "BYE" on every connection. So there is really no need
        // to send a second "BYE" separately. Hence we just return TRUE
        // and be done with it instead of: return CCE::ccephp_new("BYE");
        return true;
    }

    // Invalidate SessionID:
    function ccephp_endkey() {
        // Here is the catch:
        // Issuing 'ENDKEY' to CCEd does not (as advertised) invalidate
        // the sessionId. We actually have to manually delete the cookie
        // as well. Or we run into a nice "AUTHKEY failed" loop.
        // BaseController now does that for us:
        //$this->setSessionId('');
        $this->SessionId = '';
        return CCE::ccephp_new("ENDKEY");
    }

    // the legacy find command
    // returns: matching $oids
    // usage: $oids = $cce->find($class, array( 'property' => 'value'));
    function ccephp_find($class, $vars, $key = "", $crit = "0") {
        if ($vars == "") {
            CCE::ccephp_new("FIND $class");
        }
        else {
            $varline = " ";
            foreach ($vars as $key => $value) {
                $varline .= "$key = \"" . CCE::_escape($value) . "\" ";
            }
            $varline = rtrim($varline);
            CCE::ccephp_new("FIND $class $varline");
        }
        return $this->CODB['oidlist'];
    }

    // Description: advanced method of finding objects
    // $class : class to find
    // $vars : exact-match criteria
    // $revars : regex-match criteria
    // $sorttype : name of sorttype to use (optional)
    //           : listed in basetypes.schema, valid types are
    //           : ascii, old_numeric, locale, ip, hostname
    // $sortprop : name of property (key) on which to sort
    // returns: matching $oids
    // usage: $oids = $cce->findx($class, $vars, $regex_vars, $sorttype, $sortkey);
    function ccephp_findx($class, $vars, $revars, $sorttype, $sortprop) {
        // Make sorting work
        $sortData = "";
        if (($sorttype != "") && ($sortprop != "")) {
            $sortData = " sorttype $sorttype sortprop $sortprop";
        }

        // Check for vars
        if (($vars == "") && ($revars == "")) {
            // Simple search for $class
            CCE::ccephp_new("FIND $class" . $sortData);
        }
        elseif (($vars != "") && ($revars == "")) {
            // Simple search for $class with $vars:
            $varline = ' ';
            foreach ($vars as $key => $value) {
                $varline .= "$key = \"" . CCE::_escape($value) . "\" ";
            }
            $varline = rtrim($varline);
            CCE::ccephp_new("FIND $class " . $varline . " " . $sortData);
        }
        elseif (($vars == "") && ($revars != "")) {
            // Complex search for $class with regex-match criteria in $revars:
            //
            // Do a simple search for all members of this $class first:
            CCE::ccephp_new("FIND $class " . $sortData);
        }
        else {
            $varline = " ";
            foreach ($vars as $key => $value) {
                $varline .= "$key = \"" . CCE::_escape($value) . "\" ";
            }
            $varline = rtrim($varline);
            CCE::ccephp_new("FIND $class $varline " . $sortData);
        }
        return $this->CODB['oidlist'];
    }

    // Get the object:
    function ccephp_get($oid, $namespace) {
        if (is_array($oid)) {
            // IF $oid is an array we only process the first element:
            if (isset($oid[0])) {
                $oid = $oid[0];
            }
            else {
                // First element is not set, return "-1":
                return "-1";
            }
        }
        if (($oid == "") || (!is_string($oid))) {
            // If OID is empty or not a string, then
            // something went wrong and we just return
            // a -1 to indicate a failure.
            return "-1";
        }
        if ($namespace == "") {
            CCE::ccephp_new("GET $oid");
        }
        else {
            CCE::ccephp_new("GET $oid . $namespace");
        }
        if (is_array($this->CODB['object'])) {
            return $this->CODB['object'];
        }
        else {
            // Cheating: The returned object is not an array?
            // In that case we return "-1" just as the cce.so PHP module would do.
            // This is apparently done so that a referenced non-found key in the
            // called object doesn't trigger an 'Uninitialized string offset' error.
            // I'd rather return NULL or FAIL, but we have to remain compatible.
            return "-1";
        }
    }

    // Auth:
    function ccephp_auth($userName, $password) {

        if (($userName == '') || ($password == '')) {
            bx_error_log("CCE.php: NOT running ccephp_auth() with userName and/or password empty!");
            $this->setUsername($userName);
            $this->setSessionId('');
            return '';
        }

        // The password must be escaped and the only valid char for that is a double quote.
        // CCEd will not accept single quoted values:
        CCE::ccephp_new("AUTH \"" . CCE::_escape($userName) . "\" \"" . CCE::_escape($password) . "\"");
        if ($this->CODB['sessionid'] != "") {
            $this->setUsername($userName);
            $this->setSessionId($this->CODB['sessionid']);
        }
        return $this->CODB['sessionid'];
    }

    // Authkey:
    function ccephp_authkey($userName, $sessionId) {

        // Fast return in case we don't have a username or sessionId:
        if (($userName == '') || ($sessionId == '')) {
            bx_error_log("CCE.php: NOT running ccephp_authkey() with userName and/or sessionId empty!");
            $this->SessionId = '';
            return $this->SessionId = '';
        }

        CCE::ccephp_new("AUTHKEY \"" . CCE::_escape($userName) . "\" $sessionId");
        if ($this->CODB['success'] == '1') {
            $this->setSessionId($sessionId);
            $this->SessionId = $sessionId;
            $this->CODB['sessionid'] = $sessionId;
        }
        else {
            $this->CODB['sessionid'] = '';
            $this->setSessionId('');
            $this->SessionId = '';
        }
        return $this->CODB['sessionid'];
    }

    // WHOAMI
    function ccephp_whoami() {
        CCE::ccephp_new("WHOAMI");
        if (isset($this->CODB['oidlist'][0])) {
            return $this->CODB['oidlist'][0];
        }
        return NULL;
    }

    // NAMES
    function ccephp_names($args = "") {
        if ($args == "") {
            CCE::ccephp_new("NAMES");
        }
        else {
            CCE::ccephp_new("NAMES $args");
        }
        return $this->CODB['namelist'];
    }

    // description: set object properties in CCE
    // returns: boolean true for success, boolean false for failure
    // usage: $ok = $cce->set($oid, $namespace, array( 'property' => 'value'));
    function ccephp_set($oid, $namespace, $vars) {
        $this->OID = $oid;
        $snd_line = "SET $oid";
        if ($namespace != "") {
            $snd_line .= " . " . $namespace;
        }

        $varline = " ";
        foreach ($vars as $key => $value) {
            $varline .= "$key = \"" . CCE::_escape($value) . "\" ";
        }
        $varline = rtrim($varline);
        CCE::ccephp_new($snd_line . " " . $varline);
        $this->OID = '';
        return $this->CODB['success'];
    }

    // description: set CCE read-only.
    // Requires: systemAdministrator access
    // param: reason: reason for CCE being read-only
    // returns: true is successful
    function ccephp_suspend($reason = '') {
        if ($reason != '') {
            $reason = '"' . CCE::_escape($reason) . '"';
        }

        // Perform the transaction:
        CCE::ccephp_new("ADMIN SUSPEND " . $reason);

        // Return result:
        return $this->CODB['success'];
    }

    // description: set CCE read-write after a call to suspend().
    // Requires: systemAdministrator access
    // returns: true is successful
    function ccephp_resume() {

        // Perform the transaction:
        CCE::ccephp_new("ADMIN RESUME");

        // Return result:
        return $this->CODB['success'];
    }

    //@@@@@@@@@@@@@
    // IMPORTANT!
    //@@@@@@@@@@@@@
    //
    // ccephp_begin() and ccephp_commit() start and end delayed trans state (DTS).
    //
    // Which has three states:
    //
    // FALSE:  DTS disabled. Execute commands directly. This is the default.
    // TRUE:   DTS entered.  Do not execute commands directly. Record them and wait for COMMIT.
    // COMMIT: DTS ending.   Execute all stored commands and once done reset to FALSE.
    //
    // DTS basically allows us to run a whole heap of commands, which might or might not conflict
    // with each others or which might cause multiple handler runs. Instead we issue ...
    // BEGIN, then all commands and then COMMIT. The handlers are only run after COMMIT has been
    // issued and therefore will not run more than once. This can be a time saviour, but we don't
    // really use this often enough in the GUI yet.
    // description: begin delayed-handler mode
    // Also known as delayed transaction state (DTS)
    function ccephp_begin() {
        // Enter delayed transaction state:
        CCE::setDTS("TRUE");
        // Issue the BEGIN transaction that tells CCEd to hold its horses on the handlers:
        CCE::ccephp_new("BEGIN");
        // Return TRUE as we have no way of knowing if this failed or not:
        return true;
    }

    // description: trigger all handlers to run since begin() call
    // returns: a success code based on the success or failure
    function ccephp_commit() {
        // Leave delayed transaction state:
        CCE::setDTS("COMMIT");
        // Call cceclient and tell it to COMMIT the changes:
        CCE::ccephp_new("COMMIT");
        // Return result - with a catch: This only shows the success/failure of the final transaction.
        return $this->CODB['success'];
    }

    // description: determine if CCE is suspended or not
    // returns: reason string if suspended, false otherwise
    function ccephp_suspended() {
        // Bit of a cheating:
        // System var 'productVendor' is usually unused. We set it to blank and see
        // if we get a 'suspendedmsg':
        CceClient::setObject("System", array(
            'productVendor' => ''
        ));
        return $this->CODB['suspendedmsg'];
    }

    // description: create a CCE object of type $class, with properties in $vars
    // returns: oid of created object, or 0 on failure
    // usage: $oid = $cce->create($class, array( 'property' => 'value' ));
    function ccephp_create($class, $vars = array()) {

        // Cleanup $vars:
        $varline = " ";
        foreach ($vars as $key => $value) {
            $varline .= "$key = \"" . CCE::_escape($value) . "\" ";
        }
        $varline = rtrim($varline);

        // Do it:
        CCE::ccephp_new("CREATE $class " . $varline);

        // Parse response:
        if ($this->CODB['success'] == '1') {
            // Return OID of the new Object:
            if (!isset($this->CODB['oidlist']['0'])) {
                // Return '0' to indicate that we failed:
                return '0';
            }
            return $this->CODB['oidlist']['0'];
        }
        else {
            // Return '0' to indicate that we failed:
            return '0';
        }
    }

    // description: destroy the CCE object with oid $oid
    // returns: boolean true for success, false for failure
    // usage: $ok = $cce->destroy($oid);
    function ccephp_destroy($oid) {
        if (!$oid) {
            // No OID given? Nothing to destroy:
            return false;
        }
        // Do it:
        CCE::ccephp_new("DESTROY $oid");
        // Return the result:
        if ($this->CODB['success'] == "1") {
            return true;
        }
        else {
            return false;
        }
    }

    // description: determines if the current session is a handler in rollback mode.
    function ccephp_is_rollback() {
        return $this->CODB['rollbackflag'];
    }

    function setUsername($Username = "") {
        $this->Username = $Username;
        $CI = & get_instance();
        $CI->setBX_SESSION_loginName($Username);
    }

    function getUsername() {

        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        if ((!isset($this->Username)) && (isset($BX_SESSION['loginName']))) {
            $cookie_loginName = $BX_SESSION['loginName'];
            if (isset($cookie_loginName)) {
                $this->Username = $BX_SESSION['loginName'];
            }
            else {
                $this->Username = $BX_SESSION['loginName'];
            }
        }
        return $this->Username;
    }

    function setSessionId($SessionId = "") {
        $this->SessionId = $SessionId;
        $CI = & get_instance();
        $CI->setBX_SESSION_sessionId($SessionId);
    }

    function getSessionId() {
        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        if (!isset($this->SessionId)) {
            $this->SessionId = $BX_SESSION['sessionId'];
        }
        return $this->SessionId;
    }

    function setPassword($Password = "") {
        $this->Password = $Password;
    }

    function getPassword() {
        return $this->Password;
    }

    function _cceclient($command = "") {

        $this->Username = $this->getUsername();
        $this->SessionId = $this->getSessionId();
        $this->Password = $this->getPassword();

        // Setup $this->CODB with basic stuff:
        CCE::flushmsgs();

        // Initialize Socket - use the one from the config file:
        $system = new System();
        $socketPath = $system->getConfig("ccedSocketPath");

        // Timeout for CCEd (15 seconds):
        $timeout = 15;
        $max_wait_time = 30; // seconds
        $attempt_start = time();
        $socket = false;

        // Retry loop:
        while ((time() - $attempt_start) < $max_wait_time) {
            $socket = @stream_socket_client('unix://' . $socketPath, $errorno, $errorstr, $timeout);

            if ($socket !== false && is_resource($socket)) {
                // Success
                break;
            }

            // Optional logging:
            bx_error_log("CCE.php: Failed to connect to socket ($errorno): $errorstr. Retrying ...");

            // Sleep before retry (500ms in this case):
            usleep(500000);
        }

        // Final check:
        if (!$socket || !is_resource($socket)) {
            $this->setERRNO($errorno);
            $this->setERRSTR($errorstr);
            bx_error_log("CCE.php: Giving up after $max_wait_time seconds: $errorno - $errorstr");
            return false;
        }

        // Set timeout:
        @stream_set_timeout($socket, $timeout);

        if (is_bool($socket)) {
            return false;
        }

        // If we have Username and Password we use AUTH:
        if ((isset($this->Username)) && (isset($this->Password))) {
            if (($this->Username != '') && ($this->Password != '')) {
                fwrite($socket, "AUTH \"$this->Username\" \"" . $this->Password . "\"\n");
            }
        }
        elseif ((isset($this->Username)) && (isset($this->SessionId))) {
            // If we have Username and SessionId we use AUTHKEY instead:
            if (($this->Username != '') && ($this->SessionId != '')) {
                fwrite($socket, "AUTHKEY \"$this->Username\" $this->SessionId\n");
            }
        }
        else {
            // No Auth at this time.
            
        }
        if ($command != "") { // Only issue commands to CCEd if we have something to say.
            // Issue the command to the Unix Socket:
            fwrite($socket, $command . "\n");
        }

        // Adios y hasta luego!
        fwrite($socket, "BYE\n");

        // Get CCEd's collective responses to our transactions:
        $result = CCE::_parse_response(stream_get_contents($socket));

        if (is_file("/etc/DEBUG")) {
            $dsp_err = json_encode($result);
            //bx_error_log("CMD: $command <--> Response: " . $dsp_err);
            bx_error_log("Response: " . $dsp_err);
        }

        if (isset($errorno)) {
            if ($errorno != "0") {
                // Store Socket-Errors:
                CCE::setERRNO($errorno);
                CCE::setERRSTR($errorstr);
            }
        }

        // If we do not have a 'sessionid' reported from the last connection
        // attempt, then we delete the 'sessionid' Cookie and internal vars
        // related to the SessionID. This is needed on logouts or attempted
        // connections after a timeout. Otherwise we'd see repeated attempts
        // to use AUTHKEY, which simply take too long to finish due to the
        // involved delays of the PAM module. So this speeds up things. And
        // BaseController is doing the cookie deletion for us now:
        if ($this->CODB['sessionid'] == '') {
            $this->setSessionId('');
            $this->SessionId = '';
        }
        return $result;
    }

    function flushmsgs() {
        $this->CODB['success'] = '0';
        $this->CODB['perm'] = '1';
        $this->CODB['object'] = '';
        $this->CODB['old'] = '';
        $this->CODB['new'] = '';
        $this->CODB['baddata'] = '';
        $this->CODB['info'] = '';
        $this->CODB['oidlist'] = array();
        $this->CODB['namelist'] = array();
        $this->CODB['createflag'] = '0';
        $this->CODB['destroyflag'] = '0';
        $SID = $this->getSessionId();
        if ($SID == "") {
            $this->CODB['sessionid'] = '';
        }
        else {
            $this->CODB['sessionid'] = $SID;
        }
        $this->CODB['classlist'] = array();
    }

    function _parse_response($result) {

        // Start sane:
        CCE::flushmsgs();

        // Explode by newline:
        $resultArr = preg_split("/\\r\\n|\\r|\\n/", $result);

        // Parse line by line:
        foreach ($resultArr as $key => $line) {
            //if (m/^100 CSCP\/(\S+)/) { $self->{version} = $1; next; }
            if (preg_match('/^100 CSCP\/(\S+)/', $line, $matches)) {
                if (isset($matches[1])) {
                    $this->CODB['version'] = $matches[1];
                }
            }

            if (preg_match('/^200 READY$/', $line, $matches)) {
                continue;
            }
            if (preg_match('/^202 GOODBYE$/', $line, $matches)) {
                continue;
            }

            if (preg_match('/^101/', $line, $matches)) {
                $this->CODB['event'] = 'unknown';
                $this->CODB['event_oid'] = '0';
                $this->CODB['event_namespace'] = '';
                $this->CODB['event_property'] = '';
                // FIXME: this needs to handle multiple header EVENTs
                if (preg_match('/EVENT\s+(\d+)\s*\.\s*(\w*)\s*\.\s*(\w*)/', $line, $matches)) {
                    $this->CODB['event_oid'] = $matches[1];
                    $this->CODB['event_namespace'] = $matches[2];
                    $this->CODB['event_property'] = $matches[3];
                }
                elseif (preg_match('/EVENT\s+(\d+)\s*\.(\w*)/', $line, $matches)) {
                    $this->CODB['event_oid'] = $matches[1];
                    $this->CODB['event_property'] = $matches[2];
                }
            }

            if (preg_match('/^102 \S+ (.*?)\s*=\s*(.*)/', $line, $matches)) {
                if ((isset($matches[1])) && (isset($matches[2]))) {
                    $key = $matches[1];
                    $val = $matches[2];
                    if (is_array($this->CODB['old'])) {
                        $old_array = $this->CODB['old'];
                        $this->CODB['old'] = array_merge($old_array, array(
                            $key => CCE::unescape($val)
                        ));
                    }
                    else {
                        $this->CODB['old'] = array(
                            $key => CCE::unescape($val)
                        );
                    }
                }
            }

            if (preg_match('/^103 \S+ (.*?)\s*=\s*(.*)/', $line, $matches)) {
                if ((isset($matches[1])) && (isset($matches[2]))) {
                    $key = $matches[1];
                    $val = $matches[2];
                    if (is_array($this->CODB['new'])) {
                        $old_array = $this->CODB['new'];
                        $this->CODB['new'] = array_merge($old_array, array(
                            $key => CCE::unescape($val)
                        ));
                    }
                    else {
                        $this->CODB['new'] = array(
                            $key => CCE::unescape($val)
                        );
                    }

                }
            }

            // Handle FIND:
            if (preg_match('/^104 OBJECT (\d+)/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1]))) {
                    $this->CODB['oidlist'][] = $matches[1];
                }
            }

            if (preg_match('/^105 NAMESPACE (\S+)/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1]))) {
                    $this->CODB['namelist'][] = $matches[1];
                }
            }

            if (preg_match('/^106 INFO (.*)/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1]))) {
                    $this->CODB['info'][] = $matches[1];
                }
            }

            if (preg_match('/^107 CREATED/', $line, $matches)) {
                $this->CODB['createflag'] = '1';
            }

            if (preg_match('/^108 DESTROYED/', $line, $matches)) {
                $this->CODB['destroyflag'] = '1';
            }

            // SESSIONID:
            if (preg_match('/^109 SESSIONID (\S+)/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1]))) {
                    $this->CODB['sessionid'] = $matches[1];
                    $this->setSessionId($this->CODB['sessionid']);
                }
            }

            // CLASSLIST:
            if (preg_match('/^110 CLASS (\S+)/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1]))) {
                    $this->CODB['classlist'][] = $matches[1];
                }
            }

            // ROLLBACK:
            if (preg_match('/^111 ROLLBACK$/', $line, $matches)) {
                $this->CODB['rollbackflag'] = '1';
            }

            // INFO/ERROR:
            if (preg_match('/^301 UNKNOWN CLASS\s(.*)$/', $line, $matches)) {
                if (isset($matches[1])) {
                    $this->CODB['info'] = $matches[0];
                    CCE::setError($matches[0], "", "", $matches[0]);
                    continue;
                }
            }

            // 306 ERROR COMMAND PARSE ERROR:
            if (preg_match('/^306 ERROR\s(.*)$/', $line, $matches)) {
                if (isset($matches[1])) {
                    if (!isset($this->OID)) {
                        $this->OID = '306';
                    }
                    if (!isset($this->CODB['info'][$this->OID])) {
                        $this->CODB['info'] = array($this->OID => $matches[1]);
                    }

                    $this->CODB['info'][$this->OID] = $matches[1];
                    CCE::setError($matches[0], $this->OID, "", $matches[1]);
                    continue;
                }
            }

            // BAD DATA:
            // Example:
            // 302 BAD DATA 3 isLicenseAccepted "[[base-cce.invalidData]]"
            if (preg_match('/^302 BAD DATA\s+(\d+)\s+(\S+)\s*(.*)?/', $line, $matches)) {
                if ((isset($matches[0])) && (isset($matches[1])) && (isset($matches[2]))) {
                    $oid = $matches[1];
                    $key = $matches[2];
                    if (isset($matches[3])) {
                        $msg = $matches[3];
                    }
                    else {
                        $msg = "unknown-error";
                    }
                    if (!isset($this->CODB['baddata'])) {
                        $this->CODB = array(
                            'baddata' => true
                        );
                    }
                    if (!isset($this->CODB['baddata'][$oid])) {
                        $this->CODB['baddata'] = array(
                            $oid => array(
                                $key => $msg
                            )
                        );
                    }
                    $this->CODB['baddata'][$oid][$key] = $msg;
                    CCE::setError("302 BAD DATA", $oid, $key, $msg);
                    continue;
                }
            }

            if (preg_match('/^305 WARN\s(.*)$/', $line, $matches)) {
                $errMsg = preg_replace('/"/', '', $matches[1]);
                if (isset($matches[1])) {
                    if (!isset($this->OID)) {
                        $this->OID = '0';
                    }
                    if ($this->OID == '') {
                        $this->OID = '0';
                    }
                    $this->CODB['info'] = array(
                        $this->OID => $errMsg
                    );
                    CCE::setError('305 WARN', $this->OID, "", $errMsg);
                    continue;
                }
            }

            if (preg_match('/30([0-1][3-7])(.*)/', $line, $matches)) {
                if (isset($matches[1])) {
                    if (!isset($this->OID)) {
                        $this->OID = '0';
                    }
                    if ($this->OID) {
                        $this->CODB['info'][$this->OID] = $matches[0];
                        CCE::setError($matches[1], $this->OID, "", $matches[0]);
                    }
                    continue;
                }
            }

            // SUSPENDED:
            if (preg_match('/^309 SUSPENDED\s+(.*)$/', $line, $matches)) {
                //if ((isset($matches[0])) && (!isset($matches[1]))) {
                if (isset($matches[0])) {
                    // We are suspended. Grab the suspend message. If there is none,
                    // then simply set it to TRUE:
                    $this->CODB['suspendedmsg'] = CCE::unescape($matches[1]);
                    if ($this->CODB['suspendedmsg'] == "") {
                        $this->CODB['suspendedmsg'] = true;
                    }
                    continue;
                }
            }

            // General success messages:
            if (preg_match('/^2/', $line, $matches)) {
                $this->CODB['success'] = '1';
            }

            // General FAIL messages:
            if (preg_match('/^403 BAD PARAMETERS$/', $line, $matches)) {
                $this->CODB['success'] = '0';
                $this->CODB['info'] = "403 BAD PARAMETERS";
                if (isset($this->OID)) {
                    CCE::setError("403 BAD PARAMETERS", $this->OID, "", "403 BAD PARAMETERS");
                }
                else {
                    CCE::setError("403 BAD PARAMETERS", "-1", "", "403 BAD PARAMETERS");
                }
                continue;
            }

            // General FAIL messages:
            if (preg_match('/^4/', $line, $matches)) {
                $this->CODB['success'] = '0';
                continue;
            }

            // Compose object out of old and new data (new overrides old):
            if ($this->CODB['destroyflag']) {
                $this->CODB['object'] = '';
            }
            else {
                $this->CODB['object'] = $this->CODB['old'];
            }

            if ($this->CODB['success']) {
                $this->CODB['object'] = $this->CODB['object'];
            }
        }

        // What we return here is irrelevant, as the stuff we really want
        // is in $this->CODB at this point. That's what counts.
        return $resultArr;

    }

    // unescape: This function is used to clean up data comming
    // from CODB in a fashion that it can be used by the GUI.
    function unescape($text) {
        // Getting rid of the leading and trailing double
        // quotation marks of values:
        if (preg_match('/^\"(.*)\"$/', $text, $matches)) {
            if (isset($matches[1])) {
                $text = $matches[1];
            }
        }

        // Replace certain double escapements and safe characters with their unsafe variants:
        $text = str_replace(array(
            '\\\\',
            '\\"',
            "\\a",
            "\\b",
            "\\f",
            '\\n',
            "\\t",
            "\\\"",
            "\\r"
        ) , array(
            "\\",
            '\"',
            "\a",
            "\b",
            "\f",
            "\n",
            "\t",
            '"',
            ''
        ) , $text);

        // Problem:
        //
        // テスト002 is stored as this octal sequence:
        //
        // 343 203 206 343 202 271 343 203 210 002
        //
        // テスト is stored as this octal sequence:
        //
        // 343 203 206 343 202 271 343 203 210
        //
        // This means that any numbers (or possibly regular characters) that aren't in the format '\[0-9]{3}' (backslash + 3 numbers) aren't octal and
        // must not run through octal to hex to UTF-8 processing.
        //
        // That creates an interesting challenge in decoding this shit without breakage. \o/
        // Fist step in a solution:
        //
        // Find out if we have any octals. Easily identified by counting the number of backslashes:
        $numOctals = '0';
        if (preg_match('|\\\|', $text, $mxatches)) {
            $extr_matches = explode('\\', $text);
            $numOctals = count($extr_matches);
        }

        if ($numOctals == '0') {
            // No octals? Then we can return the results immediately. Yay! \o/
            return $text;
        }
        else {
            // Hold on to your tits, as we have octals! Let the magic begin!
            // Split the whole she-bang of our input into an array of individual characters:
            $pattern = str_split($text);

            // Start with sane helper strings:
            $HaveOctalPart = '0';
            $MyOctal = '';
            $Number_of_Octals_in_a_row = '0';
            $Triple_of_Octals = '';
            $outText = '';

            for ($i = 0;$i < count($pattern);$i++) {
                if (($HaveOctalPart > '0') && ($HaveOctalPart < '4')) {
                    // We started with something that looked like it's an octal. But we don't have four characters yet.
                    // So we push it into $MyOctal to possibly get a complete octal of four chars (backslash + 3 numbers)
                    $MyOctal .= $pattern[$i];
                    $HaveOctalPart++;
                }

                if (($pattern[$i] != "\\") && ($HaveOctalPart == '0')) {
                    // This isn't part of an Octal! So we directly push this into the output array.
                    $outText .= $pattern[$i];
                    $Number_of_Octals_in_a_row = '0';
                }

                // Special case: MultiLine text may have hard returns somewhere in them.
                // We don't count them as octal even if they start with a backslash. \o/
                if (($HaveOctalPart != '4') && ($MyOctal == '\n')) {
                    // We ain't octal. Push us out and restart the count:
                    $outText .= $MyOctal;
                    $Number_of_Octals_in_a_row = '0';
                    $Triple_of_Octals = '';
                }

                if ($HaveOctalPart == '4') {
                    // We are a complete octal with backslash. Push us out into the Octal Assembly until there's three of us:
                    $Number_of_Octals_in_a_row++;
                    $Triple_of_Octals .= $MyOctal;
                    $MyOctal = '';
                    $HaveOctalPart = '0';
                }

                if ($Number_of_Octals_in_a_row == '3') {
                    // We have the required three octals in a row that we need to transform something to UTF-8:
                    $outText .= preg_replace_callback('/\\\\([0-7]{1,3})/', 'CCE::convertOctalToCharacter', $Triple_of_Octals);
                    $Number_of_Octals_in_a_row = '0';
                    $Triple_of_Octals = '';
                }

                if ($pattern[$i] == "\\") {
                    // We have found the first octal in the string. Let's grab the backslash and start counting until we have
                    // the next tree numbers that we need to form a complete octal:
                    $HaveOctalPart = '1';
                    $MyOctal .= $pattern[$i];
                }

            }
            // Return assembled output and hope it's readable:
            return $outText;
        }
    }

    // Helper-Function to convert octals to UTF-8:
    function convertOctalToCharacter($octal) {
        return chr(octdec($octal[1]));
    }

    // _escape: This function is used to clean up data comming
    // from the GUI in a fashion so that it can be stored into CODB.
    function _escape($text) {
        if (is_array($text)) {
            // We have an array. Transform it into a scalar for easier processing:
            $text = array_to_scalar($text);
        }

        // Check if this is a simple matter. If so, return right away:
        if (preg_match('/^[a-zA-Z0-9_]+$/', $text)) {
            return $text;
        }

        // Replace unwanted chars with their double escaped counterparts or another safe replacement:
        $out = str_replace(array(
            "\\",
            "\a",
            "\b",
            "\f",
            "\n",
            "\t",
            '"',
            '$',
            '&quot;',
            '&amp;',
            '&#39;',
            '&lt;',
            '&gt;'
        ) , array(
            "\\\\",
            "\\a",
            "\\b",
            "\\f",
            "\\n",
            "\\t",
            "\\\"",
            "\\$",
            '\"',
            '\&',
            "'",
            '<',
            '>'
        ) , $text);
        return $out;
    }

    // _send(): This function is used to filter and clean up any of the
    // more sophisticated commands that we send to CCEd. That way we can
    // escape and filter key/value pairs into a format that CCEd understands.
    // Currently dormant, as we use _escape() directly in relevant transactions.
    function _send($cmd) {

        // Start sane:
        $encoded = array();

        if (is_array($cmd)) {
            // $cmd is an array:
            foreach ($cmd as $key => $value) {
                $encoded[] = CCE::_escape($key) . '=' . CCE::_escape($value);
            }
        }
        else {
            // Anything else:
            $encoded[] = CCE::_escape($cmd);
        }

        // Puzzle it back together:
        $out = implode(" ", $encoded);

        // Return the results:
        return $out;
    }

    function setError($code, $oid, $key = "", $msg = "") {
        $numErrs = count($this->ERRORS);
        $this->ERRORS[$numErrs]['code'] = $code;
        $this->ERRORS[$numErrs]['oid'] = $oid;
        $this->ERRORS[$numErrs]['key'] = $key;
        $this->ERRORS[$numErrs]['message'] = $msg;

        if (preg_match('/\[\[(.*),(.*)\]\]/', $msg, $joinedMatches)) {
            if (count($joinedMatches) == "3") {
                $xvarkRay = explode('=', $joinedMatches[2]);
                if (isset($xvarkRay[1])) {
                    if (preg_match('/\"(.*)\"/', $xvarkRay[1], $cleanTagVar)) {
                        $xvarkRay[1] = preg_replace('/\\\\/', '', $xvarkRay[1]);
                        $xvarkRay[1] = rtrim($xvarkRay[1]);
                        $xvarkRay[1] = ltrim($xvarkRay[1]);
                    }
                }
                $this->ERRORS[$numErrs]['code'] = "[[$joinedMatches[1]]]";
                $this->ERRORS[$numErrs]['key'] = $xvarkRay;
            }
        }

        if ($numErrs != "0") {
            $numErrs++;
        }
    }

    function ccephp_errors() {
        return $this->ERRORS;
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