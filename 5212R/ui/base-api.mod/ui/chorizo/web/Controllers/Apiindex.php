<?php 
namespace Api\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("AutoFeatures.php");
include_once("CceClient.php");
include_once("ServerScriptHelper.php");
include_once("ArrayPacker.php");
use I18n;
use BxPage;
use AutoFeatures;
use CceClient;
use ServerScriptHelper;
use ArrayPacker;

/**
 * BlueOnyx API
 *
 * BlueOnyx API Index Page
 *
 * @package   BlueOnyx base-api.mod
 * @author    Michael Stauber
 * @link      http://www.solarspeed.net
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   3.0
 *
 * @info      Creation of this module was sponsored by VIRTBIZ Internet Services: http://www.virtbiz.com
 *
 */

// This module provides rudimentary API functions to BlueOnyx. This allows server administrators and
// especially ISP's to set up some kind of automated account creation and provisioning for BlueOnyx.
//
// This module was created with WHMCS (see http://www.whmcs.com/) in mind and there is also a module 
// for WHMCS available which allows WHMCS to "talk" to BlueOnyx servers for provisioning and management.
//
// However, even if you don't use WHMCS, you can still use the API to perfom remote provisioning of 
// BlueOnyx such as ...
//
// - Create a Vsite and a user for it
// - Configure various options for that Vsite
// - Suspend that Vsite
// - Unsuspend that Vsite
// - Delete that Vsite
// - Check the server status remotely
// - Shutdown or Reboot the server
// - Poll Active Monitor
// - Poll Active Monitor for a detailed status report
//
// The API documentation (and the module for WHMCS) can be found at http://www.blueonyx.it 

class Apiindex extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }

    public function index() {

        helper(['bxapi']);

        $CI =& get_instance();

        if ($this->request->getMethod() == 'POST') {
            bx_error_log("Apiindex.php: Access() with POST data.");
        }
        else {
            bx_error_log("Apiindex.php: Access() without POST data.");
        }

        // We start without any active errors:
        $errors = array();
        $extra_headers =array();

        // We neither have a sessionId nor login at this point:
        $sessionId = "";

        //
        //--- Handle form validation:
        //

        // My output array starts empty:
        $data = array();

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $this->request->getPost();

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            if ((isset($form_data['login'])) && (isset($form_data['pass']))) {
                bx_error_log("Apiindex.php: We have a Username and Password.");
                $sessionId = $CI->cceClient->auth($form_data['login'], $form_data['pass']);
                bx_error_log("Apiindex.php: Our sessionId is now: " . $sessionId);
            }

            if (!empty($sessionId)) {
                $BX_SESSION = $CI->getBX_SESSION();
                $serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
                $CI->setSSH($serverScriptHelper);
                $System = $CI->getSystem();
                $CI->setCCE($serverScriptHelper->getCceClient());
                $cceClient = $CI->getCCE();
                $serverScriptHelper = $CI->getSSH();
                $locale = $BX_SESSION['locale'];
                $BX_SESSION = $CI->getBX_SESSION();
                bx_error_log("Apiindex.php: Auth-Geraffel done.");
            }
            else {
                bx_error_log("Apiindex.php: Invalid login attempt. I will remember this!");
                $CI->add_invalid_login();

                bx_error_log("Apiindex.php: No sessionId yet, so the login must have failed!");
                $data['result'] = "BlueOnyx API: You're not doing this right.";
                Log403Error();
                return view('Api\Views\apiindex_view', $data);
            }
        }
        else {
            // We don't have POST data. Bye, bye, stranger!
            $data['result'] = "BlueOnyx API: You're not doing this right.";
            Log403Error();
            return view('Api\Views\apiindex_view', $data);
        }

        // Only adminUser should be here:
        if (!$CI->getAllowed('adminUser')) {
            bx_error_log("Apiindex.php: Failed CI->getAllowed('adminUser') check!");
            $cceClient->bye();
            $serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
            return;
        }
        else {
            bx_error_log("Apiindex.php: We PASSED CI->getAllowed('adminUser') check!");
        }


        //
        // -- Check if the API is enabled and we are allowed to pass on further:
        //

        $ip = getenv('REMOTE_ADDR'); // Or: $_SERVER["REMOTE_ADDR"]
        $secure_connection = FALSE;
        if ($_SERVER['SERVER_PORT'] == "81") {
          $secure_connection = TRUE;
        }

        $sysoid = $cceClient->find("System");
        $APISettings = $cceClient->get($sysoid[0], 'API');

        if ($APISettings['enabled'] == "0") {
            $data['result'] = "BlueOnyx API: API is disabled on this BlueOnyx.";
            bx_error_log("BlueOnyx API: API is disabled, but we got accessed from this IP: $ip");
            Log403Error();
            $cceClient->bye();
            $serverScriptHelper->destructor();
            return view('../../Modules/Base/Api/Views/apiindex_view', $data);
        }

        if (($APISettings['forceHTTPS'] == "1") && ($secure_connection == FALSE)) {
          $data['result'] = "BlueOnyx API: This API responds only to HTTPS connections!";
          bx_error_log("BlueOnyx API: API requries HTTPS, but we got a HTTP accessed from this IP: $ip");
          // nice people say aufwiedersehen
          $serverScriptHelper->destructor();
          exit;
        }

        if (($APISettings['apiHosts'] != "") && (isset($ip))) {
          $api_hosts = stringToArray($APISettings['apiHosts']);
          // Check if the IP of the visitor is in the array of allowed hosts:
          if (!in_array($ip, $api_hosts)) {
            $data['result'] = "BlueOnyx API: You are not allowed to access this API.";
            bx_error_log("BlueOnyx API: API access from unauthorized IP: $ip");
            // nice people say aufwiedersehen
            $serverScriptHelper->destructor();
            exit;
          }
        }

        //
        // -- Decode Payload:
        //

        bx_error_log("BlueOnyx API: Access from $ip to port " . $_SERVER['SERVER_PORT']);

        if ((isset($form_data['payload'])) && (($form_data['action'] != "reboot") || ($form_data['action'] != "shutdown") || ($form_data['action'] != "status") || ($form_data['action'] != "vsitestatus") || ($form_data['action'] != "destroy")))  {
            $payload = json_decode($form_data['payload']);

            bx_error_log("BlueOnyx API: We have action " . $form_data['action'] . " and the payload: " . $form_data['payload']);

            if (isset($payload->clientsdetails)) {
                $clientsdetails = json_decode($payload->clientsdetails);
                $payload->clientsdetails = "";
            }

            if ($payload == NULL) {
                bx_error_log("BlueOnyx API: JSON decoding returned NULL!");
                bx_error_log("BlueOnyx API: JSON error: " . json_last_error());
                bx_error_log("BlueOnyx API: Not continuing without JSON data!");
                $data['result'] = "BlueOnyx API: JSON decoding error.";
                Log403Error();
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
            }

        }
        elseif ((!isset($form_data['payload'])) && (($form_data['action'] == "reboot") || ($form_data['action'] == "shutdown") || ($form_data['action'] == "status") || ($form_data['action'] != "vsitestatus") || ($form_data['action'] == "destroy"))) {
            //error_log("BlueOnyx API: " . $form_data['action'] . " requested.");
        }
        else {
            // No payload? Something went wrong!
            $data['result'] = "BlueOnyx API: You're not doing this right.";
            bx_error_log("BlueOnyx API: API access without payload.");
            Log403Error();
            $cceClient->bye();
            $serverScriptHelper->destructor();
            return view('../../Modules/Base/Api/Views/apiindex_view', $data);
        }

        //
        // -- Check transaction type:
        //

        if (($form_data['action'] == "create") && ($payload->producttype != "hostingaccount")) {
            $data['result'] = "BlueOnyx API: At this time only producttype 'hostingaccount' is supported.";
            bx_error_log("BlueOnyx API: At this time only producttype 'hostingaccount' is supported.");
            Log403Error();
            $cceClient->bye();
            $serverScriptHelper->destructor();
            return view('../../Modules/Base/Api/Views/apiindex_view', $data);
        }

        //
        // -- See if we have everything we need for a "create" transaction:
        //

        if ($form_data['action'] == "create") {
            bx_error_log("BlueOnyx API: Action 'create' detected.");
            if ((isset($payload->domain)) &&
              (isset($payload->ipaddr)) &&
              (isset($payload->username)) &&
              (isset($payload->password)) &&  
              (isset($payload->disk)) &&  
              (isset($payload->users)) &&
              (isset($payload->auto_dns)) &&
              (isset($clientsdetails->firstname)) &&  
              (isset($clientsdetails->lastname)) &&  
              (isset($clientsdetails->email))) 
            {

                // Create Vsite:
                bx_error_log("BlueOnyx API: Running do_create_vsite()");
                $result = do_create_vsite($payload, $clientsdetails, $serverScriptHelper);

              // If that went well, we create the User, too:
              if (is_array($result)) {
                bx_error_log("BlueOnyx API: Vsite $payload->domain created successfully.");
                $result = do_create_user($payload, $clientsdetails, $serverScriptHelper, $result);
                if ($result == "success") {
                    // nice people say aufwiedersehen
                    $data['result'] = $result;
                    bx_error_log("BlueOnyx API: Vsite $payload->domain created successfully, returning result: " . $result);
                    //error_log("BlueOnyx API: Done, reporting: $result");
                    $cceClient->bye();
                    $serverScriptHelper->destructor();
                    return view('../../Modules/Base/Api/Views/apiindex_view', $data);
                }
                else {
                    // This should never fire, as other errors trigger first. But one never knows:
                    $data['result'] = "BlueOnyx API: Unknown error during Vsite and User creation, sorry. " . $result;
                    bx_error_log("BlueOnyx API: Failed, reporting: " . $data['result']);
                    $cceClient->bye();
                    $serverScriptHelper->destructor();
                    return view('../../Modules/Base/Api/Views/apiindex_view', $data);
                }
              }
              else {
                    $data['result'] = "BlueOnyx API: Sorry, the Vsite was not created properly. " . $result;
                    bx_error_log("BlueOnyx API: Failed, reporting: " . $data['result']);
                    $cceClient->bye();
                    $serverScriptHelper->destructor();
                    return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
            }
            else {
                $data['result'] = "BlueOnyx API: Did not receive sufficient data to finish this transaction.";
                bx_error_log("BlueOnyx API: Failed, reporting: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
            }
        }
        elseif ($form_data['action'] == "changepass") {
          if ((isset($payload->domain)) &&
              (isset($payload->ipaddr)) &&
              (isset($payload->username)) &&
              (isset($payload->password)) &&  
              (isset($clientsdetails->firstname)) &&  
              (isset($clientsdetails->lastname)) &&  
              (isset($clientsdetails->email))) 
            {
              $cceClient->setObject("User", array("password" => $payload->password), "", array("name" => $payload->username));
              $errors = $cceClient->errors();

              // nice people say aufwiedersehen
              $serverScriptHelper->destructor();

              if (count($errors) >= "1") {
                $data['result'] = "BlueOnyx API: An error happened during the password change.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                $data['result'] = "success";
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
            }
        }
        elseif ($form_data['action'] == "suspend") {
          if ((isset($payload->domain)) &&
              (isset($payload->ipaddr)) &&
              (isset($payload->username)) &&
              (isset($payload->password)) &&  
              (isset($clientsdetails->firstname)) &&  
              (isset($clientsdetails->lastname)) &&  
              (isset($clientsdetails->email))) 
            {

              $host_details = get_fqdn_details($payload->domain);
              $cceClient->setObject("Vsite", array("suspend" => "1"), "", array("fqdn" => $host_details['fqdn']));
              $errors = $cceClient->errors();

              // nice people say aufwiedersehen
              $serverScriptHelper->destructor();

              if (count($errors) >= "1") {
                $data['result'] = "BlueOnyx API: An error happened during the suspension of the Vsite.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                $data['result'] = "success";
                //error_log("BlueOnyx API: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
            }
        }
        elseif ($form_data['action'] == "unsuspend") {
          if ((isset($payload->domain)) &&
              (isset($payload->ipaddr)) &&
              (isset($payload->username)) &&
              (isset($payload->password)) &&  
              (isset($clientsdetails->firstname)) &&  
              (isset($clientsdetails->lastname)) &&  
              (isset($clientsdetails->email))) 
            {
              $host_details = get_fqdn_details($payload->domain);
              $cceClient->setObject("Vsite", array("suspend" => "0"), "", array("fqdn" => $host_details['fqdn']));
              $errors = $cceClient->errors();

              // nice people say aufwiedersehen
              $serverScriptHelper->destructor();

              if (count($errors) >= "1") {
                $data['result'] = "BlueOnyx API: An error happened during unsuspension of the Vsite.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                $data['result'] = "success";
                bx_error_log("BlueOnyx API: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
            }
        }
        elseif ($form_data['action'] == "destroy") {
            //error_log("Processing 'destroy' request ...");
            if ((isset($payload->domain)) &&
                (isset($payload->ipaddr)) &&
                (isset($payload->username)) &&
                (isset($payload->password)) &&  
                (isset($clientsdetails->firstname)) &&  
                (isset($clientsdetails->lastname)) &&  
                (isset($clientsdetails->email))) 
            {
                //error_log("Processing 'destroy' request for $payload->domain - $payload->ipaddr");
                // Get Vsite OID:
                $host_details = get_fqdn_details($payload->domain);
                //error_log("Calculated FQDN for $payload->domain is: " . $host_details['fqdn']);
                $vsiteOID = $cceClient->find("Vsite", array("fqdn" => $host_details['fqdn']));
                $numResults = count($vsiteOID);
                if ($numResults == '0') {
                    bx_error_log("A site with that name was not present!");
                    $errors = array("error" => "A site with that name was not present!");
                }
                else {
                  // Get Vsite Settings:
                  $VsiteSettings = $cceClient->get($vsiteOID[0], '');

                  // Get Vsite's MySQL settings:
                  $VsiteMySQL = $cceClient->get($vsiteOID[0], "MYSQL_Vsite");

                  if ($VsiteMySQL['enabled'] == "1") {
                      // Get Server's MySQL access details:
                      $getthisOID = $cceClient->find("MySQL");
                      $mysql_settings = $cceClient->get($getthisOID[0]);

                      // Server MySQL settings:
                      $sql_root               = $mysql_settings['sql_root'];
                      $sql_rootpassword       = $mysql_settings['sql_rootpassword'];

                      // Store the setings in $VsiteSettings as well:
                      $VsiteSettings['sql_username'] = $VsiteMySQL['username'];
                      $VsiteSettings['sql_database'] = $VsiteMySQL['DB'];
                      $VsiteSettings['sql_host'] = $VsiteMySQL['host'];
                      $VsiteSettings['sql_root'] = $sql_root;
                      $VsiteSettings['sql_rootpassword'] = $sql_rootpassword;

                      delete_mysql_stuff($VsiteSettings, $cceClient);
                  }

                  // Find out if the site is suspended. In that case we unsuspend it first:
                  if ($VsiteSettings['suspend'] == "1") {
                      $host_details = get_fqdn_details($payload->domain);
                      $cceClient->setObject("Vsite", array("suspend" => "0"), "", array("fqdn" => $host_details['fqdn']));
                      $errors = $cceClient->errors();
                  }

                  // Destroy the Vsite and all its Users and data:
                  if (isset($VsiteSettings['name'])) {
                      //error_log("Running /usr/sausalito/sbin/vsite_destroy.pl " . $VsiteSettings['name']);
                      $cmd = "/usr/sausalito/sbin/vsite_destroy.pl " . escapeshellarg($VsiteSettings['name']);
                      $no_return = '';
                      $serverScriptHelper->shell($cmd, $no_return, 'root', $sessionId);
                  }
                  else {
                      bx_error_log("A site with that name was not present!");
                      $errors = array("error" => "A site with that name was not present!");
                  }
                }

                // nice people say aufwiedersehen
                $serverScriptHelper->destructor();

                if (count($errors) >= "1") {
                    $data['result'] = "BlueOnyx API: An error happened during the deletion of the Vsite. ";
                    if (isset($errors['error'])) {
                      $data['result'] .= $errors['error'];
                    }
                    bx_error_log($data['result']);
                    $cceClient->bye();
                    $serverScriptHelper->destructor();
                    return view('../../Modules/Base/Api/Views/apiindex_view', $data);
                }
                else {
                    $data['result'] = "success";
                    //error_log("BlueOnyx API: " . $data['result']);
                    $cceClient->bye();
                    $serverScriptHelper->destructor();
                    return view('../../Modules/Base/Api/Views/apiindex_view', $data);
                }
            }
        }
        elseif ($form_data['action'] == "modify") {
          if ((isset($payload->domain)) &&
              (isset($payload->ipaddr)) &&
              (isset($payload->username)) &&
              (isset($payload->password)) &&  
              (isset($payload->disk)) &&  
              (isset($payload->users)) &&
              (isset($payload->auto_dns)) &&
              (isset($clientsdetails->firstname)) &&  
              (isset($clientsdetails->lastname)) &&  
              (isset($clientsdetails->email))) 
            {

              // Create Vsite:
              $result = do_modify_vsite($payload, $clientsdetails, $serverScriptHelper, "modify");

              // If that went well, we create the User, too:
              if ($result == "success") {
                $data['result'] = "success";
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                // This should never fire, as other errors trigger first. But one never knows:
                $data['result'] = "BlueOnyx API: Unknown error during modification of the Vsite. Sorry.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
            }
            else {
                // This should never fire, as other errors trigger first. But one never knows:
                $data['result'] = "BlueOnyx API: Unknown error during modification of the Vsite. Not enough data.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
            }
        }
        elseif ($form_data['action'] == "reboot") {

              $sysoid = $cceClient->find("System");
              $cceClient->set($sysoid[0], "Power", array("reboot" => time()));
              $errors = $cceClient->errors();

              // nice people say aufwiedersehen
              $serverScriptHelper->destructor();

              if (count($errors) >= "1") {
                $data['result'] = "BlueOnyx API: An error happened while attempting to reboot the server.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                $data['result'] = "success";
                bx_error_log("BlueOnyx API: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
        }
        elseif ($form_data['action'] == "shutdown") {

              $sysoid = $cceClient->find("System");
              $cceClient->set($sysoid[0], "Power", array("halt" => time()));
              $errors = $cceClient->errors();

              // nice people say aufwiedersehen
              $serverScriptHelper->destructor();

              if (count($errors) >= "1") {
                $data['result'] = "BlueOnyx API: An error happened while attempting to shutdown the server.";
                bx_error_log($data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
              else {
                $data['result'] = "success";
                bx_error_log("BlueOnyx API: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('../../Modules/Base/Api/Views/apiindex_view', $data);
              }
        }
        elseif ($form_data['action'] == "statusdetailed") {

            $factory = $serverScriptHelper->getHtmlComponentFactory("base-am");
            $i18n = $factory->i18n;

            // Force run of Swatch:
            $no_return = '';
            $serverScriptHelper->shell("/usr/sbin/swatch -c /etc/swatch.conf", $no_return, 'root', $sessionId);

            // Poll CCE for our ActiveMonitor details:
            $amobj = $cceClient->getObject("ActiveMonitor");
            $am_names = $cceClient->names("ActiveMonitor");
            $System = $cceClient->getObject("System");
            $amenabled = $amobj["enabled"];

            $stmap = array(
            "N" => "N/A", 
            "G" => "Normal", 
            "Y" => "Problem", 
            "R" => "Severe Problem");

            $status = "Status for BlueOnyx (" . $System['productBuild'] . ")<br>\n\n";

              for ($i=0; $i < count($am_names); ++$i) {
                $nspace = $cceClient->get($amobj["OID"], $am_names[$i]);
                  if (!isset($nspace["hideUI"])) {
                      $iname = $i18n->interpolate($nspace["nameTag"]);

                      if (!$amenabled) {
                        $icon = "Not Monitored";
                      } else if (!$nspace["enabled"]) {
                        $icon = "Disabled";
                      } else if (!$nspace["monitor"]) {
                        $icon = "Not Monitored";
                      } else {
                        $icon = $stmap[$nspace["currentState"]];
                      }

                      if ($nspace["UIGroup"] == "system") {
                        $status_system .= $iname . ": " . $icon . "<br>\n";
                      } else if ($nspace["UIGroup"] == "service") {
                        $status_service .= $iname . ": " . $icon . "<br>\n";
                      }
                  }
              }

              $result = $status;
              $result .= "System:<br>\n\n";
              $result .= $status_system;
              $result .= "<br><br>\n\nService:<br>\n\n";
              $result .= $status_service;

              $data['result'] = $result;
              bx_error_log("BlueOnyx API: " . $data['result']);
              $cceClient->bye();
              $serverScriptHelper->destructor();
              return view('../../Modules/Base/Api/Views/apiindex_view', $data);

        }
        elseif ($form_data['action'] == "status") {

            $factory = $serverScriptHelper->getHtmlComponentFactory("base-am");
            $i18n = $factory->i18n;

            // Poll CCE for our ActiveMonitor details:
            $amobj = $cceClient->getObject("ActiveMonitor");
            $am_names = $cceClient->names("ActiveMonitor");
            $System = $cceClient->getObject("System");
            $amenabled = $amobj["enabled"];

            $stmap = array(
                            "N" => "N/A", 
                            "G" => "Normal", 
                            "Y" => "Problem", 
                            "R" => "Severe Problem"
                          );

            $yellow = "0";
            $red = "0";

            for ($i=0; $i < count($am_names); ++$i) {
              $nspace = $cceClient->get($amobj["OID"], $am_names[$i]);
              if (!isset($nspace["hideUI"])) {
                if ($nspace["currentState"] == "Y") {
                  $yellow++;
                }
                if ($nspace["currentState"] == "R") {
                  $red++;
                }
              }
            }

            if (($yellow == "0") && ($red == "0")) {
              $result = "G";
            }
            elseif (($yellow == "1") && ($red == "0")) {
              $result = "Y";
            }
            elseif (($yellow == "1") && ($red == "1")) {
              $result = "R";
            }
            elseif (($yellow == "0") && ($red == "1")) {
              $result = "R";
            }
            else {
              $result = "G";
            }
            $data['result'] = $result;
            bx_error_log("BlueOnyx API AM-STATUS: " . $data['result']);
            $cceClient->bye();
            $serverScriptHelper->destructor();
            return view('../../Modules/Base/Api/Views/apiindex_view', $data);
        }
        elseif ($form_data['action'] == "vsitestatus") {

            if ((isset($payload->domain)) &&
                (isset($payload->ipaddr)) &&
                (isset($payload->username)) &&
                (isset($payload->password)) &&  
                (isset($clientsdetails->firstname)) &&  
                (isset($clientsdetails->lastname)) &&  
                (isset($clientsdetails->email))) 
            {

                $fqdn_Vsite = 'www.' . $payload->domain;

                bx_error_log("Checking Vsite: " . $payload->domain);

                $factory = $serverScriptHelper->getHtmlComponentFactory("base-am");
                $i18n = $factory->i18n;
    
                $vsite = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite));

                if (isset($vsite['OID'])) {
                  $vsitePHP = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'PHP');
                  $vsiteUsers = '0';
                  if (isset($vsite['name'])) {
                      $vsiteUserOIDs = $cceClient->findx('User', array('site' => $vsite['name']));
                      $vsiteUsers = count($vsiteUserOIDs);
                  }
                  $userLine = $vsiteUsers . ' of ' . $vsite['maxusers'];
      
                  $cceClient->set($vsite['OID'], 'Disk', array( 'refresh' => time()));
                  $vsiteDisk = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'Disk');
                  if (!isset($vsiteDisk['used'])) { 
                      $vsiteDisk['used'] = '0'; 
                  }
                  if (!isset($vsiteDisk['quota'])) { 
                      $vsiteDisk['quota'] = '0'; 
                  }
      
                  $percentage = round(100 * $vsiteDisk['used'] / $vsiteDisk['quota']);
                  $VsiteDiskUsage = $vsiteDisk['used'] . ' MB of ' . $vsiteDisk['quota'] . ' MB used (' . $percentage . '%)';
      
                  $PHPType = 'Disabled';
                  if ($vsitePHP['enabled'] == '1') { $PHPType = 'DSO'; }
                  if ($vsitePHP['suPHP_enabled'] == '1') { $PHPType = 'suPHP'; }
                  if ($vsitePHP['mod_ruid_enabled'] == '1') { $PHPType = 'mod_ruid'; }
                  if ($vsitePHP['fpm_enabled'] == '1') { $PHPType = 'FPM'; }

                  // MySQL:
                  $VsiteMySQL = '0';
                  $VsiteMaxDBs = '0';
                  $VsiteDBs = '0';
                  $obj_MYSQL_Vsite = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'MYSQL_Vsite');
                  if ($obj_MYSQL_Vsite['enabled'] == '1') {
                    $VsiteMySQL = $obj_MYSQL_Vsite['enabled'];
                    $VsiteMaxDBs = $obj_MYSQL_Vsite['maxDBs'];
                    $VsiteDBs = "1" + count($cceClient->scalar_to_array($obj_MYSQL_Vsite['DBmulti']));
                  }
      
                  // Subdomains:
                  $obj_subdomains_Vsite = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'subdomains');
                  $Vsite_subs = '0';
                  $Vsite_subs_max = '0';
                  $vsite_subs_present = '0';
                  if ($obj_subdomains_Vsite['vsite_enabled'] == '1') {
                    $Vsite_subs = $obj_subdomains_Vsite['vsite_enabled'];
                    $Vsite_subs_max = $obj_subdomains_Vsite['max_subdomains'];
                    $vsite_subs_present = count($cceClient->findx('Subdomains', array('group' => $vsite['name'])));
                  }

                  // SSL:
                  $obj_SSL_Vsite = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'SSL');
                  $Vsite_SSL = '0';
                  $Vsite_SSL_orgName = 'unknown';
                  $vsite_SSL_expires = 'unknown';
                  if ($obj_SSL_Vsite['enabled'] == '1') {
                    $Vsite_SSL = $obj_SSL_Vsite['enabled'];
                    $Vsite_SSL_orgName = $obj_SSL_Vsite['orgName'];
                    $vsite_SSL_expires = $obj_SSL_Vsite['expires'];
                  }

                  // Shell:
                  $shellOptionsIndexed = array('0' => 'None', '1' => 'Chrooted (SFTP SCP RSYNC)', '2' => 'Chrooted (Shell SFTP SCP RSYNC)', '3' => 'Full Shell Access');
                  $obj_Shell_Vsite = $cceClient->getObject('Vsite', array('fqdn' => $fqdn_Vsite), 'Shell');
                  $Vsite_Shell = 'Disabled';
                  if (($obj_Shell_Vsite['enabled'] > '0') && ($obj_Shell_Vsite['enabled'] < '4')) {
                    $Shell_Index = $obj_Shell_Vsite['enabled'];
                    $Vsite_Shell = 'Enabled. ' . $shellOptionsIndexed[$Shell_Index];
                  }

                  // Out with the results:
                  $vsitestatus = array(
                          'CHECKSTATUS' => 'SUCCESS', 
                          'VsiteDiskUsage' => $VsiteDiskUsage, 
                          'UserCnt' => $userLine, 
                          'PHPType' => $PHPType, 
                          'vsitePHP' => $vsitePHP['version'],
                          'VsiteMySQL' => $VsiteMySQL,
                          'VsiteMaxDBs' => $VsiteMaxDBs,
                          'VsiteDBs' => $VsiteDBs,
                          'Vsite_subs' => $Vsite_subs,
                          'Vsite_subs_max' => $Vsite_subs_max,
                          'vsite_subs_present' => $vsite_subs_present,
                          'Vsite_SSL' => $Vsite_SSL,
                          'Vsite_SSL_orgName' => $Vsite_SSL_orgName,
                          'vsite_SSL_expires' => $vsite_SSL_expires,
                          'Vsite_Shell' => $Vsite_Shell,
                        );

                  $result = json_encode($vsitestatus);
                }
                else {
                  $vsitestatus = '';
                }
    
                $result = json_encode($vsitestatus);

                $data['result'] = $result;
                bx_error_log("BlueOnyx API: " . $data['result']);
                $cceClient->bye();
                $serverScriptHelper->destructor();
                return view('Api\Views\apiindex_view', $data);
            }
        }
    }       
}

/*
Copyright (c) 2014-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2014-2024 Team BlueOnyx, BLUEONYX.IT
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