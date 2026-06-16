<?php
/**
 * CceApiClient.php
 *
 * BlueOnyx CceApiClient for Codeigniter
 *
 * @package   CceApiClient
 * @author    Michael Stauber
 * @copyright Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
 * @copyright Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
 * @link      http://www.blueonyx.it
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   1.0
 */

class CceApiClient {

    private $ip = '127.0.0.1';
    private $port = '9092';
    private $DEBUG = false;

    // Username:
    private $Username;

    // SessionId:
    private $SessionId;

    // Password:
    private $Password;

    // To keep curl open:
    private $curlHandle = null;

    function __construct($ip = null, $port = null) {
        if ($ip) $this->ip = $ip;
        if ($port) $this->port = $port;
        $this->loadDefaultsFromConfig();
        if (is_file("/etc/DEBUG")) {
            $this->DEBUG = true;
        }
    }

    // Destructor to take curl down:
    function __destruct() {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
        }
    }

    function loadDefaultsFromConfig() {
        $conf = '/etc/cced-api/config/cced-api.conf';
        if (is_readable($conf)) {
            $lines = file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^listen_address\s*=\s*(.*)$/', $line, $matches)) {
                    list($ip, $port) = explode(':', $matches[1]);
                    $this->ip = $ip;
                    $this->port = $port;
                    break;
                }
            }
        }
    }

    function getApiUrl() {
        return "https://{$this->ip}:{$this->port}/v2/cce";
    }

    function clean_payload($arr) {
        return array_filter($arr, function ($v) {
            if (is_array($v)) return count($v) > 0;
            return $v !== "" && $v !== null;
        });
    }

    function callApi($payload) {
        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode($payload));
        }

        $payload = $this->clean_payload($payload);

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Reuse cURL handle
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
            curl_setopt($this->curlHandle, CURLOPT_URL, $this->getApiUrl());
            curl_setopt($this->curlHandle, CURLOPT_POST, true);
            curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($this->curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->curlHandle, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($this->curlHandle, CURLOPT_FORBID_REUSE, false);   // allow reuse
            curl_setopt($this->curlHandle, CURLOPT_FRESH_CONNECT, false);  // allow keep-alive
        }

        curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, $json);
        $response = curl_exec($this->curlHandle);

        if ($response === false && $this->DEBUG) {
            bx_error_log("CceApiClient::callApi() CURL ERROR: " . curl_error($this->curlHandle));
        }

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi() response: $response");
        }

        return json_decode($response, true);
    }

    function auth($username, $password) {
        if ($this->DEBUG) {
            bx_error_log("CceApiClient::auth(): Command: AUTH \"$username\" \"XXXX\"");
        }

        if ((empty($username)) && (empty($password))) {
            bx_error_log("CceApiClient::auth(): WARNING: AUTH with missing credentials!");
        }

        $return_msg = $this->callApi(['cmd' => 'AUTH', 'user' => $username, 'password' => $password]);

        if ((isset($return_msg['data']['sessionid'])) && (!empty($return_msg['data']['sessionid']))) {
            $this->setUsername($username);
            bx_error_log("CceApiClient::auth(): sessionid: " . $return_msg['data']['sessionid']);
            $this->setSessionId($return_msg['data']['sessionid']);
        }

        return $this->getSessionId();
    }

    function authkey($username, $sessionid) {
        // Fast return in case we don't have a username or sessionId:
        if (($username == '') || ($sessionid == '')) {
            $this->setUsername($username);
            $this->setSessionId('');
            bx_error_log("CceApiClient::authkey(): NOT running ccephp_authkey() with username and/or sessionid empty!");
            return '';
        }

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::authkey(): Command: AUTHKEY \"$username\" \"$sessionid\"");
        }

        $return_msg = $this->callApi(['cmd' => 'AUTHKEY', 'user' => $username, 'sessionid' => $sessionid]);

        if ($return_msg['status'] == '201') {
            $this->setUsername($username);
            $this->setSessionId($sessionid);
            return $sessionid;
        }
        bx_error_log("CceApiClient::authkey() No sessionid. Zapping it.");
        $this->setUsername($username);
        $this->setSessionId('');
        return '';
    }

    function whoami($username, $sessionid) {
        $payload = ['cmd' => 'WHOAMI'];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'WHOAMI',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function bye($username, $sessionid) {
        $payload = ['cmd' => 'BYE'];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'BYE',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function begin($username, $sessionid) {
        $payload = ['cmd' => 'BEGIN'];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'BEGIN',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function commit($username, $sessionid) {
        $payload = ['cmd' => 'COMMIT'];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'COMMIT',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function endkey($username, $sessionid) {
        $payload = ['cmd' => 'ENDKEY'];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'ENDKEY',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function create($username, $sessionid, $class, $vars) {
        $payload = [
            'cmd' => 'CREATE',
            'class' => $class,
            'data' => $vars
        ];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'CREATE',
                'user' => $username,
                'sessionid' => $sessionid,
                'class' => $class,
                'data' => $vars
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function destroy($username, $sessionid, $oid) {
        $payload = [
            'cmd' => 'DESTROY',
            'oid' => $oid
        ];
        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'DESTROY',
                'user' => $username,
                'sessionid' => $sessionid,
                'oid' => $oid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function find($username, $sessionid, $class, $vars) {
        $payload = [
            'cmd' => 'FIND',
            'class' => $class,
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;
        if (!empty($vars)) $payload['args'] = $vars;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([$payload]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function findx($username, $sessionid, $class, $vars, $revars = [], $sorttype = "", $sortprop = "") {
        $payload = [
            'cmd' => 'FINDX',
            'class' => $class,
            'args' => $vars
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;
        if (!empty($revars)) $payload['regex_args'] = $revars;
        if (!empty($sorttype)) $payload['sorttype'] = $sorttype;
        if (!empty($sortprop)) $payload['sortprop'] = $sortprop;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'FINDX',
                'user' => $username,
                'sessionid' => $sessionid,
                'class' => $class,
                'args' => $vars,
                'regex_args' => $revars,
                'sorttype' => $sorttype,
                'sortprop' => $sortprop
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function get($username, $sessionid, $oid, $namespace = "") {
        $payload = [
            'cmd' => 'GET',
            'oid' => $oid
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;
        if (!empty($namespace)) $payload['namespace'] = $namespace;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'GET',
                'user' => $username,
                'sessionid' => $sessionid,
                'oid' => $oid,
                'namespace' => $namespace
            ]));

            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function getObject($username = "", $sessionid = "", $class, $vars = array(), $namespace = "") {
        // Use internal defaults if not passed
        if (empty($username)) {
            $username = $this->username ?? '';
        }
        if (empty($sessionid)) {
            $sessionid = $this->sessionid ?? '';
        }
        if (!empty($vars)) {
            $payload['args'] = $vars;
        }

        $payload = array(
            'cmd' => 'GETOBJECT',
            'class' => $class,
            'namespace' => $namespace
        );

        // Include user/sessionid ONLY if both are present
        if (!empty($username) && !empty($sessionid)) {
            $payload['user'] = $username;
            $payload['sessionid'] = $sessionid;
        }

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): GETOBJECT payload: " . json_encode($payload));
        }

        $response = $this->callApi($payload);

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): GETOBJECT response: " . json_encode($response));
        }

        if (isset($response['status']) && $response['status'] == 201) {
            if (isset($response['data']['DATA'])) {
                return $response['data']['DATA'];
            }
            return $response['data'];
        }

        return null;
    }

    function getAll($username, $sessionid, $class_or_oids, $args = []) {
        if ($this->DEBUG) {
            bx_error_log("CceApiClient::getAll(): class_or_oids = " . json_encode($class_or_oids) . ", args = " . json_encode($args));
        }

        $payload = [
            'cmd' => 'GETALL',
            'user' => $username,
            'sessionid' => $sessionid,
        ];

        // Decide between class-based or OID-based request
        if (is_array($class_or_oids)) {
            $payload['oids'] = $class_or_oids;
        }
        else {
            $payload['class'] = $class_or_oids;
            $payload['args'] = $args;
        }

        $response = $this->callApi($payload);

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::getAll() RAW response: " . json_encode($response));
        }

        // Return only the 'objects' sub-array, or an empty array if missing
        return $response['data']['objects'] ?? [];
    }

    function set($username, $sessionid, $oid, $namespace, $vars) {
        if (!empty($namespace)) {
            $cmd = "SET $oid . $namespace";
        }
        else {
            $cmd = "SET $oid";
        }

        foreach ($vars as $k => $v) {
            // Properly escape all control characters
            $safeVal = str_replace(
                ["\\", "\"", "\n", "\r", "\t", "\f", "\b", "\a"],
                ["\\\\", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b", "\\a"],
                $v
            );
            $cmd .= " $k=\"$safeVal\"";
        }

        $payload = [
            'cmd' => $cmd
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function names($username, $sessionid, $oidOrClass) {
        $payload = [
            'cmd' => 'NAMES',
            'oid' => $oidOrClass
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'NAMES',
                'user' => $username,
                'sessionid' => $sessionid,
                'oid' => $oidOrClass
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function suspended($username, $sessionid) {
        $payload = [
            'cmd' => 'SUSPENDED'
        ];

        if (!empty($username)) $payload['user'] = $username;
        if (!empty($sessionid)) $payload['sessionid'] = $sessionid;

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode([
                'cmd' => 'SUSPENDED',
                'user' => $username,
                'sessionid' => $sessionid
            ]));
            bx_error_log("CceApiClient::callApi(): FILTERED: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function suspend($username, $sessionid, $reason = '') {
        $payload = [
            'cmd' => 'ADMIN SUSPEND',
            'user' => $username,
            'sessionid' => $sessionid,
        ];

        if (!empty($reason)) {
            $payload['reason'] = $reason;
        }

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function resume($username, $sessionid) {
        $payload = [
            'cmd' => 'ADMIN RESUME',
            'user' => $username,
            'sessionid' => $sessionid,
        ];

        if ($this->DEBUG) {
            bx_error_log("CceApiClient::callApi(): RAW: " . json_encode($payload));
        }

        return $this->callApi($payload);
    }

    function ping() {
        return $this->callApi(['cmd' => 'PING']);
    }

    function setUsername($Username = "") {
        $this->Username = $Username;
        $CI = & get_instance();
        $CI->setBX_SESSION_loginName($Username);
    }

    function getUsername() {
        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();

        if ((!isset($this->Username)) && (isset($BX_SESSION['loginName']))) {
            $cookie_loginName = $BX_SESSION['loginName'];
            if (isset($cookie_loginName)) {
                $this->Username = $BX_SESSION['loginName'];
            }
            else {
                $this->Username = $BX_SESSION['loginName'];
            }
        }
        return $this->Username;
    }

    function setSessionId($SessionId = "") {
        $this->SessionId = $SessionId;
        $CI = & get_instance();
        $CI->setBX_SESSION_sessionId($SessionId);
        if ($this->DEBUG) {
            bx_error_log("Setting sessionid: $SessionId");
        }
    }

    function getSessionId() {
        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        if (!isset($this->SessionId)) {
            $this->SessionId = $BX_SESSION['sessionId'];
        }
        return $this->SessionId;
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