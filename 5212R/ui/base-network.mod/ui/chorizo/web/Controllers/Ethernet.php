<?php 
namespace Network\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("Product.php");
use Product;
use I18n;
use BxPage;

class Ethernet extends BaseController {
    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }
    
    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        if (!$CI->getAllowed('serverNetwork')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-network", "/network/ethernet");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        // Protect certain form fields read-only inside VPS's:
        if (is_file("/proc/user_beancounters")) { 
            $fieldprot = "r";
        }
        else {
            $fieldprot = "rw";
        }

        // Are we running on AWS?
        if (is_file("/etc/is_aws")) {
            $is_aws = "1";
        }
        else {
            $is_aws = "0";
        }

        $redirect = "";

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // Form fields that are required to have input:
        $required_keys = array();

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text");

        if ((is_array($form_data)) && ($this->request->getPost(NULL, NULL, TRUE))) {

            // Function GetFormAttributes() walks through the $form_data and returns us the $parameters we want to
            // submit to CCE. It intelligently handles checkboxes, which only have "on" set when they are ticked.
            // In that case it pulls the unticked status from the hidden checkboxes and addes them to $parameters.
            // It also transformes the value of the ticked checkboxes from "on" to "1". 
            //
            // Additionally it generates the form_validation rules for CodeIgniter.
            //
            // params: $i18n                i18n Object of the error messages
            // params: $form_data           array with form_data array from CI
            // params: $required_keys       array with keys that must have data in it. Needed for CodeIgniter's error checks
            // params: $ignore_attributes   array with items we want to ignore. Such as Labels.
            // params: $BxPage              our already declared $BxPage Object (for storing validation Errors)
            // return:                      array with keys and values ready to submit to CCE.
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

            // Get potential errors that GetFormAttributes() ran into from $BxPage:
            $errors = array_merge($errors, $BxPage->getErrors());
        }

        //
        //--- Own error checks:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) {

            // We have no errors. We submit to CODB.
            $oids = $CI->cceClient->find("System");
            $product = new Product( $CI->cceClient );

            //          Array
            //          (
            //              [hostNameField] => ng2
            //              [domainNameField] => blueonyx.it
            //              [dnsAddressesField] => &8.8.8.8&127.0.0.1&
            //              [gatewayField] => 186.116.135.82
            //              [ipAddressFieldeth0] => 186.116.135.83
            //              [netMaskFieldeth0] => 255.255.255.240
            //              [macAddressFieldeth0] => 08:00:27:D4:2C:4E
            //              [hasAliaseseth0] => 0
            //              [ipAddressOrigeth0] => 186.116.135.83
            //              [netMaskOrigeth0] => 255.255.255.240
            //              [bootprotoFieldeth0] => none
            //              [enabledeth0] => 0
            //              [adminIf] => eth0
            //              [deviceList] => &eth0&
            //          )

            // Remove any pre-existing CCE Replay-File:
            $CI->cceClient->replayReset();

            // Prevent handler change_route.pl from firing before we are entirely done with the network settings:
            $CI->cceClient->record($oids['0'], '', array("nw_update" => '0'));

            // Determine IPtype:
            if (!is_file("/proc/user_beancounters")) {
                $got_IPv4 = '0';
                $got_IPv6 = '0';
                $got_BOTH = '0';
                // Assume a safe default:
                $IPType = 'IPv4';
                if ((isset($attributes['ipAddressFieldeth0'])) && (isset($attributes['netMaskFieldeth0'])) && (isset($attributes['gatewayField']))) {
                    if (($attributes['ipAddressFieldeth0'] != "") && ($attributes['netMaskFieldeth0'] != "") && ($attributes['gatewayField'] != "")) {
                        $got_IPv4 = '1';
                        $IPType = 'IPv4';
                    }
                }
                if ((isset($attributes['IPv6_ipAddressFieldeth0'])) && (isset($attributes['gatewayField_IPv6']))) {
                    if (($attributes['IPv6_ipAddressFieldeth0'] != "") && ($attributes['gatewayField_IPv6'] != "")) {
                        $got_IPv6 = '1';
                        $IPType = 'IPv6';
                    }
                }
                if (($got_IPv4 == '1') && ($got_IPv6 == '1')) {
                    $got_BOTH = '1';
                    $IPType = 'BOTH';
                }
                // Record CCE Replay-Transaction:
                $CI->cceClient->record($oids['0'], '', array("IPType" => $IPType));
            }

            if ($product->isRaq()) {
                // Record CCE Replay-Transaction:
                $CI->cceClient->record($oids['0'], '', array("hostname" => $attributes['hostNameField'], "domainname" => $attributes['domainNameField'], "dns" => $attributes['dnsAddressesField'], "gateway" => $attributes['gatewayField'], "gateway_IPv6" => $attributes['gatewayField_IPv6']));
            }
            else {
                // Record CCE Replay-Transaction:
                $CI->cceClient->record($oids['0'], '', array("hostname" => $attributes['hostNameField'], "domainname" => $attributes['domainNameField'], "dns" => $attributes['dnsAddressesField']));
            }

            //--> Redirect needs to be handled here.

            // handle all devices
            $devices = find_eth_ifaces();

            $primary_interface = get_primary_interface();

            $devices_new = array();
            if (isset($attributes['deviceList'])) {
                $devices = $CI->cceClient->scalar_to_array($attributes['deviceList']);
            }

            // Ok, this is nuts. Somehow our '&eth0&' got turned into '&eth0;&' and I have no idea 
            // where or why this happened. So we have to walk through the $devides array and every
            // device in it needs to have any superfluxous ';' removed:
            foreach ($devices as $key => $value) {
                $devices_new[] = preg_replace('/;/', '', $value);
            }
            $devices = $devices_new;

            // special array for admin if errors
            $admin_if_errors = array();

            $eth0_ipaddr = '';
            $eth0_ipaddr_IPV6 = '';

            for ($i = 0; $i < count($devices); $i ++) {
                $var_name = "ipAddressField" . $devices[$i];
                $ip_field = $attributes[$var_name];
                $var_name_IPv6 = "IPv6_ipAddressField" . $devices[$i];
                $var_name_orig_IPv6 = "IPv6_ipAddressOrig" . $devices[$i];
                $ip_orig_IPv6 = $attributes[$var_name_orig_IPv6];

                $var_name_bootproto = "bootprotoField" . $devices[$i];
                $bootproto = $attributes[$var_name_bootproto];

                if ($bootproto === 'Manual') {
                    $bootproto = 'none';
                }
                if ($bootproto === 'DHCP') {
                    $bootproto = 'dhcp';
                }

                if ($attributes['gatewayField_IPv6'] != '') {
                    bx_error_log("Setting interface " . $devices[$i] . " to " . $attributes[$var_name_IPv6]);
                    $ip_field_IPv6 = $attributes[$var_name_IPv6];
                }
                else {
                    // No IPv6 Gateway? Then remove IPv6 IP as well:
                    bx_error_log("Stripping interface " . $devices[$i] . " of " . $attributes[$var_name_IPv6]);
                    $ip_field_IPv6 = '';
                }
                $var_name = "ipAddressOrig" . $devices[$i];
                $ip_orig = $attributes[$var_name];
                $var_name = "netMaskField" . $devices[$i];
                $nm_field = $attributes[$var_name];
                $var_name = "netMaskOrig" . $devices[$i];
                $nm_orig = $attributes[$var_name];

                $var_name = "bootprotoField" . $devices[$i];
                $boot_field = $attributes[$var_name];

                // No IPv4 Gateway? Then remove IPv4 IP and Netmask as well:
                if ($attributes['gatewayField'] == '') {
                    bx_error_log("Stripping interface " . $devices[$i] . " of " . $ip_field . "/" . $nm_field);
                    $ip_field = '';
                    $nm_field = '';
                    $ReplayType = 'full';
                }
               
                $target_OID = $CI->cceClient->findx('Network', array('device' => "$devices[$i]"), array(), 'ascii', 'device');
                bx_error_log("target_OID of interface " . $devices[$i] . ": " . $target_OID['0']);
                if (isset($target_OID['0'])) {

                    if ($devices[$i] == 'eth0') {
                        $eth0_ipaddr = $ip_field;
                        $eth0_ipaddr_IPV6 = $ip_field_IPv6;
                    }

                    // We *always* update 'eth0' on saving. No if, no but, we just do it. Because we *must* be sure that the network config is in sync with the GUI. And it might not be.
                    // Any other network object only gets updated if there are changes in IP/Netmask or IPv6 IP address.
                    if (($devices[$i] == $primary_interface) || (($ip_field != $ip_orig) || ($ip_field_IPv6 != $ip_orig_IPv6) || ($nm_field != $nm_orig) || ($attributes['gatewayField'] != $attributes['gatewayFieldOrig']) || ($attributes['gatewayField_IPv6'] != $attributes['gatewayFieldOrig_IPv6']))) {
                        $CI->cceClient->record($target_OID['0'], '', array('ipaddr' => $ip_field, 'netmask' => $nm_field, 'ipaddr_IPv6' => $ip_field_IPv6, 'enabled' => '1', 'bootproto' => $bootproto, 'refresh' => time()));
                    }
                }
            }

            // Set nw_update:
            if ($product->isRaq()) {
                // Record CCE Replay-Transaction:
                $CI->cceClient->record($oids['0'], '', array("nw_update" => time(), 'bridged_network' => $attributes['bridged_network']));
            }

            // Redirect to our new progress-display for CCE Stored-Transactions:
            $http_server_name = $_SERVER['SERVER_NAME'];
            $http_server_name = preg_replace('/\[/', '', $http_server_name);
            $http_server_name = preg_replace('/\]/', '', $http_server_name);
            bx_error_log("SERVER_NAME: " . $http_server_name);
            if ((count($errors) == "0")) {
                if ((filter_var($http_server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) && ($eth0_ipaddr_IPV6 != '' )) { 
                    // GUI is currently accessed via an IPv6 IP!
                    $targetProto = 'ipv6';
                    bx_error_log("Redirect-Check: IPv6 possible");
                }
                elseif ((filter_var($http_server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) && ($eth0_ipaddr != '' )) { 
                    // GUI is currently accessed via an IPv4 IP!
                    $targetProto = 'ipv4';
                    bx_error_log("Redirect-Check: IPv4 possible");
                }
                else {
                    // GUI is currently accessed via FQDN:
                    bx_error_log("Redirect-Check: Using 'standard'");
                    $targetProto = 'standard';
                }
            
                if (!isset($ReplayType)) {
                    $ReplayType = 'full';
                }
                bx_error_log("redirectType: " . $targetProto);
                bx_error_log("ReplayType: " . $ReplayType);
                header("Location: /gui/working?statusId=1&VM=base_serverconfig&VMC=base_ethernet&PM=base_sysmanage&redirectType=$targetProto&ReplayType=$ReplayType");
                exit;
            }
        }

        //
        //-- Own page logic:
        //

        // We override the cached 'System' object and fetch it directly from CCE, as there might have been changes:
        $System = $CI->cceClient->getObject('System', array('cce_nocache' => 'cce_nocache'));
        $CI->setSystem($System);

        $product = new Product($CI->cceClient);

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/network/ethernet");
        $BxPage->setErrors($errors);

        // Primary IP is changing. Show redirect 'error' message:
        if ($redirect != "") {
            $redir_msg[] = '<div class="alert dismissible alert_green"><img width="40" height="36" src="/.elm/images/icons/small/white/alarm_bell.png"><strong>' . $i18n->interpolateHtml('[[base-network.adminRedirect]]') . '</strong></div>';
            $errors = array_merge($redir_msg, $errors);
        }

        // Show OpenVZ message:
        if (in_array($System['IPType'], array('VZv4', 'VZv6', 'VZBOTH'))) {
            $vps_msg[] = '<div class="alert dismissible alert_green"><img width="40" height="36" src="/.elm/images/icons/small/white/alarm_bell.png"><strong>' . $i18n->interpolateHtml('[[base-network.openvz_vps]]') . '</strong></div>';
            $errors = array_merge($vps_msg, $errors);
        }

        // Get errorMsg from URL string.
        if (isset($get_form_data['errorMsg'])) {
            $errors[] = @json_decode(urldecode($get_form_data['errorMsg']));
        }

        // Set Menu items:
        $BxPage->setVerticalMenu('base_serverconfig');
        $BxPage->setVerticalMenuChild('base_ethernet');
        $page_module = 'base_sysmanage';

        $default_page = 'primarySettings';
        if (($fieldprot == "rw") && ($is_aws == "0")) {
            // Show "Interface Aliasses" if not inside a VPS:
            //$pages = array($default_page, 'aliasSettings');
            $pages = array($default_page);
        }
        else {
            // Hide "Interface Aliasses" inside a VPS:
            $pages = array($default_page);
        }

        $block = $factory->getPagedBlock("tcpIpSettings", $pages);

        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        //$block->setShowAllTabs("#");
        $block->setDefaultPage($default_page);

        if ($redirect != "") {
            $oldIP = $_SERVER['SERVER_ADDR'];
            $port = $_SERVER['SERVER_PORT'];
            $reconnect = $factory->getButton("http://$redirect:$port/network/ethernet", 'reconnect');
            $fallback = $factory->getButton("http://$oldIP:$port/network/ethernet", 'oldIPReconnect');

            $buttonRSContainer = $factory->getButtonContainer("tcpIpSettings", array($reconnect, $fallback));
            $block->addFormField(
                $buttonRSContainer,
                $factory->getLabel("tcpIpSettings"),
                $default_page
            );
        }

        //
        //--- TAB: primarySettings
        //

        // host and domain names
        $hostfield = $factory->getDomainName("hostNameField", $System["hostname"], $fieldprot);
        $domainfield = $factory->getDomainName("domainNameField", $System["domainname"], $fieldprot);

        $fqdn = $factory->getCompositeFormField(array($hostfield, $domainfield), '&nbsp;.&nbsp;');

        $block->addFormField(
            $fqdn,
            $factory->getLabel("enterFqdn"), 
            $default_page
        );

        $dns = $factory->getIpAddressList("dnsAddressesField", $System["dns"], $fieldprot);
        $dns->setOptional(true);
        $dns->setType('ipaddr_list_IPv4IPv6');
        $block->addFormField(
          $dns,
          $factory->getLabel("dnsAddressesField"),
          $default_page
        );

        if ($product->isRaq()) {
            if ($is_aws == "1") {
                if (!isset($System["gateway"])) {
                    // AWS and Gateway not defined. Make it editable:
                    $gwFprot = 'rw';
                }
                else {
                    if ($System["gateway"] == "") {
                        // AWS and Gateway not set. Make it editable:
                        $gwFprot = 'rw';
                    }
                    else {
                        // AWS, Gateway is set and not empty. Show it.
                        // But do not allow to edit it:
                        $gwFprot = 'r';
                    }
                }
            }
            else {
                // Not AWS. Allow edits if they are allowed for any of
                // the other network related fields:
                $gwFprot = $fieldprot;
            }
            $gw = $factory->getIpAddress("gatewayField", $System["gateway"], $gwFprot);
            $gw->setOptional(true);
            $block->addFormField($gw, $factory->getLabel("gatewayField"), $default_page);

            $gatewayFieldOrig = $factory->getIpAddress("gatewayFieldOrig", $System["gateway"], "");
            $block->addFormField(
                    $gatewayFieldOrig,
                    $factory->getLabel("gatewayField"),
                    $default_page
                    );
        }

        if ($product->isRaq()) {
            if ($is_aws == "1") {
                if (!isset($System["gateway_IPv6"])) {
                    // AWS and Gateway not defined. Make it editable:
                    $gwFprot = 'rw';
                }
                else {
                    if ($System["gateway_IPv6"] == "") {
                        // AWS and Gateway not set. Make it editable:
                        $gwFprot = 'rw';
                    }
                    else {
                        // AWS, Gateway is set and not empty. Show it.
                        // But do not allow to edit it:
                        $gwFprot = 'r';
                    }
                }
            }
            else {
                // Not AWS, but OpenVZ Container: 
                if (in_array($System['IPType'], array('VZv4', 'VZv6', 'VZBOTH'))) {
                    $gwFprot = '';
                }
                else {
                    // Allow edits if they are allowed for any of
                    // the other network related fields:
                    $gwFprot = $fieldprot;
                }
            }
            $gw_IPv6 = $factory->getIpAddress("gatewayField_IPv6", $System["gateway_IPv6"], $gwFprot);
            $gw_IPv6->setOptional(true);
            $gw_IPv6->setType('ipaddrIPv6');
            $block->addFormField($gw_IPv6, $factory->getLabel("gatewayField_IPv6"), $default_page);

            $xxx = $factory->getIpAddress("gatewayFieldOrig_IPv6", $System["gateway_IPv6"], "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("gatewayField_IPv6"),
                    $default_page
                    );
        }

        // Bridged Network:
        if ((is_file("/etc/is_aws")) || (is_file('/proc/user_beancounters')) || (is_file('/dev/incus/sock'))) {
            # We are a container! No Bridge possible!
            $System['bridged_network'] = 0;
        }

        $HAVE_DHCP = $CI->cceClient->findx('Network', array('bootproto' => "dhcp"), array(), 'ascii', 'device');
        if (count($HAVE_DHCP) > 0) {
            # We are using DHCP! No Bridge possible!
            $System['bridged_network'] = 0;
        }

        $ffs = $factory->getBoolean("bridged_network", $System['bridged_network']);
        $block->addFormField(
            $ffs,
            $factory->getLabel("bridged_network")
        );

        // real interfaces
        // ascii sorted, this may be a problem if there are more than 10 interfaces
        $interfaces = $CI->cceClient->findx('Network', array('real' => '1'), array(), 'ascii', 'device');

        // Fallback:
        if (count($interfaces) === 0) {
            $found_interfaces = find_eth_ifaces();
            $interfaces = array();
            foreach ($found_interfaces as $key => $fb_if) {
                $if_oid = $CI->cceClient->find('Network', array('device' => $fb_if));
                if (($if_oid != '-1') || (isset($if_oid['0']))) {
                    $interfaces[] = $if_oid[0];
                }
            }
        }

        $devices = array();
        $deviceList = array();
        $devnames = array();
        $i18n = $factory->getI18n();
        $admin_if = '';
        for ($i = 0; $i < count($interfaces); $i++) {

            $is_admin_if = false;
            $iface = $CI->cceClient->get($interfaces[$i]);
            $device = $iface['device'];
            
            // save the devices and strings for javascript fun
            $deviceList[] = $device;
            $devices[] = "'$device'";    
            $devnames[] = "'" . $i18n->getJs("[[base-network.interface$device]]") . "'";

                // Devices:
                $dev[$device] = array (
                                'ipaddr' => $iface["ipaddr"],
                                'netmask' => $iface["netmask"],
                                'ipaddr_IPv6' => $iface["ipaddr_IPv6"],
                                'mac' => $iface["mac"],
                                'device' => $device,
                                'bootproto' => $iface["bootproto"],
                                'enabled' => $iface["enabled"]
                                );

        }

        $primary_interface = get_primary_interface();
        $have_interfaces = find_eth_ifaces();

        if (isset($dev[$primary_interface])) {
            $ipaddr = $dev[$primary_interface]['ipaddr'];
            $netmask = $dev[$primary_interface]['netmask'];
            $ipaddr_IPv6 = $dev[$primary_interface]['ipaddr_IPv6'];
            $device = $dev[$primary_interface]['device'];
            $mac = $dev[$primary_interface]['mac'];
            $enabled = $dev[$primary_interface]['enabled'];
            $bootproto = $dev[$primary_interface]['bootproto'];

            if ($bootproto === 'dhcp') {
                $ipaddr = get_primary_ipv4_ip($primary_interface);
                $ipaddr_IPv6 = get_primary_ipv6_ip($primary_interface);
                $netmask = get_primary_ipv4_netmask($primary_interface);
            }
            
            $ip_label = 'ipAddressField1';
            $nm_label = 'netMaskField1';
            $ip_label_IPv6 = 'IPv6_ipAddressField';

            // Add divider:
            $ifaceDivider = $factory->addBXDivider("interface$device", "");
            $block->addFormField(
                    $ifaceDivider,
                    $factory->getLabel("interface$device", false),
                    $default_page
                    );

            if ($is_aws == "0") {
                $devprot = "rw";
            }
            else {
                $devprot = "r";
            }

            // Bootproto:
            $proto_Choices = array("none" => "Manual", "dhcp" => "DHCP");
            $proto_select = $factory->getMultiChoice("bootprotoField$primary_interface", array_values($proto_Choices));
            $proto_select->setSelected($proto_Choices[$bootproto], true);
            //$block->addFormField($proto_select, $factory->getLabel("bootprotoField$device"), $default_page);
            $block->addFormField($proto_select, $factory->getLabel("bootprotoField"), $default_page);

            $ip_field0 = $factory->getIpAddress("ipAddressField$device", $ipaddr, $devprot);
            $ip_field0->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
            $ip_field0->setCurrentLabel($i18n->getHtml('[[base-network.ipAddressField1]]', true, array(), array('name' => "[[base-network.help$device]]")));
            $ip_field0->setDescription($i18n->getWrapped('[[base-network.ipAddressField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
            $ip_field0->setOptional(true);

            $block->addFormField(
                    $ip_field0,
                    $factory->getLabel($ip_label, true, array(), array('name' => "[[base-network.help]]")),
                    $default_page
                );

            $netmask_field0 = $factory->getIpAddress("netMaskField$device", $netmask, $devprot);
            $netmask_field0->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
            $netmask_field0->setCurrentLabel($i18n->getHtml('[[base-network.netMaskField1]]', true, array(), array('name' => "[[base-network.help$device]]")));
            $netmask_field0->setDescription($i18n->getWrapped('[[base-network.netMaskField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));

            // Netmask is not optional for the admin iface and for eth0
            $netmask_field0->setOptional(true);
            
            $block->addFormField(
                    $netmask_field0,
                    $factory->getLabel($nm_label, true, array(), array('name' => "[[base-network.help]]")),
                    $default_page
                );

            // IPv6:
            $IPv6_ip_field0 = $factory->getIpAddress("IPv6_ipAddressField$device", $ipaddr_IPv6, $devprot);
            $IPv6_ip_field0->setInvalidMessage($i18n->getJs('IPv6_ipAddressField_invalid'));
            $IPv6_ip_field0->setCurrentLabel($i18n->getHtml('[[base-network.IPv6_ipAddressField1]]', true, array(), array('name' => "[[base-network.help$device]]")));
            $IPv6_ip_field0->setDescription($i18n->getWrapped('[[base-network.IPv6_ipAddressField1_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
            $IPv6_ip_field0->setOptional(true);
            $IPv6_ip_field0->setType('ipaddrIPv6');

            $block->addFormField(
                    $IPv6_ip_field0,
                    $factory->getLabel($ip_label_IPv6, true,
                                array(), array('name' => "[[base-network.help]]")),
                    $default_page
                );

            // MAC:
            $macaddress_field0 = $factory->getMacAddress("macAddressField$device", $mac, "r");
            $macaddress_field0->setCurrentLabel($i18n->getHtml('[[base-network.macAddressField]]', true));
            $macaddress_field0->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));

            $block->addFormField(
                    $macaddress_field0,
                    $factory->getLabel("macAddressField"),
                    $default_page
                );

            // retain orginal information
            $xxx = $factory->getBoolean("hasAliases$device", 0, '');
            $block->addFormField($xxx);

            $xxx = $factory->getIpAddress("ipAddressOrig$device", $ipaddr, "");
            $block->addFormField(
                    $xxx,
                    '',
                    $default_page
                    );

            $xxx = $factory->getIpAddress("IPv6_ipAddressOrig$device", $ipaddr_IPv6, "");
            $block->addFormField(
                    $xxx,
                    '',
                    $default_page
                    );

            $xxx = $factory->getIpAddress("netMaskOrig$device", $netmask, "");
            $block->addFormField(
                    $xxx,
                    "",
                    $default_page
                    );

            $xxx = $factory->getBoolean("enabled$device", $enabled, "");
            $block->addFormField(
                    $xxx,
                    "",
                    $default_page
                    );

            $xxx = $factory->getBoolean("bootprotoOrig$device", $enabled, "");
            $block->addFormField(
                    $xxx,
                    "",
                    $default_page
                    );

            // Remove primary interface from list:
            $device_to_remove = $primary_interface;

            // Find the index of the device to remove
            $index = array_search($device_to_remove, $have_interfaces);

            // If the device is found, remove it
            if ($index !== false) {
                unset($have_interfaces[$index]);
            }

            // Reindex the array to maintain numeric keys
            $have_interfaces = array_values($have_interfaces);
        }

        foreach ($have_interfaces as $device) {
            $ipaddr_IPv6 = '';
            $ipaddr = '';
            $netmask = '';
            $enabled = '0';
            if (isset($dev[$device])) {
                $ipaddr = $dev[$device]['ipaddr'];
                $IPv6_ipaddr = $dev[$device]['ipaddr_IPv6'];
                $netmask = $dev[$device]['netmask'];
                $device = $dev[$device]['device'];
                $mac = $dev[$device]['mac'];
                $enabled = $dev[$device]['enabled'];
                $bootproto = $dev[$device]['bootproto'];

                if ($bootproto === 'dhcp') {
                    $ipaddr = get_primary_ipv4_ip($device);
                    $IPv6_ipaddr = get_primary_ipv6_ip($device);
                    $netmask = get_primary_ipv4_netmask($device);
                }

                $ip_label = '[[base-network.ipAddressField1]]';
                $nm_label = '[[base-network.netMaskField1]]';
                $ipV6_label = '[[base-network.IPv6_ipAddressField]]';

                if ($enabled == "0") {
                    $ipaddr = "";
                    $netmask = "";
                }

                if (($is_aws == "0") && ($bootproto == 'none')) {
                    $devprot = "rw";
                }
                else {
                    $devprot = "r";
                }

                $ip_label = 'ipAddressField1';
                $nm_label = 'netMaskField1';

                // Add divider:
                $divider = $factory->addBXDivider("interface$device", "");
                $divider->setCurrentLabel($i18n->get("[[base-network.interface$device]]", false));
                $block->addFormField(
                        $divider,
                        $factory->getLabel("[[base-network.interface]]", false),
                        $default_page
                    );

                // Bootproto:
                $proto_Choices = array("none" => "Manual", "dhcp" => "DHCP");
                $proto_select = $factory->getMultiChoice("bootprotoField$device", array_values($proto_Choices));
                $proto_select->setSelected($proto_Choices[$bootproto], true);
                //$block->addFormField($proto_select, $factory->getLabel("bootprotoField$device"), $default_page);
                $block->addFormField($proto_select, $factory->getLabel("bootprotoField"), $default_page);

                $ip_field1 = $factory->getIpAddress("ipAddressField$device", $ipaddr, $devprot);
                $ip_field1->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ip_field1->setCurrentLabel($i18n->get('[[base-network.ipAddressField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field1->setDescription($i18n->getWrapped('[[base-network.ipAddressField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ip_field1->setOptional(true);

                $block->addFormField(
                        $ip_field1,
                        $factory->getLabel($ip_label, true,
                        array(), array('name' => "[[base-network.help]]")),
                        $default_page
                    );

                $netmask_field1 = $factory->getIpAddress("netMaskField$device", $netmask, $devprot);
                $netmask_field1->setInvalidMessage($i18n->getJs('netMaskField_invalid'));
                $netmask_field1->setEmptyMessage($i18n->getJs('netMaskField_empty', 'base-network', array('interface' => "[[base-network.interface$device]]")));
                $netmask_field1->setCurrentLabel($i18n->get('[[base-network.netMaskField2]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field1->setDescription($i18n->getWrapped('[[base-network.netMaskField2_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $netmask_field1->setOptional(true);
                
                $block->addFormField(
                        $netmask_field1,
                        $factory->getLabel($nm_label, true,
                        array(), array('name' => "[[base-network.help]]")),
                        $default_page
                    );

                // IPv6 IP-Address:
                $ipv6_field1 = $factory->getIpAddress("IPv6_ipAddressField$device", $IPv6_ipaddr, $devprot);
                $ipv6_field1->setInvalidMessage($i18n->getJs('ipAddressField_invalid'));
                $ipv6_field1->setCurrentLabel($i18n->get($ipV6_label, true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field1->setDescription($i18n->getWrapped('[[base-network.IPv6_ipAddressField_help]]', true, array(), array('name' => "[[base-network.help$device]]")));
                $ipv6_field1->setOptional(true);
                $ipv6_field1->setType('ipaddrIPv6');
                $block->addFormField(
                        $ipv6_field1,
                        $factory->getLabel($ipV6_label, true,
                        array(), array('name' => "[[base-network.help]]")),
                        $default_page
                    );

                // MAC:
                $macaddress_field1 = $factory->getMacAddress("macAddressField$device", $mac, "r");
                $macaddress_field1->setCurrentLabel($i18n->get('[[base-network.macAddressField]]', true));
                $macaddress_field1->setDescription($i18n->getWrapped('[[base-network.macAddressField_help]]', true));
                $block->addFormField(
                        $macaddress_field1,
                        $factory->getLabel("macAddressField"),
                        $default_page
                    );

                // retain orginal information
                $y_has_aliases = $factory->getBoolean("hasAliases$device", 0, '');
                $block->addFormField(
                        $y_has_aliases,
                        $default_page
                    );

                $y_orig_ip = $factory->getIpAddress("ipAddressOrig$device", $ipaddr, "");
                $block->addFormField(
                        $y_orig_ip,
                        '',
                        $default_page
                        );

                $y_netMaskOrig = $factory->getIpAddress("netMaskOrig$device", $netmask, "");
                $block->addFormField(
                        $y_netMaskOrig,
                        "",
                        $default_page
                    );

                $xxx = $factory->getBoolean("bootprotoOrig$device", $enabled, "");
                $block->addFormField(
                        $xxx,
                        "",
                        $default_page
                        );

                $y_enabled = $factory->getBoolean("enabled$device", $enabled, "");
                $block->addFormField(
                        $y_enabled,
                        "",
                        $default_page
                    );
                }

                // retain orginal information
                $xxx = $factory->getBoolean("hasAliases$device", 0, '');
                $block->addFormField($xxx);

                $xxx = $factory->getIpAddress("ipAddressOrig$device", $ipaddr, "");
                $block->addFormField(
                        $xxx,
                        '',
                        $default_page
                        );

                $xxx = $factory->getIpAddress("IPv6_ipAddressOrig$device", $ipaddr_IPv6, "");
                $block->addFormField(
                        $xxx,
                        '',
                        $default_page
                        );

                $xxx = $factory->getIpAddress("netMaskOrig$device", $netmask, "");
                $block->addFormField(
                        $xxx,
                        "",
                        $default_page
                        );

                $xxx = $factory->getBoolean("bootprotoOrig$device", $enabled, "");
                $block->addFormField(
                        $xxx,
                        "",
                        $default_page
                        );

                $xxx = $factory->getBoolean("enabled$device", $enabled, "");
                $block->addFormField(
                        $xxx,
                        "",
                        $default_page
                        );

            }

        // add a hidden field indicating which interface is the admin interface
        $xxx = $factory->getTextField('adminIf', $primary_interface, '');
        $block->addFormField($xxx);

        $xxx = $factory->getTextField('deviceList', $CI->cceClient->array_to_scalar($deviceList), '');
        $block->addFormField($xxx);

        //
        //--- TAB: aliasSettings
        //

        if ((in_array($System['IPType'], array('IPv4', 'IPv6', 'BOTH'))) && (!is_file("/etc/is_aws"))) {
            // Add-Button:
            $addAlias = "/network/aliasModify";
            $addbutton = $factory->getAddButton($addAlias, '[[base-network.addAliasButton]]', "DEMO-OVERRIDE");
            $buttonContainer = $factory->getButtonContainer("aliasSettings", $addbutton);
            $block->addFormField(
                $buttonContainer,
                $factory->getLabel("aliasSettings"),
                'aliasSettings'
            );

            // add scrollist of aliases
            $alias_list = $factory->getScrollList("aliasSettings", array('aliasName', 'aliasIpaddr', 'aliasNetmask', 'aliasActions'), array()); 
            $alias_list->setAlignments(array("left", "left", "center", "center"));
            $alias_list->setDefaultSortedIndex('0');
            $alias_list->setSortOrder('ascending');
            $alias_list->setSortDisabled(array('3'));
            $alias_list->setPaginateDisabled(FALSE);
            $alias_list->setSearchDisabled(FALSE);
            $alias_list->setSelectorDisabled(FALSE);
            $alias_list->enableAutoWidth(FALSE);
            $alias_list->setInfoDisabled(FALSE);
            $alias_list->setColumnWidths(array("320", "178", "120", "120")); // Max: 739px
        
            $sort_map = array('device', 'ipaddr', 'netmask');
            $networks = $CI->cceClient->findx(
                          'Network', array('real' => 0), array(),
                          'ascii', $sort_map[$alias_list->getSortedIndex()]);

            for($i=0; $i < count($networks); $i++) {
                // must be an alias
                $alias = $CI->cceClient->get($networks[$i]);
                $device_info = preg_split('/:/', $alias['device']);
                $alias_name = $i18n->interpolateHtml('[[base-network.alias' .
                                     $device_info[0] . ']]',
                                     array('num' => $device_info[1]));
                
                $modButt = $factory->getModifyButton("/network/aliasModify?ACTION=M&_oid=$networks[$i]");
                $modButt->setImageOnly(TRUE);
                $delButt = $factory->getRemoveButton("/network/aliasModify?ACTION=D&_oid=$networks[$i]");
                $delButt->setImageOnly(TRUE);
            
                $alias_list->addEntry(
                              array(
                                $alias_name,
                                $alias['ipaddr'],
                                $alias['netmask'],
                                $factory->getCompositeFormField(
                                                array(
                                                  $modButt,
                                                  $delButt
                                                  )
                                                )
                                ));
              }

            // Push out the Scrollist with the aliasSettings:
            $xxx = $factory->getRawHTML("aliasSettings", $alias_list->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("aliasSettings"),
                'aliasSettings'
            );
        }

        //
        //--- Add the buttons
        //

        // Only add the save button if looking at primary settings AND we're not inside a VPS:
        if ($fieldprot == "rw") {
            $block->addButton($factory->getSaveButton($BxPage->getSubmitAction()));
            $block->addButton($factory->getCancelButton("/network/ethernet"));
        }

        //$routeButton = $factory->getButton("/network/routes", "routes", "DEMO-OVERRIDE");
        //$buttonRouteContainer = $factory->getButtonContainer(" ", array($routeButton));
        //$page_body[] = $buttonRouteContainer->toHtml();

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

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