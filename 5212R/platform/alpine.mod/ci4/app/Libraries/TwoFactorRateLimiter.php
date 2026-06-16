<?php
namespace App\Libraries;

/**
 * TwoFactorRateLimiter - Rate limiting for 2FA attempts
 * 
 * Prevents brute-force attacks on TOTP codes
 * Uses session-based storage (no external dependencies)
 */
class TwoFactorRateLimiter {
    
    private $session;
    private $maxAttempts;
    private $lockoutDuration;
    
    public function __construct(int $maxAttempts = 5, int $lockoutDuration = 900) {
        $this->maxAttempts = $maxAttempts;
        $this->lockoutDuration = $lockoutDuration; // 15 minutes
        $this->session = \Config\Services::session();
    }
    
    /**
     * Check if user is currently locked out
     * 
     * @param string $username Username to check
     * @return bool True if locked out
     */
    public function isLocked(string $username): bool {
        $lockKey = '2fa_lock_' . md5($username);
        $lockedUntil = $this->session->get($lockKey);
        
        if ($lockedUntil === null) {
            return false;
        }
        
        if (time() > $lockedUntil) {
            // Lock expired, clear it
            $this->session->remove($lockKey);
            $this->resetAttempts($username);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get remaining lockout time in seconds
     * 
     * @param string $username Username to check
     * @return int Seconds remaining, 0 if not locked
     */
    public function getLockoutRemaining(string $username): int {
        if (!$this->isLocked($username)) {
            return 0;
        }
        
        $lockKey = '2fa_lock_' . md5($username);
        $lockedUntil = $this->session->get($lockKey);
        
        return max(0, $lockedUntil - time());
    }
    
    /**
     * Record a failed attempt
     * 
     * @param string $username Username
     * @return bool True if user is now locked out
     */
    public function recordFailure(string $username): bool {
        $attemptsKey = '2fa_attempts_' . md5($username);
        $lockKey = '2fa_lock_' . md5($username);
        
        $attempts = $this->session->get($attemptsKey) ?? 0;
        $attempts++;
        
        $this->session->set($attemptsKey, $attempts);
        
        if ($attempts >= $this->maxAttempts) {
            // Lock the user out
            $this->session->set($lockKey, time() + $this->lockoutDuration);
            return true;
        }
        
        return false;
    }
    
    /**
     * Reset failure count (on successful login)
     * 
     * @param string $username Username
     */
    public function reset(string $username): void {
        $this->resetAttempts($username);
        $this->clearLockout($username);
    }
    
    /**
     * Get current attempt count
     * 
     * @param string $username Username
     * @return int Number of failed attempts
     */
    public function getAttemptCount(string $username): int {
        $attemptsKey = '2fa_attempts_' . md5($username);
        return $this->session->get($attemptsKey) ?? 0;
    }
    
    /**
     * Get remaining attempts before lockout
     * 
     * @param string $username Username
     * @return int Remaining attempts
     */
    public function getRemainingAttempts(string $username): int {
        return max(0, $this->maxAttempts - $this->getAttemptCount($username));
    }
    
    /**
     * Reset attempt counter
     */
    private function resetAttempts(string $username): void {
        $attemptsKey = '2fa_attempts_' . md5($username);
        $this->session->remove($attemptsKey);
    }
    
    /**
     * Clear lockout
     */
    private function clearLockout(string $username): void {
        $lockKey = '2fa_lock_' . md5($username);
        $this->session->remove($lockKey);
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