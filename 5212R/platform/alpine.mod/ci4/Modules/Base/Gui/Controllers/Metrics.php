<?php

/*
 * This controller polls cced-api using functions from the helper 
 * 'blueonyx_helper.php' to get usage statistics for the server.
*/

namespace Gui\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
require_once '/usr/sausalito/ui/chorizo/ci4/app/Helpers/blueonyx_helper.php';
use I18n;

class Metrics extends BaseController {

    public function __construct() {

    }

    public function index() {

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (!str_starts_with($referer, 'https://' . $_SERVER['HTTP_HOST'])) {
            // Access without an internal referer? Bye, bye:
            Log403Error("/gui/Forbidden403");
        }

        // Local call to cced-api (no auth needed from 127.0.0.1)
        $url = 'https://127.0.0.1:9092/v2/metrics';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // allow self-signed
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false);    // allow reuse
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, false);   // allow keep-alive

        $raw_data = curl_exec($ch);
        curl_close($ch);

        // Fallback logic
        if ($raw_data === false) {
            return array();  // fallback on curl error
        }

        $data = json_decode($raw_data, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $metricsData = $data;
            $shortload = array_column($metricsData['shortload'], 'value', 'name');
            $shortmem  = array_column($metricsData['shortmem'], 'value', 'name');
            $aggnet_raw = array_column($metricsData['aggnet'], 'value', 'name');

            $aggnet = [];

            $primary_interface = get_primary_interface();
            foreach ($aggnet_raw as $key => $val) {
                if (preg_match("/^(receive|transmit)_bytes_total_(br0|eth0|{$primary_interface})$/", $key)) {
                    $aggnet[$key] = $val;
                }
            }

            // Fallback if br0/eth0 don't exist
            if (empty($aggnet)) {
                // Fallback: include lo for loopback totals as a backup display
                foreach ($aggnet_raw as $key => $val) {
                    if (strpos($key, 'lo') !== false) {
                        $aggnet[$key] = $val;
                    }
                }
            }

            // Stuff the cleaned 'aggnet' back into $data:
            $data['aggnet'] = $aggnet;

            session()->set([
                'shortload' => $shortload,
                'shortmem'  => $shortmem,
                'aggnet'    => $aggnet,
            ]);
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return array();
        }

        http_response_code(200);
        $this->response->noCache();
        return $this->response->setJSON($data);
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