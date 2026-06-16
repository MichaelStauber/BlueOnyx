<?php
namespace App\Libraries;

/**
 * TwoFactorEncryption - Encrypt/decrypt 2FA secrets
 * 
 * Uses AES-256-GCM with a per-user key stored in CODB.
 * Falls back to the historical host-bound key if no per-user key exists yet.
 */
class TwoFactorEncryption {
    
    private $cipher = 'aes-256-gcm';
    private $key;
    
    public function __construct(?string $storageKey = null) {
        $this->key = $this->resolveKey($storageKey);
    }

    public static function generateStorageKey(): string {
        return base64_encode(random_bytes(32));
    }
    
    /**
     * Encrypt data
     * 
     * @param string $plaintext Data to encrypt
     * @return string Base64 encoded ciphertext (iv + tag + ciphertext)
     */
    public function encrypt(string $plaintext): string {
        $iv = random_bytes(12); // GCM recommended IV length
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // GCM tag length
        );
        
        if ($ciphertext === false) {
            throw new \Exception('Encryption failed');
        }
        
        // Combine iv + tag + ciphertext
        $combined = $iv . $tag . $ciphertext;
        
        return base64_encode($combined);
    }
    
    /**
     * Decrypt data
     * 
     * @param string $ciphertext Base64 encoded ciphertext
     * @return string|null Decrypted data or null on failure
     */
    public function decrypt(string $ciphertext): ?string {
        $data = base64_decode($ciphertext, true);
        
        if ($data === false || strlen($data) < 28) {
            return null;
        }
        
        // Extract iv (12 bytes) + tag (16 bytes) + ciphertext
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);
        
        $plaintext = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );
        
        return ($plaintext === false) ? null : $plaintext;
    }
    
    /**
     * Derive encryption key
     * Uses CCE encryption or environment variable
     */
    private function resolveKey(?string $storageKey): string {
        if (!empty($storageKey)) {
            $decoded = base64_decode($storageKey, true);
            if (($decoded !== false) && (strlen($decoded) === 32)) {
                return $decoded;
            }

            return hash('sha256', $storageKey, true);
        }

        return $this->deriveLegacyKey();
    }

    private function deriveLegacyKey(): string {
        // Try to get key from environment
        $key = getenv('CCE_ENCRYPTION_KEY');
        
        if (empty($key)) {
            // Fallback: Use system-specific data to derive key
            // This is NOT as secure as a proper key management system
            // but maintains backward compatibility
            $keyData = file_exists('/etc/machine-id') 
                ? file_get_contents('/etc/machine-id') 
                : uniqid('blueonyx_', true);
            
            $key = hash('sha256', $keyData, true);
        } else {
            // Derive proper key from environment key
            $key = hash('sha256', $key, true);
        }
        
        return $key;
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
