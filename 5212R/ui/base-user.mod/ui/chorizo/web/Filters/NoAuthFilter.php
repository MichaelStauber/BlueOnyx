<?php
namespace User\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class NoAuthFilter implements FilterInterface {

    public function bx_error_log($msg='') {
        error_log("$msg" . PHP_EOL, 3, '/var/log/gui-debug.log');
    }

    public function print_rp($prp) {
      echo "<pre>";
      print_r($prp);
      echo "</pre>";
    }

    public function before(RequestInterface $request, $arguments = null) {

        self::bx_error_log("User/Filters/NoAuthFilter.php: before()");
        if (session()->get('isLoggedIn')) {
            self::bx_error_log("User/Filters/NoAuthFilter.php: before() - redirecting to /gui");
            return redirect()->to(base_url() . '/gui');
        }
    }

    //--------------------------------------------------------------------

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {
        self::bx_error_log("User/Filters/NoAuthFilter.php: after() - Doing nothing");
        // Do something here
    }

}
