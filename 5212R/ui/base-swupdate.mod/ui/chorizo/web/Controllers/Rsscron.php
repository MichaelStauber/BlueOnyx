<?php 
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class Rsscron extends BaseController {
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

        // locale and charset setup:
        $ini_langs = initialize_languages(FALSE);
        $locale = $ini_langs['locale'];
        $localization = $ini_langs['localization'];
        $charset = $ini_langs['charset'];

        $domain = 'Compass-base';

        $serialNumber = shell_exec('/usr/sausalito/bin/getSerial.pl');

        // Directory where the cached and encrypted licenses are stored:
        $lic_dir = '/usr/sausalito/license';

        // Set headers:
        $this->response->setStatusCode(200);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-language', $locale);
        $this->response->setHeader('Content-type', "text/html; charset=$charset");

        $title = PoorMansBabelFish("wizard_refresh_header", $locale, $domain);
        $text = PoorMansBabelFish("wizard_refresh_text", $locale, $domain);

        $System = $CI->getSystem();
        $title = "BlueOnyx " . $System['productBuild'];

        //
        // Pull RSS:
        //

        // Location (URL) of the RSS feed:
        $rsslocation = 'https://www.blueonyx.it/news.rss';

        // Check if we are online:
        if (areWeOnline($rsslocation, "5")) {
            $online = "1";
        }
        else {
           $online = "0";
        }

        if ($online == "1") {
            // Process the RSS feed:
            $news = getRssfeed($rsslocation,"BlueOnyx News","auto",50,3);
        }

        if (!isset($news)) {
            $news = array();
            $text = "Unable to update the RSS news feed chache.";
        }
        else {
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
                else {
                    $text = "Unable to update the RSS news feed chache.";
                }
            }
        }

        // Show the Elmer HTML Page:
        $page_variables = array(
                                'localization' => $localization,
                                'charset' => $charset,
                                'page_title' => $title,
                                'bx_logo_color' => '#000000',
                                'elmer_style_css' => '/.elm/dist/css/style.css',
                                'extra_headers' => '',
                                'heading' => $title,
                                'text' => $text,
                                'extra_footers' => '',
                                );

        return view('../../Modules/Base/Gui/Views/elmer_minimalist_view', $page_variables);
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
?>