<?php 
namespace Disk\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class UserDiskUsage extends BaseController {
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

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // Make the users fullName safe for all charsets:
        $user['fullName'] = bx_charsetsafe($user['fullName']);

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-disk", "/disk/userDiskUsage");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //-- Get Diskspace info:
        $CI->cceClient->setObject("User", array("refresh" => time()), "Disk", array("name" => $BX_SESSION['loginName']));

        // get objects
        $userDisk = $CI->cceClient->get($user['OID'], "Disk");
        $user = $CI->cceClient->get($user['OID']);

        // get user disk information
        $used = $userDisk["used"];
        $available = $userDisk["quota"] * 1024;

        $overquota = 0;
        // fix to correspond to new quota scheme, negative number means no quota set
        // 0 means 0, and any positive number is that number
        if ($available < 0) {
            $home = $CI->cceClient->getObject(
                        'Disk', 
                        array('mountPoint' => $user['volume'])
                    );

            // $user['volume'] may be incorrectly set to /home if we have no /home. Try / instead then:
            if ((!isset($home['total'])) && (!isset($home['used']))) {
                $home = $CI->cceClient->getObject(
                            'Disk', 
                            array('mountPoint' => '/')
                        );
            }

            // If we then still have nothing, we set defaults:
            if ((!isset($home['total'])) && (!isset($home['used']))) {
                $available = 0;
                $free = 0;
                $percentage = 0;
            }

            $available = $home['total'] - $home['used'];
            $free = $available;
            $percentage = 0;
        } 
        else {
            // calculate free space for user and if they are over quota
            if (($available - $used) >= 0) {
                $free = $available-$used;
            }
            else {
                $overquota = 1;
                $free = 0;
            }

            // find out percentage used
            $percentage = round(100 * $used / $available);
            // don't show percentages greater than 100 because it 
            // could go way off the screen
            if ($percentage > 100) {
                $percentage = 100;
            }
        }

        // convert into MB / GB, TB:
        $available = simplify_number_diskspace($available, "kb", "2", "B");
        $used = simplify_number_diskspace($used, "kb", "2", "B");
        if ($used == "") {
            $used = "0B";
        }
        $free = simplify_number_diskspace($free, "kb", "2", "B");

        // Show over quota notification:
        if ($overquota) {
            $errors[] = ErrorMessage($i18n->get("[[base-disk.userOverQuota]]") . ": " . $i18n->get("[[base-disk.overQuotaMsg]]"));
        }

        //-- Generate page:

        // Prepare Page:
        $BxPage->setFormUrl($_SERVER['PHP_SELF']);
        $BxPage->setErrors($errors);

        $page_module = 'base_personalProfile';

        //
        //--- Define the PageBlock and our Tabs:
        //

        $defaultPage = "pageID";
        $block = $factory->getPagedBlock($i18n->get("diskUsageFor", "base-disk", array("userName" => $BX_SESSION['loginName'])), array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);           
        $block->setLabel($factory->getLabel('diskUsageFor', false, array('userName' => $BX_SESSION['loginName'])));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");

        $groupUsed = $factory->getInteger('userDiskUsed', $used, 'base-disk', '', 'r');
        $groupUsed->setPreserveData(false);
        $block->addFormField($groupUsed, $factory->getLabel('userDiskUsed'), 'pageID');

        $groupFree = $factory->getInteger('userDiskFree', $free, 'base-disk', '', 'r');
        $groupFree->setPreserveData(false);
        $block->addFormField($groupFree, $factory->getLabel('userDiskFree'), 'pageID');

        $disk_bar = $factory->getBar("userDiskPercentage", floor($percentage), "");
        $disk_bar->setBarText($i18n->getHtml("[[base-disk.userDiskPercentage_moreInfo]]", false, array("percentage" => $percentage, "used" => $used, "total" => $available)));
        $disk_bar->setLabelType("quota");
        $disk_bar->setHelpTextPosition("bottom");   

        $block->addFormField(
                $disk_bar,
                $factory->getLabel('groupDiskPercentage'),
                'pageID'
                );

        // Print Page:
        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }       
}
/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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