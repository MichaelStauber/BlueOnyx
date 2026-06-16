<?php 
namespace Apache\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Fpm_amdetails extends BaseController {
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

        helper(['amdetail']);

        $CI =& get_instance();

        if (!$CI->getAllowed('serverShowActiveMonitor')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Generate Software-Updates page:
        //

        $errors = array();

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-apache");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        // Find out if we display without menu or with menu:
        $get_form_data = $this->request->getGet();
        if (!isset($get_form_data['short'])) {
            $fancy = FALSE;
        }
        else {
            if ($get_form_data['short'] == "1") {
                $fancy = TRUE;
            }
        }

        // Set Menu items:
        $BxPage->setVerticalMenu('base_monitor');
        $BxPage->setVerticalMenuChild('base_amStatus');
        if ($fancy == TRUE) {      
            $BxPage->setOutOfStyle(TRUE);
        }
        $page_module = 'base_sysmanage';
        $defaultPage = "basicSettingsTab";

        if ($fancy == TRUE) {
            $page_body[] = '<br><div id="main_container" class="container_16">';
        }

        //
        //--- Print Detail Block:
        //

        $page_body[] = am_detail_block($factory, $CI->cceClient, "PHPFPMMASTER", "[[base-apache.amPHPFPMMASTERNameTag]]");
        if (is_dir('/home/solarspeed/php-5.6')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP56", "[[base-apache.amPHPFPM56NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-7.0')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP70", "[[base-apache.amPHPFPM70NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-7.1')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP71", "[[base-apache.amPHPFPM71NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-7.2')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP72", "[[base-apache.amPHPFPM72NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-7.3')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP73", "[[base-apache.amPHPFPM73NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-7.4')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP74", "[[base-apache.amPHPFPM74NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.0')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP80", "[[base-apache.amPHPFPM80NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.1')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP81", "[[base-apache.amPHPFPM81NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.2')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP82", "[[base-apache.amPHPFPM82NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.3')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP83", "[[base-apache.amPHPFPM83NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.4')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP84", "[[base-apache.amPHPFPM84NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.5')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP85", "[[base-apache.amPHPFPM85NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-8.6')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP86", "[[base-apache.amPHPFPM86NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-9.0')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP90", "[[base-apache.amPHPFPM90NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-9.1')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP91", "[[base-apache.amPHPFPM91NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-9.2')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP92", "[[base-apache.amPHPFPM92NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-9.3')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP93", "[[base-apache.amPHPFPM93NameTag]]");
        }
        if (is_dir('/home/solarspeed/php-9.4')) {
            $page_body[] = am_detail_block($factory, $CI->cceClient, "FPMPHP94", "[[base-apache.amPHPFPM94NameTag]]");
        }

        if ($fancy == TRUE) {
            $page_body[] = '</div>';
        }
        else {
            // Full page display. Show "Back" Button:
            $page_body[] = am_back($factory);
        }

        // Out with the page:
        $BxPage->setErrors($errors);
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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