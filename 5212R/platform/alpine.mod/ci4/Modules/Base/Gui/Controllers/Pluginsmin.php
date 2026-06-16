<?php 
namespace Gui\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
use I18n;

//class Vsite extends Controller
class Pluginsmin extends BaseController {
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

        $debug = FALSE;

        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        $locale = $BX_SESSION['locale'];
        $localization = $BX_SESSION['localization'];
        $charset = $BX_SESSION['charset'];

        $i18n = new I18n("palette", $locale);

        // Prepare the messages output for our jQuery script:

        // These are for the checks that are already included in the stock validator.js:
        $messages = array(
                    'addAll' => $i18n->get("[[palette.addAll]]"),
                    'removeAll' => $i18n->get("[[palette.removeAll]]"),
                    'itemsCount' => $i18n->get("[[palette.itemsCount]]")
            );

        // Set Headers:
        $this->response->noCache();
        $this->response->setContentType('application/javascript');
        $this->response->setHeader('Cache-Control', 'must-revalidate');

        // Show the HTML Page:
        if ($debug === FALSE) {
            return view('../../Modules/Base/Gui/Views/pluginsmin', $messages);
        }
        else {
            return view('../../Modules/Base/Gui/Views/pluginsmax', $messages);
        }
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