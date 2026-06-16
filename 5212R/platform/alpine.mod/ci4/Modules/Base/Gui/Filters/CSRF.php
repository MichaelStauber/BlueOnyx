<?php

/**
 * This file is part of CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * *** Special Note for BlueOnyx Code Maintainers ****
 * 
 * This file is a copy of and substitute for /usr/sausalito/ui/chorizo/ci4/vendor/codeigniter4/framework/system/Filters/CSRF.php and
 * contains changes to allow us to exclude URIs from CSRF handling. Due to our custom routing the standard CI4 way to whitelist URIs
 * doesn't work, hence this extra effort.
 * 
 * To make this work our /usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php defines and alias that changes ...
 * 
 *      public $aliases = [
 *       'csrf'     => \CodeIgniter\Filters\CSRF::class,
 *
 *      ... to this:
 * 
 *      public $aliases = [
 *       'csrf'     => \Gui\Filters\CSRF::class,
 *
 * This allows us to extend the 'public function before()' below with our mechanism to exclude URIs from CSRF. 
 * 
 */

namespace Gui\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use Config\Services;

/**
 * CSRF filter.
 *
 * This filter is not intended to be used from the command line.
 *
 * @codeCoverageIgnore
 */
class CSRF implements FilterInterface
{
    /**
     * Do whatever processing this filter needs to do.
     * By default it should not return anything during
     * normal execution. However, when an abnormal state
     * is found, it should return an instance of
     * CodeIgniter\HTTP\Response. If it does, script
     * execution will end and that Response will be
     * sent back to the client, allowing for error pages,
     * redirects, etc.
     *
     * @param array|null $arguments
     *
     * @throws SecurityException
     *
     * @return RedirectResponse|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {

        // Blueonyx URIs where CSRF is permanently disabled and bypassed:
        $BlueOnyx_CSRF_Excludes = array('gui/check_password', 'ddns/ddnsapi', 'api/', 'api/index', 'api/apilogin', 'apilogin');
        if (in_array($request->getPath(), $BlueOnyx_CSRF_Excludes)) {
            return;
        }

        if (! $request instanceof IncomingRequest) {
            return;
        }

        $security = Services::security();

        try {
            $security->verify($request);
        } catch (SecurityException $e) {
            if ($security->shouldRedirect() && ! $request->isAJAX()) {
                return redirect()->back()->with('error', $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * We don't have anything to do here.
     *
     * @param array|null $arguments
     *
     * @return void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
