<?php
/**
 * AiApi - Internal API for other modules
 * Provides read-only AI function execution for cross-module integration.
 * Only serverAdministrator can use this.
 */
namespace Ai\Controllers;

use App\Controllers\BaseController;
include_once(__DIR__ . "/../Config/Constants.php");

class AiApi extends BaseController
{
    private function getAiServiceAuthKey(): string
    {
        $CI = get_instance();
        $System = $CI->getSystem();
        $ai_config = $CI->cceClient->get($System['OID'], "AI");
        if (!is_array($ai_config)) {
            return '';
        }

        return trim((string)($ai_config['service_api_key'] ?? ''));
    }

    public function execute()
    {
        $CI = get_instance();

        if (!$CI->getAllowed('serverAdministrator')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $function_name = $this->request->getPost('function');
        $args = $this->request->getPost('args') ?? [];

        if (!$function_name) {
            return $this->response->setJSON(['error' => 'No function specified'])->setStatusCode(400);
        }

        $user = session()->get('user');
        // For future Tier 2 functions, run_as would be the user's UID.
        // For now, Tier 1 read-only only.
        $run_as = 'blueonyx_ai';
        $serviceAuthKey = $this->getAiServiceAuthKey();
        if ($serviceAuthKey === '') {
            return $this->response->setJSON(['error' => 'AI service auth key missing'])->setStatusCode(503);
        }

        $service_url = AI_SERVICE_URL . '/function';
        $payload = json_encode([
            'function' => $function_name,
            'args' => $args,
            'run_as' => $run_as,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $service_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-BlueOnyx-AI-Auth: ' . $serviceAuthKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->response->setJSON(['error' => "AI service unavailable: $error"])->setStatusCode(503);
        }

        return $this->response->setJSON(json_decode($response, true))->setStatusCode($http_code);
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
