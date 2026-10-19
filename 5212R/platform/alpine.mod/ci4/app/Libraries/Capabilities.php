<?php

/**
 * Capabilities.php
 *
 * BlueOnyx Capabilities for Codeigniter
 *
 * Description: A class that facilitates working with Capabilities
 * and capability groups
 *
 * @package   Capabilities
 * @author    Michael Stauber
 * @copyright Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
 * @copyright Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
 * @copyright Copyright (c) 2003 Sun Microsystems, Inc.  All rights reserved.
 * @link      http://www.blueonyx.it
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   2.0
 * 
 * 
 * IMPORTANT: WHY THE FUCK DO WE NEED THIS? IT'S ALL IN ServerScripthelper.php NOW!!!!
 * 
 */

include_once("ArrayPacker.php");

// CapCache is legacy global-namespace code like this file, so the CI4
// autoloader will never find it. It lives next to this file; anchor the
// include to __DIR__ so it resolves regardless of include_path or CWD.
include_once(__DIR__ . '/CapCache.php');

// The cache helper is an optimisation, not a prerequisite. If it is missing
// (incomplete package, botched deploy) log it and fall back to no caching
// instead of taking the whole GUI down with a fatal error.
if (!class_exists('CapCache', FALSE)) {
    $capcache_msg = 'Capabilities.php: CapCache.php not found next to ' . __FILE__ . ' - capability cache disabled';
    if (function_exists('bx_error_log')) {
        bx_error_log($capcache_msg);
    }
    else {
        error_log($capcache_msg);
    }
}

class Capabilities {

    // Internal caching of expanded data
    var $capabilityGroups;
    var $notCapabilityGroups;
    var $capabilities;
    var $cceClient;
    public $loginUser;
    var $_listAllowed;
    var $_gotAllCapabilityGroups;
    // TRUE only when every CapabilityGroup that CCE reported was really read.
    // A partial read (CCEd restarting mid-fetch) must never count as complete.
    public $groups_complete = FALSE;
    var $loginName;
    var $sessionId;
    var $debugActive;
    var $_debug;

    // Description: Constructor
    // param: a active cceclient. (optional, otherwise it will create a new connection)
    //function Capabilities($cce = NULL, $loginName = NULL, $sessionId = NULL) {
    public function __construct($cce = NULL, $loginName = NULL, $sessionId = NULL) {
        $CI =& get_instance();
        if ($cce != NULL) {
            $this->cceClient =& $cce;
        }
        else {
            $this->cceClient =& $CI->getCCE();
        }

        // New method via CI 'BX_SESSION':
        $BX_SESSION = $CI->getBX_SESSION();
        $userCap = $BX_SESSION['loginUser'];
        $iam = $userCap['OID'];
        $this->loginUser = $userCap;

        $this->capabilityGroups = array();
        $this->capabilities = array();
        $this->notCapabilityGroups = array();
        $this->_listAllowed = array();
        $this->_debug = false;

        // Credentials first. getAllCapabilityGroups() below shells out through
        // ccewrap and shell() puts $this->loginName into CCE_USERNAME. With the
        // name still unset the call goes out as user "" - CCEd answers
        // 'AUTHKEY to user "" failed', the external fetch fails, and we fall back
        // to the slow direct-CCE path (which is what dies when CCEd restarts).
        $this->loginName = (isset($BX_SESSION['loginName']) && $BX_SESSION['loginName'] != '') ? $BX_SESSION['loginName'] : $loginName;
        $this->sessionId = (isset($BX_SESSION['sessionId']) && $BX_SESSION['sessionId'] != '') ? $BX_SESSION['sessionId'] : $sessionId;

        // this makes us get all the capgroup stuff right away, making CCE not 
        // to worry about pulling capgroups out by indexed names
        $this->getAllCapabilityGroups();
        $this->getAllCapabilities();
        $this->listAllowed();

        // Check if debugging is active
        if (is_file("/etc/DEBUGSSH")) {
            $this->debugActive = TRUE;
        }
        else {
            $this->debugActive = FALSE;
        }
    }

    // description: TRUE when every CapabilityGroup that CCE reported was really
    // read. A FALSE here means "permissions unknown", never "no permissions".
    public function capabilityGroupsComplete() {
        return $this->groups_complete;
    }

    // description: checks to see if a user is granted the given capability.
    // param: the name of the CapabilityGroup or CCE-Level capability to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    function getAllowed($capName, $oid = -1) {
        // this is quicker besides systemAdministrator should be
        // able to view everything whether there is a capability group
        // or not
        $currentuser = 0;
        if ($oid == -1) {
            $currentuser = 1;
            if (isset($this->loginUser["OID"])) {
                $oid = $this->loginUser["OID"];
            }
            else {
                // No loginUser? Then we have no rights!
                return 0;
            }
        }

        if (($currentuser == 1) && ($this->loginUser['systemAdministrator'])) {
            // We want to know the caps for the current users. AND that user is
            // 'systemAdministrator'. Spare the trouble and return a fast 'yes':
            return 1;
        }

        if ((!$this->loginUser['systemAdministrator']) && ($oid == -1) && ($capName == 'adminUser')) { 
            // Fast 'no' to the question for 'adminUser', because we simply aren't.
            // Do not get get confused here. Resellers are 'adminUser', but we do
            // NOT treat them as such unless they also have the 'systemAdministrator'
            // flag. Without that flag, we do not rate them as 'adminUser':
            return 0;
        }

        $caps = $this->listAllowed($oid);
        if (in_array($capName, $caps)) {
            return 1;
        }
        else {
            return 0;
        }
        return 0;
    }

    // description: checks to see if a user is a reseller (createdUser) of a 
    // given Vsite group.
    // param: the group of the Vsite to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    function getReseller($group, $oid = -1) {
        if ($oid == -1) {
            $currentuser = 1;
            $oid = $this->loginUser["OID"];
        }
        // Find out if the Group exists:
        $site = $this->cceClient->getObject('Vsite', array('name' => $group));
        if (!isset($site['fqdn'])) {
            // Group doesn't exist. So we fail right here:
            return 0;
        }
        if ($this->loginUser['systemAdministrator']) {
            // Fast 'yes' to all rights, because we are system administrator:
            return 1;
        }
        // Check Vsite's 'createdUser':
        if ($site['createdUser'] == $this->loginUser['name']) {
            // This user is listed as 'createdUser', so we return yes:
            return 1;
        }
        return 0;
    }

    // description: checks to see if a user is a siteAdmin of a given Vsite group.
    // param: the group of the Vsite to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    function getSiteAdmin($group, $oid = -1) {
        if ($oid == -1) {
            $currentuser = 1;
            $oid = $this->loginUser["OID"];
        }
        // Find out if the Group exists:
        $site = $this->cceClient->getObject('Vsite', array('name' => $group));
        if (!isset($site['fqdn'])) {
            // Group doesn't exist. So we fail right here:
            return 0;
        }
        if ($this->loginUser['systemAdministrator']) {
            // Fast 'yes' to all rights, because we are system administrator:
            return 1;
        }
        // Check if this user belongs to the given group:
        if (($this->loginUser['site'] == $group) && ($this->getAllowed('siteAdmin'))) {
            // This user is listed as 'createdUser', so we return yes:
            return 1;
        }
        else {
            // He might be a siteAdmin elsewhere, but sure not here.
            return 0;
        }
        // Check if this user has the capability 'siteAdmin':
        $caps = $this->listAllowed($oid);
        if (in_array('siteAdmin', $caps)) {
            return 1;
        }
        return 0;
    }

    // description: checks to see if a user is in a certain group or a reseller of it.
    // param: the group of the User/Vsite to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    function getGroup($group, $oid = -1) {
        if ($oid == -1) {
            $currentuser = 1;
            $oid = $this->loginUser["OID"];
        }
        // Find out if the Group exists:
        $site = $this->cceClient->getObject('Vsite', array('name' => $group));
        if (!isset($site['fqdn'])) {
            // Group doesn't exist. So we fail right here:
            return 0;
        }
        if ($this->loginUser['systemAdministrator']) {
            // Fast 'yes' to all rights, because we are system administrator:
            return 1;
        }
        // Check if this user belongs to the given group OR is Reseller of this group:
        if (($this->loginUser['site'] == $group) || ($this->getReseller($group))) {
            // This user is listed as 'createdUser', so we return yes:
            return 1;
        }
        return 0;
    }

    // description: checks to see if a user is systemAdministrator, siteAdmin 
    // or a reseller of a group and if the group exists.
    // param: the group of the User/Vsite to check
    // param: the user to check for (default: current)
    // returns: true if the current user has this capability, false otherwise

    function getGroupAdmin($group, $oid = -1) {
        if ($oid == -1) {
            $currentuser = 1;
            $oid = $this->loginUser["OID"];
        }

        if ($this->loginUser['systemAdministrator']) {
            // Fast 'yes' to all rights, because we are system administrator:
            return 1;
        }

        // Find out if the Group exists:
        $site = $this->cceClient->getObject('Vsite', array('name' => $group));
        if (!isset($site['fqdn'])) {
            // Group doesn't exist. So we fail right here:
            return 0;
        }
        // Check if this user is Reseller of this group:
        if (($this->loginUser['site'] == "") && ($this->getReseller($group) == "1")) {
            // This is a reseller (has no group) and can manage the specified group as Reseller.
            return 1;
        }
        // Check if this user belongs to this group and is siteAdmin of this group:
        if (($this->loginUser['site'] == $group) && ($this->getSiteAdmin($group))) {
            // This user belongs to this group and is siteAdmin OR Reseller.
            return 1;
        }
        return 0;
    }

    // description:  gets the capabilityGroup and caches it
    function &getCapabilityGroup($capName, $data = null) {
        $this->debug_log("getCapabilityGroup: via Capabilities.php");

        if ($data) {
            // we are given the data to cache.
            $this->capabilityGroups[$capName] = $data;
            return $this->capabilityGroups[$capName];
        }
        // check if we already checked and couldn't find this capname
        if (isset($this->capabilityGroups[$capName])) {
            if (isset($this->notCapabilityGroups[$capName]) || ($this->capabilityGroups[$capName]==null && $this->_gotAllCapabilityGroups)) {
               return null;
            }
        }
        $cce = $this->cceClient;
        if (isset($this->capabilityGroups[$capName])) {
            if ($this->capabilityGroups[$capName]!=null) {
               return $this->capabilityGroups[$capName];
            }
        }
        if (($group = $this->cceClient->getObject("CapabilityGroup", array("name"=>$capName)))!=null) {
            $this->capabilityGroups[$capName] = $group;
            return $this->capabilityGroups[$capName];
        }
        $this->notCapabilityGroups[$capName] = 1;
        $null = "NULL";
        return $null;
    } 

    function getGlobalCapabilitiesObject($cce = null) {

        $cap_file_name = "/usr/sausalito/capcache/$this->loginName" . "_cap";
        $this->debug_log("getGlobalCapabilitiesObject: via Capabilities.php");

        if (is_file($cap_file_name)) {
            $cap_file_data = read_file($cap_file_name);
            $this->CAPABILITIESGLOBALOBJECT = json_decode($cap_file_data, true);
        }

        if (is_array($this->CAPABILITIESGLOBALOBJECT)) {
            $this->debug_log("getGlobalCapabilitiesObject: from File");
            return $this->CAPABILITIESGLOBALOBJECT;
        }
        else {
            $this->debug_log("getGlobalCapabilitiesObject: full run");
            $this->CAPABILITIESGLOBALOBJECT = new Capabilities($cce);

            // Store temporary file:
            $cap_file_data = json_encode($this->CAPABILITIESGLOBALOBJECT);

            if (!write_file($cap_file_name, $cap_file_data)) {
                system("rm -f $cap_file_name");
            }
            return $this->CAPABILITIESGLOBALOBJECT;
        }
    }

    function reset_cache() {
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        self::debug_log("reset_cache: via ServerScriptHelper");
        $capabilityGroups_file_name = "/usr/sausalito/capcache/$this->loginName" . "_capabilityGroups";
        if (is_file($capabilityGroups_file_name)) {
            system("rm -f $capabilityGroups_file_name");
        }
    }

    // description: returns an array of ALL the capabilityGroups
    function getAllCapabilityGroups() {

        $enable_ACL_Cache = TRUE;

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $this->debug_log("getAllCapabilityGroups: via Capabilities.php");

        $loginName = (isset($BX_SESSION['loginName']) && $BX_SESSION['loginName'] != '') ? $BX_SESSION['loginName'] : $this->loginName;

        if (isset($this->_gotAllCapabilityGroups)) {
            $this->debug_log("getAllCapabilityGroups: From memory");
            return $this->capabilityGroups;
        }

        // Which CapabilityGroups exist *right now*. Doubles as the cache's
        // validation key: a snapshot taken while the CCEd constructors were still
        // creating those groups has a different OID set and gets rebuilt instead
        // of being trusted.
        $cce =& $this->cceClient;
        $oids = array();
        try {
            $oids = (array)$cce->find("CapabilityGroup");
        }
        catch (\Throwable $e) {
            $oids = array();
        }

        // Conditions (a) System object missing and (b) license not accepted:
        // neither trust nor write the on-disk cache in that window. If the
        // helper is not installed at all, behave as if we were always in that
        // window: full run on every request, no caching, no fatal.
        $have_cache = class_exists('CapCache', FALSE);
        $setup_window = $have_cache ? CapCache::setupWindow($cce) : TRUE;

        if ($have_cache && (!$setup_window) && ($enable_ACL_Cache === TRUE)) {
            $cached_groups = CapCache::load($loginName, $oids);
            if (is_array($cached_groups)) {
                $this->capabilityGroups = $cached_groups;
                $this->_gotAllCapabilityGroups = 1;
                // A cache that was validated against the current OID set counts
                // as complete - incomplete snapshots are never written any more.
                $this->groups_complete = TRUE;
                return $this->capabilityGroups;
            }
            $this->debug_log("getAllCapabilityGroups: cache stale, incomplete or missing - Full run");
        }
        else {
            $this->debug_log("getAllCapabilityGroups: setup window - cache bypassed, Full run");
        }

        $timer = timer();
        timer('Capabilities.php CapabilityGroup Fetch');

        $cce =& $this->cceClient;
        $oids = $cce->find("CapabilityGroup");

        $totalNumOIDs = count($oids);
        $oid_list = json_encode($oids);

        $ret = $this->shell("/usr/sausalito/sbin/external_cce_get.pl --oid $oid_list", $output, 'root', $BX_SESSION['sessionId']);

        // Check if the script call worked:
        $fallback_to_direct_cce = '0';
        if ($ret != 0) {
            // Failed!
            $fallback_to_direct_cce = '1';
        }

        // Read the groups. CCEd can vanish mid-request - it gets restarted right
        // after the constructors finish, which is exactly when the wizard hands
        // over to the GUI - so every single read has to be checked: an
        // unchecked $obj['name'] on false is a hard 500 in PHP 8.
        // Track which groups were really read - by name, not by counting read
        // attempts. When both paths run for the same OID a counter would count
        // that group twice and could hide a gap.
        $read_groups = array();

        if ($fallback_to_direct_cce == '0') {
            // Use JSON results:
            $JSON_OBJECTS = json_decode($output, true);
            if (is_array($JSON_OBJECTS)) {
                foreach ($JSON_OBJECTS as $oid) {
                    if ((!is_array($oid)) || (!isset($oid['OBJECT'])) || (!is_array($oid['OBJECT'])) || (!isset($oid['OBJECT']['name']))) {
                        continue;
                    }
                    // Get CapabilityGroup settings:
                    $this->getCapabilityGroup($oid['OBJECT']['name'], $oid['OBJECT']);
                    $read_groups[$oid['OBJECT']['name']] = TRUE;
                }
            }
            if (count($read_groups) < count($oids)) {
                // The external fetch came back short. Top it up the direct way
                // instead of throwing the partial result away.
                $fallback_to_direct_cce = '1';
            }
        }

        if ($fallback_to_direct_cce != '0') {
            // Use PHP-CCE instead:
            foreach($oids as $oid) {
                $obj = $cce->get($oid);
                if ((!is_array($obj)) || (!isset($obj['name']))) {
                    continue;
                }
                $this->getCapabilityGroup($obj['name'], $obj);
                $read_groups[$obj['name']] = TRUE;
            }
        }

        // The expected count comes from CCE itself (find CapabilityGroup), so
        // groups added by an update or a shop module are included automatically:
        // they raise the bar here and they change the OID set the cache is
        // validated against. Nothing is hardcoded. An empty OID list means CCE
        // could not be asked at all - that is never "complete".
        if ((count($oids) == 0) || (count($read_groups) < count($oids))) {
            // CCEd is gone or still coming up - or it answered only part of the
            // set. Do not mark this as loaded and do not cache it: a partial read
            // stored together with the full OID list would look valid forever.
            bx_error_log("getAllCapabilityGroups: read " . count($read_groups) . " of " . count($oids) . " CapabilityGroups (CCEd restarting?) - not complete, nothing cached, retry left open");
            return $this->capabilityGroups;
        }

        // Every group that CCE reported was read. Only now is this complete.
        $this->groups_complete = TRUE;

        timer('Capabilities.php CapabilityGroup Fetch');
        $this->_gotAllCapabilityGroups = 1;

        // Store the snapshot - but never one we cannot trust:
        if (($enable_ACL_Cache === TRUE) && $have_cache) {
            if ($setup_window) {
                // Constructor window: whatever exists right now may still be
                // incomplete, so nothing is persisted.
                CapCache::drop($loginName);
            }
            elseif (($loginName === 'admin') && (!isset($this->capabilityGroups['adminUser']))) {
                // (c) Never persist a snapshot that cannot even resolve the
                // factory admin's adminUser capability. That is exactly the state
                // which sends /wizard to /user/personalAccount and back again.
                CapCache::drop($loginName);
                bx_error_log("getAllCapabilityGroups: refusing to cache incomplete capability groups for 'admin' (adminUser missing, " . count($oids) . " CapabilityGroups in CODB)");
            }
            elseif (!CapCache::save($loginName, $this->capabilityGroups, $oids)) {
                CapCache::drop($loginName);
            }
        }
        return $this->capabilityGroups;
    }

    // description: returns an array of all the declared cce-level capabilities
    function getAllCapabilities() {
        $this->debug_log("getAllCapabilities: via Capabilities.php");
        if (count($this->capabilities)) {
           return ($this->capabilities); 
        }
        $this->capabilities = $this->cceClient->names("Capabilities");
        return $this->capabilities;
    }

    // description: get a list of all the capabilities the given user has
    // param: the oid of the user to check (defaults: current)
    // returns: a list of all the capabilities the current user has
    function listAllowed($oid = -1) {
        if ($oid == -1) {
            $currentuser = 1;
            $oid = $this->loginUser["OID"];
        }
        if (!isset($this->_listAllowed[$oid])) {
            $this->_listAllowed[$oid] = TRUE;
        }

        if (is_array($this->_listAllowed[$oid])) {
            return $this->_listAllowed[$oid];
        }

        $ret = array();

        // get the capLevels from this user 
        if (isset($currentuser)) {
            $uirights = stringToArray($this->loginUser["uiRights"]);
            if (in_array("systemAdministrator", $uirights) || $this->loginUser["systemAdministrator"]) {
                // I am god, so I get ALL the capgroups :)
                $groups = $this->getAllCapabilityGroups();
                $caplevels = array();
                foreach($groups as $groupkey=>$groupval) {
                    $caplevels[] = $groupkey;
                }
            } 
            else { // get the capLevels from this user 
                $caplevels = stringToArray($this->loginUser["capLevels"]);
            }
        }
        else {
            // i'm asking about another user, so I say what I can about them.
            $user = $this->cceClient->get($oid);
            $caplevels = stringToArray($user["capLevels"]);
        }

        $returnCap = array();

        foreach ($caplevels as $key => $capName) {
            foreach ($this->getAllCapabilityGroups() as $capA => $capContend) {
                if ($capContend['CLASS'] == "CapabilityGroup") {
                    if ($capContend['name'] == $capName) {
                        if (!in_array($capName, $returnCap)) {
                            $returnCap[] = $capName;
                        }
                        $tmpreturnCap = scalar_to_array($capContend['capabilities']);
                        foreach ($tmpreturnCap as $key => $value) {
                            if (!in_array($value, $returnCap)) {
                                $returnCap[] = $value;
                            }
                        }
                    }
                    else {
                        if (!in_array($capName, $returnCap)) {
                            $returnCap[] = $capName;
                        }
                    }
                }
            }
        }

        // New method via CI 'BX_SESSION':
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        if ($BX_SESSION['userShell'] > "0") {
            $returnCap[] = 'shellAccessEnabled';
        }

        // Remove blank entries, make unique and store:
        $returnCap = array_filter(array_unique($returnCap));
        // Only remember the result when the capability groups were read
        // completely. Otherwise a later call in the same request must be able to
        // recompute it - CCEd may be back a second later.
        if (($this->_listAllowed[$oid] == TRUE) && ($this->groups_complete)) {
            $this->_listAllowed[$oid] = $returnCap;
        }
        return $returnCap;
    }

    // description: given a capabilitygroup name, this function will expand it
    //   and it's children into a list composed of both capabilitygroup names and
    //   and cce-level capabilities
    // param: capName - the name of the capability to be expanded.
    // returns: an expanded list of the capabilities entailed by $capName 
    function expandCaps($capName, $seen = array()) {
        // don't cycle around in a graph.
        if (in_array($capName, $seen)) {
            return array();
        }

        // check to see if capName is a group, if so, expand..
        if (($group = &$this->getCapabilityGroup($capName))!=null) {

            if (isset($group["expanded"])) {
                if ($group["expanded"] != null) {
                    return $group["expanded"];
                }
            }

            $children = stringToArray($group["capabilities"]);
            $kids = array();
            array_push($seen, $capName);
            foreach($children as $child) {
                $kids = array_merge((array)$kids, (array)$this->expandCaps($child, $seen));
            }
            array_push($kids, $capName);
            $kids = array_unique($kids); 
            $group["expanded"] =& $kids;
            return $kids;
            // check as a cce-capability
            }
            else {
                $capList = $this->getAllCapabilities();
                if (in_array($capName, $capList)) {
                    return array($capName);
                } 
                elseif ($this->_debug) {
                    $msg = "Capability name $capName could not be found in Capabilities" . "::getAllowed()";
                    bx_error_log($msg, 0);
            }
        }
    }

    // descriptions: get an array of access rights
    // returns: an array of access rights in strings
    function getAccessRights() {

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        // Get loginName:
        $loginName = $BX_SESSION['loginName'];
        if (!$loginName) {
            $loginName = $CI->input->cookie('loginName');
        }

        $accessRights = array();

        // include rights specified in uiRights property
        if ($this->loginUser["uiRights"] != "") {
            $accessRights = stringToArray($this->loginUser["uiRights"]);
        }

        // add the list of capabilityGroups AND cce-level capabilities
        if (isset($this->loginUser["capLevels"])) {
            $accessRights = array_merge($accessRights, $this->listAllowed());
        }

        // This catches extra admins:
        $admin_users = posix_getgrnam("admin-users");
        if (is_array($admin_users)) {
            if (in_array($loginName, $admin_users['members'])) {
                $accessRights[] = "serverManage";
            }
        }

        if (is_array($BX_SESSION['loginUser'])) {
            // Reuse loginUser from BX_SESSION if present:
            $user = $BX_SESSION['loginUser'];
            $userShell['enabled'] = $BX_SESSION['userShell'];
        }
        else {
            // If not present, fetch him via CCE:
            $user = $this->cceClient->getObject("User", array("name" => $loginName));
            $userShell = $this->cceClient->get($user['OID'], 'Shell');
        }

        if ($userShell['enabled'] > "0") {
            if (!in_array('shellAccessEnabled', $accessRights)) {
                $accessRights[] = 'shellAccessEnabled';
            }
        }

        if (($loginName == "admin") || ($this->loginUser['systemAdministrator'] == '1')) {
            if (!in_array('admin', $accessRights)) {
                $accessRights[] = "admin";
            }
            if (!in_array('systemAdministrator', $accessRights)) {
                $accessRights[] = "systemAdministrator";
            }            
        }

        if (in_array($loginName, posix_getgrnam("site-adm"))) {
            if (!in_array('siteAdministrator', $accessRights)) {
                $accessRights[] = "siteAdministrator";
            }
        } 

        return array_unique(array_values($accessRights));
    }

    function getProductCode() {
        //Get product info 
        $build_file = "/etc/build";
        $BUILD_FILE = fopen($build_file, "r");
        $buildtext = fread($BUILD_FILE,filesize($build_file)); 
        fclose($BUILD_FILE);
        if (preg_match("/for a ([A-Za-z0-9\-]+) in/", $buildtext, $regs)) {
            $product = $regs[1];
        }
        return $product;
    }

    // description: allows one to execute a program as
    //   the currently logged in user
    // param: program: A string containing program to execute, including 
    //   path and any arguments
    // param: output variable that picks up the output sent by the program
    // param: the user to run this program as (defaults to the currently
    //   logged in user 
    // returns: 0 an success, errno on error
    function shell($cmd, &$output, $runas="", $sessionId="") {
        $CI =& get_instance();
        if ((!isset($sessionId)) || ($sessionId == "")) {
            $CI =& get_instance();
            $BX_SESSION = $CI->getBX_SESSION();
            $sessionId = $BX_SESSION['sessionId'];
        }
        $product = $this->getProductCode();
        $this->isMonterey = preg_match("/35[0-9][0-9]R/", $product);

        // call ccewrap
        //$cmd = escapeShellCmd($cmd);
        putenv("CCE_SESSIONID=". $sessionId);
        putenv("CCE_USERNAME=". $this->loginName);
        putenv("CCE_REQUESTUSER=". $runas);
        putenv("PERL_BADLANG=0");

        if ($this->isMonterey) {
            exec("$cmd", $array, $ret);
        }
        else {
            exec("/usr/sausalito/bin/ccewrap $cmd", $array, $ret);
        }

        // prepare return
        //while (list($key,$val)=each($array)) {
        //    $output .= "$val\n";  
        //}
        foreach ($array as $key => $value) {
            $output .= $value . "\n";
        }
        //$output = $array;

        // clean up
        putenv("CCE_SESSIONID=");
        putenv("CCE_USERNAME=");
        putenv("CCE_REQUESTUSER=");

        return $ret;
    }

    // Debug logging:
    function debug_log ($msg) {
        if ($this->debugActive) {
            bx_error_log($msg);
        }
    }

} // Class Capabilities

/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
Copyright (c) 2003 Sun Microsystems, Inc. 
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
