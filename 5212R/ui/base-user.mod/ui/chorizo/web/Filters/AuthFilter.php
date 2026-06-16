<?php
namespace User\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class AuthFilter implements FilterInterface {

    public function bx_error_log($msg='') {
        error_log("$msg" . PHP_EOL, 3, '/var/log/gui-debug.log');
    }

    public function before(RequestInterface $request, $arguments = null) {
       
        if (!session()->get('isLoggedIn')) {
            $uri = $request->uri;
            $path = $uri->getPath(); 
            if (($path != '') && ($path != '/')) {
                self::bx_error_log("User/Filters/AuthFilter.php: before(): Setting FlashData redirectUri to " . base_url().'/'.$uri->getPath());
                session()->setFlashdata('redirectUri', base_url().'/'.$uri->getPath());
            }
            //return redirect()->to(base_url() . '/gui');
        }
    }

    //--------------------------------------------------------------------

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {
        $redirectUri = session()->getFlashdata('redirectUri');
        if (!empty($redirectUri)) {
            self::bx_error_log("User/Filters/AuthFilter.php: after(): Fetched FlashData redirectUri " . $redirectUri . " - redirecting now!");
            if ($redirectUri != '') {
                return redirect()->to($redirectUri);
            }
        }
        else {
            self::bx_error_log("User/Filters/AuthFilter.php: after(): Have no FlashData redirectUri - not redirecting.");
        }

    }

}
