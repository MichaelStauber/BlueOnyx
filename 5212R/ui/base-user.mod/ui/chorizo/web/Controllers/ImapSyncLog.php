<?php 
namespace User\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class ImapSyncLog extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/imapSyncLog");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-user", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        $user = $BX_SESSION['loginUser'];

        //
        //-- Handle form data:
        //

        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // -- Actual page logic start:

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['userOid'])) {
            // We have a UserOID:
            $userOid = $get_form_data['userOid'];
        }
        if (!isset($userOid)) {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }

        // Check if the User is viewing his own logfile:
        if ($user['OID'] != $userOid) {
            // He's not viewing his own logfile. We need to check if 
            // that User has the rights to be here:

            // Get group of the user whose logfile we want to view:
            $TargetUser = $CI->cceClient->get($userOid);

            if ($TargetUser['site'] != "") {
                $group = $TargetUser['site'];
            }
            else {
                $group = "";
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
                Log403Error("/gui/Forbidden403#2");
            }
        }
        else {
            // User views his own log:
            $TargetUser = $user;
        }

        //-- Handle form validation:

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_personalProfile';

        $logfile = '~' . $TargetUser['name'] . '/.imapsync.log';

        $ret = $CI->serverScriptHelper->shell("/bin/cat $logfile", $output, 'root', $BX_SESSION['sessionId']);

        if ($output != '') {
            $output = explode("\n", $output);
            $out = "<pre>";
            foreach($output as $outputline) {
                    $out .= formspecialchars($outputline) . "\n";
            }
            $out .= "</pre>";
        }
        else {
            $out = $i18n->get("[[palette.404title]]");
        }

        $page_body[] = $out;

        // Out with the page:
        $BxPage->setOutOfStyle(TRUE);
        return $BxPage->render($page_module, $page_body);

    }       
}
/*
Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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