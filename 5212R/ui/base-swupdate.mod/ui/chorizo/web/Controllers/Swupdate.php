<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Swupdate extends BaseController {
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
    public function news() {

        //helper(['form']);

        $CI =& get_instance();

        if (!$CI->getAllowed('managePackage')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        // Invalidate Redis-Cache:
        $CI->redisZapCache(true);

        // Prepare Page:
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-yum", "/swupdate/news");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Generate Software-Updates page:
        //

        // Don't poll via get_updates.pl. Instead use CODB's last result:
        // Do we have any PKGs listed in CODB that are visible and have the 'new' flag set?
        $search = array('new' => '1', 'isVisible' => '1', 'installState' => 'Available');
        $oids = $CI->cceClient->findNSorted("Package", 'version', $search);
        if (count($oids) > "0") {
            $errors[] = ErrorMessage($i18n->get('[[base-swupdate.UpdatesAvailablePackagesBody]]'), 'alert_red', 'alarm_bell', TRUE);
        }

        //
        //-- Generate News page:
        //

        $BxPage->setExtraHeaders('
                <script>
                    $(document).ready(function() {
                        $(".various").fancybox({
                            overlayColor: "#000",
                            fitToView   : false,
                            width       : "80%",
                            height      : "80%",
                            autoSize    : false,
                            fixed       : false,
                            closeClick  : false,
                            openEffect  : "none",
                            closeEffect : "none"
                        });
                    });
                </script>');

        $BxPage->setVerticalMenu('base_swupdate');
        $page_module = 'base_sysmanage';

        //
        //--- RSS Feed Handling:
        //

        $have_good_rss_cache = FALSE;

        if (is_file('/usr/sausalito/license/rss-news.cache')) {
            $rss_cache = file_get_contents('/usr/sausalito/license/rss-news.cache');

            // Json-decode it:
            $rss_cache = @json_decode($rss_cache, true);

            // Check if we have data in expected format:
            if ((isset($rss_cache['time'])) && (isset($rss_cache['rss']))) {
                // Cache expires after one day:
                if ($rss_cache['time'] + 86400 > time()) {
                    $have_good_rss_cache = TRUE;
                    $news = $rss_cache['rss'];
                }
            }
        }

        // We don't have good cache data. So we pull the news live:
        if ($have_good_rss_cache == FALSE) {

            // Location (URL) of the RSS feed:
            $rsslocation = 'https://www.blueonyx.it/news.rss';

            // Check if we are online:
            if (areWeOnline($rsslocation, "20")) {
                $online = "1";
            }
            else {
                $online = "0";
                $errors[] = ErrorMessage($i18n->get('[[base-yum.ErrorMSGdesc]]'), 'alert_red', 'alert_2', TRUE);
            }

            if ($online == "1") {
                // Process the RSS feed:
                $news = getRssfeed($rsslocation,"BlueOnyx News","auto",50,3);

                if (isset($news["_bx_title"])) {
                    // Update Cache:
                    $cache_dir = '/usr/sausalito/license';
                    if (is_dir($cache_dir)) {
                        $rss_cache_file = $cache_dir . "/rss-news.cache";

                        // Create an Array with the cache content:
                        $cache_data['time'] = time();
                        $cache_data['rss'] = $news;

                        // Json encode the array:
                        $cache_content = json_encode($cache_data);

                        // Write the new cache file out to disk:
                        if (write_file($rss_cache_file, $cache_content)) {
                            $text = "RSS News Feed Cache updated.";
                        }
                    }
                }
            }
        }

        // News are now stored in this format:
        //
        // $news["_bx_title"] : Titles
        // $news["_bx_date"]  : Date
        // $news["_bx_desc"]  : Short description
        // $news["_bx_link"]  : Link

        if ((!isset($news["_bx_title"])) || (!is_array($news))) {
            // Although we can establish a connection to www.blueonyx.it, the RSS feed did not return expected results:
            $errors[] = ErrorMessage($i18n->getHtml("[[base-yum.ErrorMSGdesc]]"), 'alert_red', 'alarm_bell', TRUE);
            $news = array();
        }
        // Can't get News for whatever reason:
        elseif ($news["_bx_title"] == "n/a") {
            // Although we can establish a connection to www.blueonyx.it, the RSS feed did not return expected results:
            $errors[] = ErrorMessage($i18n->getHtml("[[base-yum.ErrorMSGdesc]]"), 'alert_red', 'alarm_bell', TRUE);
        }
        else {
            // General parameters for the scroll list:

            // Count number of news-entries:
            $bx_num = count($news["_bx_title"]);

            // Build multidimensional array of our news:
            $news = array($news["_bx_title"], $news["_bx_desc"], $news["_bx_date"], $news["_bx_link"]);

            // Loop through array $news and extract the news to populate the scroll list rows:
            $num = "0";
            while ($num < $bx_num) {
                // Create the image link button for the external news article URL:
                preg_match_all("/articleid=(.*)&(.*)/Uism", $news[3][$num], $article_id);
                $exturl = $news[3][$num];

                $fancyButton = $factory->getFancyButton($exturl, '[[base-yum.openURL_help]]');
                $fancyButton->setButtonSize("small");
                $fancyButton->setTarget('_blank');
                $news[3][$num] = $fancyButton->toHtml();

                $linkButton = $factory->getUrlButton($exturl);
                $linkButton->setButtonSize("small");
                $linkButton->setTarget('new_window');
                $news[4][$num] = $linkButton->toHtml();
                $num++;
            }
        }

        if (!isset($news)) {
            $news = array();
            $errors[] = ErrorMessage($i18n->getHtml("[[base-yum.ErrorMSGdesc]]"), 'alert_red', 'alarm_bell', TRUE);
        }

        // Pass on errors:
        $BxPage->setErrors($errors);

        $scrollList = $factory->getScrollList("TheNews", array("title", "desc", "date", "internal", 'link'), $news); 
        $scrollList->setAlignments(array("left", "left", "center", "right", "right"));
        $scrollList->setDefaultSortedIndex('2');
        $scrollList->setSortOrder('descending');
        $scrollList->setSortDisabled(array('3', '4'));
        $scrollList->setPaginateDisabled(FALSE);
        $scrollList->setSearchDisabled(FALSE);
        $scrollList->setSelectorDisabled(FALSE);
        $scrollList->enableAutoWidth(FALSE);
        $scrollList->setInfoDisabled(FALSE);
        $scrollList->setColumnWidths(array("15%", "55%", "100", "35", "35"));

        $page_body[] = '';

        //
        //--- Define the PageBlock and our Tabs:
        //

        $EmailTraffic = $i18n->get("RSS_News_Menu");

        $block = $factory->getPagedBlock($EmailTraffic, array('defaultPage'));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs("#");

        //--- Stats
        if (($CI->getAllowed('serverShowActiveMonitor')) && ($CI->BX_SESSION['gui_theme'] === 'elmer')) {
            $page_body[] = StartPageStats($CI->cceClient, $BxPage, $i18n);
        }
        //--- /Stats

        //--- Paypal Donations
        $donations_html = $factory->getRawHTML('donations', Donations($i18n, $CI->BX_SESSION['gui_theme']));
        $block->addFormField(
            $donations_html,
            $factory->getLabel("donations_html"),
            'defaultPage'
        );
        //--- /Paypal Donations

        $out_scrollList = $factory->getRawHTML("news", $scrollList->toHtml());
        $block->addFormField(
            $out_scrollList,
            $factory->getLabel("news"),
            'defaultPage'
        );

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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