<?php
namespace App\Libraries;

/**
 * TwoFactorBackupCodes - Secure backup code generation and validation
 * 
 * Generates cryptographically secure backup codes
 * Tracks usage to prevent replay attacks
 */
class TwoFactorBackupCodes {
    
    private $codeLength;
    private $codeCount;
    
    public function __construct(int $codeLength = 8, int $codeCount = 10) {
        $this->codeLength = $codeLength;
        $this->codeCount = $codeCount;
    }
    
    /**
     * Generate a set of backup codes
     * 
     * @return array Array of codes with metadata
     */
    public function generateCodes(): array {
        $codes = [];
        
        for ($i = 0; $i < $this->codeCount; $i++) {
            $code = $this->generateSingleCode();
            $codes[] = [
                'code' => $code,
                'used' => false,
                'created_at' => time()
            ];
        }
        
        return $codes;
    }
    
    /**
     * Validate a backup code against stored codes
     * Marks code as used if valid
     * 
     * @param string $inputCode Code to validate
     * @param array $storedCodes Array of stored code data
     * @return array ['valid' => bool, 'codes' => updatedCodes]
     */
    public function validateCode(string $inputCode, array $storedCodes): array {
        $normalizedInput = $this->normalizeCode($inputCode);
        
        foreach ($storedCodes as $index => $codeData) {
            if ($codeData['used']) {
                continue;
            }
            
            $storedCode = $this->normalizeCode($codeData['code']);
            
            // Constant-time comparison
            if ($this->constantTimeEquals($storedCode, $normalizedInput)) {
                // Mark as used
                $storedCodes[$index]['used'] = true;
                $storedCodes[$index]['used_at'] = time();
                
                return [
                    'valid' => true,
                    'codes' => $storedCodes
                ];
            }
        }
        
        return [
            'valid' => false,
            'codes' => $storedCodes
        ];
    }
    
    /**
     * Check if any unused codes remain
     * 
     * @param array $codes Array of code data
     * @return int Count of unused codes
     */
    public function countUnused(array $codes): int {
        $count = 0;
        foreach ($codes as $codeData) {
            if (!$codeData['used']) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Serialize codes for storage (JSON)
     * 
     * @param array $codes Array of code data
     * @return string JSON string
     */
    public function serialize(array $codes): string {
        return json_encode($codes);
    }
    
    /**
     * Deserialize codes from storage
     * 
     * @param string $data JSON string
     * @return array|null Array of code data or null on error
     */
    public function deserialize(string $data): ?array {
        $codes = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $codes;
    }
    
    /**
     * Generate a single secure backup code
     * Format: XXXX-XXXX (alphanumeric)
     */
    private function generateSingleCode(): string {
        // Use secure random bytes
        $bytes = random_bytes($this->codeLength);
        
        // Convert to alphanumeric (base-32 like)
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed ambiguous chars
        $code = '';
        
        for ($i = 0; $i < $this->codeLength; $i++) {
            $code .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        
        return $code;
    }
    
    /**
     * Normalize code for comparison
     * Remove dashes, spaces, convert to uppercase
     */
    private function normalizeCode(string $code): string {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    }
    
    /**
     * Constant-time string comparison
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