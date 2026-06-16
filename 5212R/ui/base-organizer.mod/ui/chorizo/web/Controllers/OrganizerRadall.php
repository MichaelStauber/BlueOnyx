<?php 
namespace Organizer\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class OrganizerRadall extends BaseController {
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

        if (!$CI->getAllowed('validUser')) {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-organizer", "/organizer/organizerradall");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Radicale is disabled. Redirect to /organizer/personalOrganizer:
        //

        // get Radicale info from CCE:
        $radicale = $CI->cceClient->getObject("System", array(), "Radicale");

        if ($radicale['enabled'] == '0') {
            $errors[] = ErrorMessage($i18n->get("[[base-organizer.radicale_off_desc]]"));
            $redirect_URL = "/organizer/personalOrganizer";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Validate GET data:
        //

        if (!isset($get_form_data['action'])) {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#1");
        }
        else {
            $action = $get_form_data['action'];
        }

        // If 'action' is not set correctly, redirect to previous page. 
        // We do have $group or we wouldn't be here.
        $possible_actions = array('backup', 'restore');
        if (!in_array($action, $possible_actions)) {
            // Redirect to previous the page:
            $redirect_URL = "/organizer/personalOrganizer";
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        // OID of the $user:
        $useroid = $user['OID'];

        //
        //-- Do Restore:
        //

        if ($action == 'restore') {
            $CI->cceClient->set($useroid, "Radicale", array("restore" => time()));
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No Errors? Show success:
            if (count($errors) === 0) {
                $errors[] = ErrorMessage($i18n->get('[[base-organizer.restore_OK]]'), 'alert_green', 'info_about');
            }
        }

        //
        //--- Do Backups:
        //

        if ($action == 'backup') {
            $CI->cceClient->set($useroid, "Radicale", array("backup" => time()));
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // No Errors? Show success:
            if (count($errors) === 0) {
                $errors[] = ErrorMessage($i18n->get('[[base-organizer.backup_OK]]'), 'alert_green', 'info_about');
            }
        }

        // Nice people say goodbye, or CCEd waits forever:
        $redirect_URL = "/organizer/personalOrganizer";
        $BxPage->ReturnToThisPage($errors, $redirect_URL);
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