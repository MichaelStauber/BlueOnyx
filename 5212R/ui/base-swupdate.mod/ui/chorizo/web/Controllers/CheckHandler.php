<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class CheckHandler extends BaseController {
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

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/checkHandler");
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

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $get_form_data = $BxPage->getGETPOST('GET');

        //
        //-- Do the deeds:
        //

        if (!isset($get_form_data['backUrl'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        // Check NewLinQ for new PKGs:
        $i = $CI->serverScriptHelper->shell("/usr/sausalito/sbin/grab_updates.pl -u", $ret, 'root', $BX_SESSION['sessionId']);
        // Set cookie to recall when we last did this:
        setcookie("nl_check", time(), "0", "/");

        if ($i) {
            if (preg_match('/\[\[(.*?)\]\]/', $ret, $matches)) {
                $msg = '[[' . $matches[1] . ']]';
                $color = 'alert_green';
                $errors[] = ErrorMessage($i18n->get($msg), $color, 'info_about');
            }
            else {
                $msg = '[[base-swupdate.NoPackagesBody]]';
                $color = 'alert_green';
                $errors[] = ErrorMessage($i18n->get($msg), $color, 'info_about');
            }
            $redirect_URL = $get_form_data['backUrl'];
            $BxPage->ReturnToThisPage($errors, $redirect_URL);
        }

        if (!isset($ret)) {
            $search = array('installState' => 'Available', 'new' => '1', 'isVisible' => '1');
            $oids = $CI->cceClient->findNSorted("Package", 'version', $search);
            if (count($oids) > 0) {
                $msg = '[[base-swupdate.NewUpdatesSubject]]';
                $color = 'alert_green';
                $errors[] = ErrorMessage($i18n->get($msg), $color, 'info_about');
            }
            else {
                $msg = '[[base-swupdate.NoPackagesBody]]';
                $color = 'alert_green';
                $errors[] = ErrorMessage($i18n->get($msg), $color, 'info_about');
            }
        }

        //
        //-- Return home:
        //

        $redirect_URL = $get_form_data['backUrl'];
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