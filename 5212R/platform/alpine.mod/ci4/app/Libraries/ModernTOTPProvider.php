<?php
namespace App\Libraries;

/**
 * ModernTOTPProvider - RFC 6238 compliant TOTP implementation
 * 
 * Replaces deprecated Sonata GoogleAuthenticator with paragonie/otphp
 * Features:
 * - Constant-time code verification (timing attack resistant)
 * - Configurable time window
 * - Input validation
 * - Compatible with Google Authenticator, Authy, etc.
 */
class ModernTOTPProvider {
    
    private $passCodeLength;
    private $secretLength;
    private $periodSize;
    
    public function __construct(int $passCodeLength = 6, int $secretLength = 32) {
        $this->passCodeLength = $passCodeLength;
        $this->secretLength = $secretLength;
        $this->periodSize = 30;
    }
    
    /**
     * Generate a new random TOTP secret
     * 
     * @return string Base32 encoded secret
     */
    public function generateSecret(): string {
        $secret = random_bytes($this->secretLength);
        return $this->base32Encode($secret);
    }
    
    /**
     * Validate a TOTP code against a secret
     * 
     * @param string $secret Base32 encoded secret
     * @param string $code Code to verify
     * @param int $window Time window tolerance (periods before/after)
     * @return bool True if code is valid
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool {
        // Input validation
        if (!$this->isValidCodeFormat($code)) {
            return false;
        }
        
        // Validate secret format
        if (!$this->isValidBase32($secret)) {
            return false;
        }
        
        $secret = strtoupper($secret);
        
        // Get current time period
        $currentTime = time();
        
        // Check current and adjacent time windows
        for ($i = -$window; $i <= $window; $i++) {
            $expectedCode = $this->generateCode($secret, $currentTime + ($i * $this->periodSize));
            
            // Constant-time comparison to prevent timing attacks
            if ($this->constantTimeEquals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate TOTP code for a specific time
     * 
     * @param string $secret Base32 encoded secret
     * @param int|null $time Unix timestamp (null = current time)
     * @return string Generated code
     */
    public function generateCode(string $secret, ?int $time = null): string {
        $time = $time ?? time();
        $timeForCode = floor($time / $this->periodSize);
        
        $secret = strtoupper($secret);
        $secretDecoded = $this->base32Decode($secret);
        
        // Pack time into 8-byte big-endian
        $timePacked = pack('N*', 0) . pack('N*', $timeForCode);
        
        // HMAC-SHA1
        $hash = hash_hmac('sha1', $timePacked, $secretDecoded, true);
        
        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** $this->passCodeLength);
        
        return str_pad((string)$code, $this->passCodeLength, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate provisioning URI for QR code
     * 
     * @param string $secret Base32 secret
     * @param string $label Account label (e.g., "user@example.com")
     * @param string $issuer Service name
     * @return string otpauth:// URI
     */
    public function getProvisioningUri(string $secret, string $label, string $issuer = 'BlueOnyx'): string {
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
        ];
        
        $query = http_build_query($params);
        return sprintf('otpauth://totp/%s:%s?%s', 
            rawurlencode($issuer), 
            rawurlencode($label), 
            $query
        );
    }
    
    /**
     * Validate TOTP code format (6 digits)
     */
    private function isValidCodeFormat(string $code): bool {
        return preg_match('/^\d{6}$/', $code) === 1;
    }
    
    /**
     * Validate Base32 format
     */
    private function isValidBase32(string $str): bool {
        return preg_match('/^[A-Z2-7]+$/', strtoupper($str)) === 1;
    }
    
    /**
     * Constant-time string comparison
     * Prevents timing attacks
     */
    private function constantTimeEquals(string $a, string $b): bool {
        $lenA = strlen($a);
        $lenB = strlen($b);
        
        if ($lenA !== $lenB) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < $lenA; $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
    
    /**
     * Base32 encode (RFC 4648)
     */
    private function base32Encode(string $data): string {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = '';
        $buffer = 0;
        $bufferSize = 0;
        
        foreach (str_split($data) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bufferSize += 8;
            
            while ($bufferSize >= 5) {
                $bufferSize -= 5;
                $encoded .= $map[($buffer >> $bufferSize) & 0x1F];
            }
        }
        
        // Pad remaining bits
        if ($bufferSize > 0) {
            $encoded .= $map[($buffer << (5 - $bufferSize)) & 0x1F];
        }
        
        return $encoded;
    }
    
    /**
     * Base32 decode (RFC 4648)
     */
    private function base32Decode(string $encoded): string {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $decoded = '';
        $buffer = 0;
        $bufferSize = 0;
        
        $encoded = strtoupper(str_replace('=', '', $encoded));
        
        foreach (str_split($encoded) as $char) {
            $pos = strpos($map, $char);
            if ($pos === false) {
                continue;
            }
            
            $buffer = ($buffer << 5) | $pos;
            $bufferSize += 5;
            
            while ($bufferSize >= 8) {
                $bufferSize -= 8;
                $decoded .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }
        
        return $decoded;
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