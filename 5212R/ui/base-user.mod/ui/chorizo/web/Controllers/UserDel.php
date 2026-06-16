<?php 
namespace User\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class UserDel extends BaseController {
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

        $CI =& get_instance();

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        // Most basic ACL:
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/userDel");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-user", $CI->getBX_Locale());
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
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a group URL string:
            $group = $get_form_data['group'];
        }
        if (isset($get_form_data['name'])) {
            // We have a username URL string:
            $username = $get_form_data['name'];
        }

        //
        //-- Access Rights Check for Vsite level pages:
        // 
        // 1.) Checks if the Group/Vsite exists.
        // 2.) Checks if the user is systemAdministrator
        // 3.) Checks if the user is Reseller of the given Group/Vsite
        // 4.) Checks if the iser is siteAdmin of the given Group/Vsite
        // Returns Forbidden403 if *none* of that is the case.
        if (!$CI->serverScriptHelper->getGroupAdmin($group)) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        if ((!isset($group)) || (!isset($username))) {
            // No group? No name? Not our kind of game!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
            // Please note: 'serverAdmin' users have no 'site' set.
            // So this also serves as a check to make sure that we 
            // can only delete minnions and not chieftains.
        }

        //
        //-----  Security checks:
        //
        //    We need to find out if the Vsite with that 'group' exists.
        //    But we also need to make sure that it is under the ownership
        //    of the currently logged in 'createdUser' or 'siteAdmin'. 
        //    Of course user 'admin' has rights to delete all Users.
        //

        // Admin cannot be deleted. This check is redundant due to our 
        // 'minnion' & 'chieftain' check above. We also don't allow
        // that someone tries to delete his own account.
        if (($username == "admin") || ($username == $BX_SESSION['loginName'])) {
            // No Harakiri allowed here!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }

        // User is a Reseller. Make sure he can only mess with accounts of Vsites
        // that are under his management:
        if (($CI->getAllowed('manageSite')) && (!$BX_SESSION['loginUser']['systemAdministrator'])) {

            // Get a list of Vsite OID's of this Reseller:
            $vsites = $CI->cceClient->findx('Vsite', array('createdUser' => $BX_SESSION['loginName']), array(), "", "");

            // Build an array of groups that this Reseller owns:
            $groups_of_owned_vsites = array();
            foreach ($vsites as $site) {
                // Get Vsite settings:
                $vsiteSettings = $CI->cceClient->get($site);
                $groups_of_owned_vsites[] = $vsiteSettings['name'];
            }

            // Unless we are 'systemAdministrator' we check if the user we want to delete belongs to a group under our control:
            if ($BX_SESSION['loginUser']['systemAdministrator'] == '0') {
                if (!in_array($group, $groups_of_owned_vsites)) {
                    // Trying to delete a user that's not yours? Bad boy!
                    // Nice people say goodbye, or CCEd waits forever:
                    $CI->cceClient->bye();
                    $CI->serverScriptHelper->destructor();
                    Log403Error("/gui/Forbidden403#4");
                }
            }
        }

        // One more security check: Is siteAdmin, not manageSite, not admin:
        if (($CI->getAllowed('siteAdmin')) && (!$CI->getAllowed('manageSite')) && (!$BX_SESSION['loginUser']['systemAdministrator'])) {
            // So we have a siteAdmin. Is he of the same group as the user he wants to delete?
            if ($BX_SESSION['loginUser']['site'] != $group) {
                // Don't play games with us!
                // Nice people say goodbye, or CCEd waits forever:
                $CI->cceClient->bye();
                $CI->serverScriptHelper->destructor();
                Log403Error("/gui/Forbidden403#5");
            }
        }

        // Check if user to be deleted is the 'SiteAdmin that owns /web:' of his Vsite:
        $all_vsite_data = $CI->cceClient->getAll("Vsite", array('name' => $group));
        $all_vsite_data = reset($all_vsite_data);
        if ($username === $all_vsite_data['PHP']['prefered_siteAdmin']) {
            // You may not delete a siteAdmin that is currently used as 'SiteAdmin that owns /web:'
            $errors[] = ErrorMessage($i18n->get("[[base-user.CannotDeleteSiteAdminForWeb]]"));
            $BxPage->ReturnToThisPage($errors, "/user/userList?group=" . $group);
        }

        // Get a list of User OID's:
        $users = $CI->cceClient->findx('User', array('name' => $username, 'site' => $group), array(), "", "");

        // At this point we should have one object. Not more and not less:
        if (count($users) != "1") {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#6");
        }
        else {

            // Handle suspension and quota state:
            if ((isset($users[0])) && (!is_file('/etc/DEMO'))) {
                $User_Cfg = $CI->cceClient->get($users[0]);
                $User_Disk = $CI->cceClient->get($users[0], 'Disk');

                // Reset over-quota state:
                if ($User_Disk['over_quota'] === '1') {
                    $quota_ok = $CI->cceClient->set($users[0], "Disk", array('over_quota' => '0', 'quota_toggle' => time()));
                }
                // Reset suspension:
                if (($User_Cfg['ui_enabled'] === '0') || ($User_Cfg['enabled'] === '0')) {
                    $unsuspend_ok = $CI->cceClient->set($users[0], "", array('ui_enabled' => '1', 'enabled' => '1'));
                }
            }

            // We continue with the deletion if we're not in DEMO mode:
            if (!is_file('/etc/DEMO')) {
                $CI->cceClient->destroyObjects("User", array('name' => $username, 'site' => $group));
            }

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors, "/user/userList?group=$group");
        }

        // Nice people say goodbye, or CCEd waits forever:
        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();

        // Can't imagine why we would get to this line.
        // But if we do, log a 403 and call it a day:
        Log403Error("/gui/Forbidden403#7");
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