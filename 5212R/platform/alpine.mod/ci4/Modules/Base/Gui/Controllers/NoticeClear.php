<?php

/*
 * NoticeClear — clears a single persistent notice.
 *
 * This is the endpoint a notice links to when its file sets clearOnClick=true, which
 * means "the click ITSELF clears this notice, regardless of any url". The notice banner
 * renders as an ordinary <a href> pointing here with the notice id appended, so the
 * clear is a plain HTTP GET — no client-side scripting, working in any browser and on
 * mobile, and keeping standard link affordances (open in new tab, middle click,
 * keyboard activation).
 *
 * After clearing, the user is redirected back to where they came from so the click
 * behaves like a normal navigation rather than dropping them on a blank response.
 *
 * The local notice directory is a MIRROR of an authoritative store held elsewhere,
 * refreshed by a pull-only cycle — the servers may be NAT'd so the authority cannot
 * push to them. A cleared notice therefore reappears if the authority still lists it.
 * That is correct mirror behaviour, not a fault, and is why clearing here is local.
 *
 * Note that notices with clearOnClick=false never reach this endpoint: their banner
 * links to their own url and clears nothing locally.
 *
 */

namespace Gui\Controllers;
use App\Controllers\BaseController;

// Log403Error() comes from the blueonyx helper, which BaseController's $helpers list
// already loads for every controller request — no explicit require is needed here.
// (BxNotice does need one, because it can run from BxPage::render() outside a
// controller; that reason does not apply to a controller.)

class NoticeClear extends BaseController {

    public function __construct() {

    }

    /**
     * Clear one notice. Reached as GET /gui/notice/clear/<id>; the id arrives as the
     * routed segment.
     */
    public function index($id = null) {

        $CI =& get_instance();

        // Same-site referer check. Clearing is a state change reachable by GET, so it
        // must not be triggerable from another site.
        //
        // NOTE: this is a real host comparison, NOT a string prefix test. A prefix
        // test (str_starts_with($referer, 'https://' . $host), as used by
        // Metrics.php / DaemonServices.php) is bypassable: for host
        // "server.example.com", a referer of "https://server.example.com.evil.net/x"
        // satisfies the prefix and would let a genuinely cross-site request through.
        // The host is therefore parsed out of the referer and compared whole.
        if (!$this->is_same_site_referer()) {
            $this->refuse();
        }

        // Clearing a notice changes what EVERY user of this host sees, so it is a
        // site-level action and requires the site-management capability — not merely a
        // valid login. Matches the gate used by the sibling state-changing controllers
        // (ProcessFrame.php, WorkFrame.php).
        if (!$CI->getAllowed('manageSite')) {
            $this->refuse();
        }

        // The notice id arrives as the routed segment (Routes.php passes it as $1).
        // Fall back to the URI only if it was not supplied, so the controller is not
        // coupled to the exact URL depth. BxNotice::clear() sanitises it to a basename
        // and refuses traversal, so a crafted id cannot escape the notice directory.
        if ($id === null || $id === '') {
            $id = $this->request->getUri()->getSegment(4);
        }

        if ($id === null || $id === '') {
            // No id supplied — nothing to clear. Send them back rather than erroring.
            return redirect()->to($this->safe_return_url());
        }

        \App\Libraries\BxNotice::clear($id);

        // Whether or not the notice existed, we redirect rather than reporting an error:
        // a stale link (notice already cleared, or already withdrawn) should behave like
        // any other navigation, not like a fault.
        return redirect()->to($this->safe_return_url());
    }

    /**
     * Where to send the user after the click.
     *
     * Prefers the referer when it is a same-site URL, so the user lands back on the page
     * they clicked from. Falls back to the GUI home. An off-site referer is ignored to
     * avoid turning this endpoint into an open redirect.
     */
    private function safe_return_url(): string {

        if ($this->is_same_site_referer()) {
            return $_SERVER['HTTP_REFERER'];
        }

        return '/gui';
    }

    /**
     * Is the request's referer a URL on this same host?
     *
     * Deliberately a parsed comparison rather than a string prefix test. A prefix test
     * passes for any hostname that merely STARTS with this host ("server.example.com"
     * would accept "server.example.com.evil.net"), which is a cross-site bypass.
     *
     * The comparison is on HOST **and PORT** together, normalised to a default port
     * when absent. Comparing hostnames alone is wrong in this product: the console is
     * legitimately served on a non-default port (e.g. https://host:81/gui), so
     * HTTP_HOST is "host:81" while parse_url() of the referer yields host "host" with
     * port 81 — a hostname-only comparison rejects the product's own GUI. Comparing
     * the full authority catches both that case and the prefix bypass.
     */
    private function is_same_site_referer(): bool {

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host    = $_SERVER['HTTP_HOST'] ?? '';

        if ($referer === '' || $host === '') {
            return false;
        }

        // The console is https-only; a referer that is not https cannot be ours.
        // Compared case-insensitively: a URL scheme is case-insensitive per RFC 3986,
        // and parse_url() preserves whatever case was given.
        if (strcasecmp((string) parse_url($referer, PHP_URL_SCHEME), 'https') !== 0) {
            return false;
        }

        $referer_authority = $this->authority($referer);

        if ($referer_authority === null) {
            return false;
        }

        return strcasecmp($referer_authority, $this->authority('https://' . $host)) === 0;
    }

    /**
     * Extract "host:port" from a URL, filling in the scheme's default port when the
     * URL omits it, so two URLs on the same endpoint compare equal regardless of
     * whether the port was written explicitly. Returns null when there is no host.
     */
    private function authority(string $url): ?string {

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'http' ? 80 : 443);

        return $parts['host'] . ':' . $port;
    }

    /**
     * Refuse the request: release CCE, then exit via the 403 page.
     *
     * Extracted so both refusal paths behave identically. Log403Error() EXITS when
     * given a URL — that exit is what stops execution reaching the delete, so this
     * method must never be changed to return. The CCE teardown matters because
     * BaseController::initController() has already opened the CceClient connection.
     */
    private function refuse(): void {

        $CI =& get_instance();
        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();
        Log403Error("/gui/Forbidden403");
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
