<?php
namespace Remote\Controllers;

use App\Controllers\BaseController;

class ShellAuth extends BaseController
{
    private const TOKEN_DIR = '/usr/sausalito/sessions/shellinabox-tokens';
    private const TOKEN_TTL = 600;

    public function auth()
    {
        $CI = get_instance();
        $session = $CI->getBX_SESSION();

        if (!$CI->getAllowed('validUser')) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(401)->setBody('Unauthorized');
        }

        $loginUser = isset($session['loginUser']) && is_array($session['loginUser'])
            ? $session['loginUser'] : array();
        $loginName = isset($session['loginName']) ? (string) $session['loginName'] : '';

        $userShell = $CI->cceClient->getObject(
            'User',
            array('name' => $loginName),
            'Shell'
        );
        $vsiteShell = $CI->cceClient->getObject(
            'Vsite',
            array('name' => isset($loginUser['site']) ? $loginUser['site'] : ''),
            'Shell'
        );

        $userShellEnabled = isset($userShell['enabled'])
            && (string) $userShell['enabled'] === '1';
        $vsiteShellEnabled = isset($vsiteShell['enabled'])
            && (string) $vsiteShell['enabled'] === '1';
        $isAdministrator = isset($loginUser['systemAdministrator'])
            && (string) $loginUser['systemAdministrator'] === '1';

        if (!$userShellEnabled || (!$vsiteShellEnabled && !$isAdministrator)) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        $system = $CI->getSystem();
        $remote = $CI->cceClient->get($system['OID'], 'Remote');
        if (!is_array($remote) || !isset($remote['enabled']) || $remote['enabled'] !== '1') {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(403)->setBody('Service disabled');
        }

        if (!is_dir(self::TOKEN_DIR) && !mkdir(self::TOKEN_DIR, 0700, true)) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(500)->setBody('Authorization unavailable');
        }
        chmod(self::TOKEN_DIR, 0700);
        if ((fileperms(self::TOKEN_DIR) & 0777) !== 0700 || !is_writable(self::TOKEN_DIR)) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(500)->setBody('Authorization unavailable');
        }

        $token = bin2hex(random_bytes(32));
        $tokenFile = self::TOKEN_DIR . '/' . $token;
        $now = time();
        $tokenData = array(
            'version' => 1,
            'user' => $loginName,
            'session' => hash('sha256', (string) ($session['sessionId'] ?? '')),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'created' => $now,
            'expires' => $now + self::TOKEN_TTL
        );
        $tokenJson = json_encode($tokenData, JSON_UNESCAPED_SLASHES);
        if ($tokenJson === false) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(500)->setBody('Authorization unavailable');
        }

        $handle = @fopen($tokenFile, 'x');
        if ($handle === false) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(500)->setBody('Authorization unavailable');
        }
        $written = fwrite($handle, $tokenJson);
        fclose($handle);
        chmod($tokenFile, 0600);
        if ($written === false || $written !== strlen($tokenJson)) {
            @unlink($tokenFile);
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->response->setStatusCode(500)->setBody('Authorization unavailable');
        }

        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();

        return $this->response
            ->setStatusCode(302)
            ->setHeader('Location', '/bxshell/')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setCookie(
                'bxshell_auth',
                $token,
                self::TOKEN_TTL,
                '',
                '/bxshell',
                '',
                true,
                true,
                'Strict'
            )
            ->setBody('');
    }
}

/*
Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
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
