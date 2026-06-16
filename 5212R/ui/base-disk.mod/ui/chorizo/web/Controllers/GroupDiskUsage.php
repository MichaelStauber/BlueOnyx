<?php 
namespace Disk\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class GroupDiskUsage extends BaseController {
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

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        //
        // Access Rules:
        //

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-disk", "/disk/groupDiskUsage");
        $BxPage = $factory->getPage();
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
            $vsiteObj = $CI->cceClient->getObject('Vsite', array('name' => $group));
        }
        else {
            // Don't play games with us!
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403#2");
        }

        //
        // Access Rules:
        //

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
            Log403Error("/gui/Forbidden403#3");
        }

        if ((!$CI->getAllowed('adminUser')) && 
            (!$CI->getAllowed('siteAdmin')) && 
            (!$CI->getAllowed('manageSite')) && 
            (($user['site'] != $CI->serverScriptHelper->loginUser['site']) && $CI->getAllowed('siteAdmin')) &&
            (($vsiteObj['createdUser'] != $BX_SESSION['loginName']) && $CI->getAllowed('manageSite'))
            ) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        // -- Actual page logic start:
        //

        $url_addPart = '';

        // Find out if we display without menu or with menu:
        $fancy = FALSE;
        if (isset($get_form_data['short'])) {
            if ($get_form_data['short'] == "1") {
                $fancy = TRUE;
                $url_addPart .= '&short=1';
            }
        }
        $serverDiskUsage = "0";
        $activeMonitor = "0";
        if (isset($get_form_data['activeMonitor'])) {
            if ($get_form_data['activeMonitor'] == "1") {
                $activeMonitor = "1";
                $url_addPart .= '&activeMonitor=1';
            }
        }
        if (isset($get_form_data['serverDiskUsage'])) {
            if ($get_form_data['serverDiskUsage'] == "1") {
                $serverDiskUsage = "1";
                $url_addPart .= '&serverDiskUsage=1';
            }
        }

        //
        //--- Prepare page header:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/disk/groupDiskUsage?group=" . $group . $url_addPart);
        $BxPage->setErrors($errors);

        if ($fancy == TRUE) {       
            $BxPage->setOutOfStyle(TRUE);
        }

        if ($fancy == TRUE) {
            $page_body[] = '<br><div id="main_container" class="container_16">';
        }

        // Set Menu items:
        if ($serverDiskUsage == "1") {
            $BxPage->setVerticalMenu('base_monitor');
            $BxPage->setVerticalMenuChild('base_amStatus');
            $page_module = 'base_sysmanage';
        }
        else {
            $BxPage->setVerticalMenu('base_siteusage');
            $BxPage->setVerticalMenuChild('base_groupDiskUsage');
            $page_module = 'base_sitemanage';
        }

        //
        //--- Build Page:
        //

        $type = 'Vsite';

        list($group_oid) = $CI->cceClient->find($type, array('name' => $group));
        // refresh only this group's quota info, not all users
        $CI->cceClient->set($group_oid, 'Disk', array( 'refresh' => time()));

        // get objects
        $group_disk = $CI->cceClient->get($group_oid, 'Disk');
        $group_info = $CI->cceClient->get($group_oid);

        $am_obj = $CI->cceClient->getObject('ActiveMonitor', array(), 'Disk');

        // get group disk information
        $used = $group_disk['used']*1000;
        $available = $group_disk['quota']*1000;

        // fix to correspond to new quota scheme, negative number means no quota set
        // 0 means 0, and any positive number is that number
        if($available < 0) {
            $home = $CI->cceClient->getObject('Disk', array('mountPoint' => $group_info['volume']));
            if ((!isset($home['total'])) && (!isset($home['used']))) {
                // Fallback to root partition:
                $home = $CI->cceClient->getObject('Disk', array('mountPoint' => '/'));
            }
            $available = $home['total'] - $home['used'];
            $free = $available;
            $percentage = 0;
        }
        else {
          
            // calculate free space for group and if they are over quota
            $overquota = 0;
            if (($available - $used) >= 0) {
                $free = $available - $used;
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

        // convert into human readable format:
        $used = simplify_number_diskspace($used, "K", "2", "B");
        $free = simplify_number_diskspace($free, "K", "2", "B");
        $available = simplify_number_diskspace($available, "K", "2", "B");

        $page = $factory->getPage();

        $group_name = ($type == 'Vsite' ? $group_info['fqdn'] : $group_info['name']);

        $defaultPage = "pageID";
        $block = $factory->getPagedBlock("generateSettings", array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setDefaultPage($defaultPage);           
        $block->setLabel($factory->getLabel('groupDiskUsageFor', false, array('groupName' => $group_name)));

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");

        $groupUsed = $factory->getInteger('groupDiskUsed', $used, '', '', 'r');
        $groupUsed->setPreserveData(false);
        $block->addFormField($groupUsed, $factory->getLabel('groupDiskUsed'), 'pageID');

        $groupFree = $factory->getInteger('groupDiskFree', $free, '', '', 'r');
        $groupFree->setPreserveData(false);
        $block->addFormField($groupFree, $factory->getLabel('groupDiskFree'), 'pageID');

        $disk_bar = $factory->getBar("groupDiskPercentage", floor($percentage), "");
        $disk_bar->setBarText($i18n->getHtml("[[base-disk.userDiskPercentage_moreInfo]]", false, array("percentage" => $percentage, "used" => $used, "total" => $available)));
        $disk_bar->setLabelType("quota");
        $disk_bar->setHelpTextPosition("bottom");   

        $block->addFormField(
                $disk_bar,
                $factory->getLabel('groupDiskPercentage'),
                'pageID'
                );

        // Site system accounts: anonymous ftp usage & site-specific logs/stats
        $sysusage = $factory->getScrollList('sysQuota', array(' ', 'service', 'service_used', 'service_quota', 'serviceDiskPercentage', ' '), array());
        $sysusage->setAlignments(array('center', 'left', 'right', 'right', 'left', 'center'));
        $sysusage->setDefaultSortedIndex('1');
        $sysusage->setSortOrder('ascending');
        $sysusage->setSortDisabled(array('0', '4'));
        $sysusage->setPaginateDisabled(TRUE);
        $sysusage->setSearchDisabled(TRUE);
        $sysusage->setSelectorDisabled(FALSE);
        $sysusage->enableAutoWidth(FALSE);
        $sysusage->setInfoDisabled(TRUE);
        $sysusage->setColumnWidths(array("20", "150", "120", "120", "329", "20")); // Max: 739px

        // find site service objects
        $service_sort_index = $sysusage->getSortedIndex();
        $service_sort_prop = array(1 => 'label', 2 => 'used', 3 => 'quota');
        $service_sort_type = array(1 => 'ascii', 2 => 'old_numeric', 3 => 'old_numeric');
        $sysquotas = $CI->cceClient->findx('ServiceQuota', array('site' => $group), array(), "", "");

        if ($sysusage->getSortOrder() == 'descending') {
            $sysquotas = array_reverse($sysquotas);
        }

        $start = 0;
        for ($i = $start; ($i < count($sysquotas)); $i++) {
            $service = $CI->cceClient->get($sysquotas[$i]);
            $quota = $service['quota'];
            $service['quota'] = $service['quota'] * 1000;
            $used = sprintf("%.2f", $service['used'] / 1024);
            $percent = round(100 * $service['used'] / $service['quota']);
            $used .= "MB";

            if ($percent > 100)
                $percent = 100;

            if ($percent >= $am_obj['red_pcnt']) {
                $status = $factory->getStatusSignal('severeProblem');
            }
            elseif ($percent >= $am_obj['yellow_pcnt']) {
                $status = $factory->getStatusSignal('problem');
            }
            else {
                $status = $factory->getStatusSignal('normal');
            }

            $label = $i18n->get($service["label"]);

            $diskBar = $factory->getBar("userDiskPercentage", floor($percent), "");
            $diskBar->setBarText($percent . "%");
            $diskBar->setLabelType("nolabel");
            $diskBar->setHelpTextPosition("right");

            $sysusage->addEntry(array($status, $label, $used, simplify_number_diskspace($service["quota"], "K", "2", "B"), $diskBar, $percent), '', false);
        }

        // add on user disk usage
        $user_list = $factory->getScrollList('userQuota', array(' ', 'user', 'used', 'quota', 'userDiskPercentage', ' '), array());
        $user_list->setAlignments(array('center', 'left', 'right', 'right', 'left', 'center'));
        $user_list->setDefaultSortedIndex('1');
        $user_list->setSortOrder('ascending');
        $user_list->setSortDisabled(array('0', '4'));
        $user_list->setPaginateDisabled(FALSE);
        $user_list->setSearchDisabled(FALSE);
        $user_list->setSelectorDisabled(FALSE);
        $user_list->enableAutoWidth(FALSE);
        $user_list->setInfoDisabled(FALSE);
        $user_list->setColumnWidths(array("20", "150", "120", "120", "329", "20")); // Max: 739px

        $page_length = 15;

        $s_index = $user_list->getSortedIndex();

        $sort_map = array( 1 => 'name', 2 => 'usage', 3 => 'quota');
        $sorttype = "descending";
        $site = $group;
        $order = ($user_list->getSortOrder() == 'descending') ? '--descending' : '--ascending';

        $cmd = "/usr/sausalito/sbin/get_quotas.pl --sort=$sorttype --site=$group $order";
        $handle = $CI->serverScriptHelper->popen($cmd, "r", "root");

        $users = array();
        while (!feof($handle)) {
          $string = fgets($handle, 256);
          $string = chop($string);
          if (!$string) {
              // empty lines don't count
              continue;
          }
          $pieces = preg_split("/\s+/", $string);
          $users[] = $pieces;
        }

        for ($i = 0; $i < count($users); $i++) {
            $user_info = $users[$i];

            if (isset($user_info[0])) {
                $name = $user_info[0];
            }
            else {
                $name = "n/a";
            }
            if (isset($user_info[1])) {
                $used = $user_info[1];
            }
            else {
                $used = "0";
            }
            if (isset($user_info[2])) {
                $quota = $user_info[2];
            }
            else {
                $quota = "0";
            }

            $used_orig = $used;
            $quota_orig = $quota;
            
            $used = sprintf("%.2f", $used / 1024); // convert into megs
            $quota = sprintf("%.2f", $quota / 1024); // convert into megs
            
            // quota <= 0 means unlimited
            if ($quota_orig > 0) {
                $percent = round(100 * $used_orig / $quota_orig);
            }
            else {
                $percent = 0;
            }

            if ($percent > 100) {
                $percent = 100;
            }

            // quota <= 0 means unlimited
            if ($quota > 0) {
              $quota_field = $factory->getInteger("bar$i", $quota, '', '', 'r');
              $quota_field->setPreserveData(false);
            }
            else {
              $i18n = $factory->getI18n();
              $quota_field = $factory->getTextField("total$i", $i18n->interpolateHtml('[[base-disk.unlimited]]'), 'r');
              $quota_field->setPreserveData(false);
            }
              
            if ($percent >= $am_obj['red_pcnt']) {
                $status = $factory->getStatusSignal('severeProblem');
            }
            else if ($percent >= $am_obj['yellow_pcnt']) {
                $status = $factory->getStatusSignal('problem');
            }
            else {
                $status = $factory->getStatusSignal('normal');
            }      

            $NameUrl = "/user/userMod?group=" . urlencode($group) . '&name=' . urlencode($name);
            $nameLink = $factory->getUrl($i, $NameUrl, $name, '', 'r');
              
            $used_field = $factory->getInteger("foo$i", $used, '', '', 'r');
            $used_field->setPreserveData(false);

            $diskBar = $factory->getBar("userDiskPercentage$name", floor($percent), "");
            $diskBar->setBarText($percent . "%");
            $diskBar->setLabelType("nolabel");
            $diskBar->setHelpTextPosition("right");

            $user_list->addEntry(array($status, $nameLink, $used . "MB", $quota . "MB", $diskBar, $percent ), false, $i);
        }

        // Print Page:
        $page_body[] = $block->toHtml();
        $page_body[] = $sysusage->toHtml();
        $page_body[] = $user_list->toHtml();

        if ($fancy == TRUE) {
            $page_body[] = '</div>';
        }
        elseif ($serverDiskUsage = "1") {
            // Don't show "Back" Button:
        }
        else {
            // Full page display. Show "Back" Button:
            $page_body[] = am_back($factory);
        }

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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