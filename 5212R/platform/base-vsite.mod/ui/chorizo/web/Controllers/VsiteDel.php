<?php 
namespace Vsite\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
use AutoFeatures;
use I18n;
use BxPage;

class VsiteDel extends BaseController {
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

        //helper(['form']);

        $CI =& get_instance();
        if (!$CI->getAllowed('manageSite')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $extra_headers = array();
        $access = 'rw';

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-vsite", "/vsite/vsiteDel");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-vsite", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //--- Handle form validation:
        //

        // Get URL params:
        $get_form_data = $this->request->getGet();

        //
        //-- Validate GET data:
        //

        if (isset($get_form_data['group'])) {
            // We have a delete transaction:
            $delSite = $get_form_data['group'];
        }
        else {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        //
        //----- Security checks:
        //
        //      We need to find out if the Vsite with that 'name' exists.
        //      But we also need to make sure that it is under the ownership
        //      of the currently logged in 'createdUser'. Of course user
        //      'admin' has rights to delete all Vsites.
        //

        // Prep search array:
        $exact = array('name' => $delSite);

        // We're not 'systemAdministrator', so we limit the search to 'createdUser' => $CI->BX_SESSION['loginName']:
        if (!$CI->getAllowed('systemAdministrator')) {
            // If the user is not 'systemAdministrator', then we only return Vsites that this user owns:
            $exact = array_merge($exact, array('createdUser' => $CI->BX_SESSION['loginName']));  
        }

        // Get a list of Vsite OID's:
        $vsites = $CI->cceClient->findx('Vsite', $exact, array(), "", "");

        // At this point we should have one object. Not more and not less:
        if (count($vsites) != "1") {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#3");
        }
        elseif (is_file('/etc/DEMO')) {
            // We are in DEMO mode. So we don't delete:

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors, '/vsite/vsiteList');
        }
        else {
            // We continue with the deletion:
            // Initialize status to avoid race conditions
            // ... actually, let's not do that now.
            //fopen("http://localhost:444/status.php?statusId=remove$delSite&title=[[base-vsite.deletingSite]]&message=[[base-vsite.removingUsers]]&progress=0", "r");

            // Command to execute:
            $cmd = "/usr/sausalito/sbin/vsite_destroy.pl $delSite \"/vsite/vsiteList\"";

            // Do the dirty deeds:
            $CI->serverScriptHelper->fork($cmd, "root", $CI->BX_SESSION['sessionId']);

            // Return to this page and display errors - if there are any.
            // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
            $BxPage->ReturnToThisPage($errors, "/gui/processing?statusId=remove$delSite&title=[[base-vsite.deletingSite]]&message=[[base-vsite.removingUsers]]&progress=0");
        }

        // Can't imagine why we would get to this line.
        // But if we do, log a 403 and call it a day:
        Log403Error("/gui/Forbidden403#4");

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