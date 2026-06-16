<?php
namespace Swupdate\Controllers;
use App\Controllers\BaseController;
include_once ("I18n.php");
include_once ("BxPage.php");
use I18n;
use BxPage;

class Shop extends BaseController {
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

        if (!$CI->getAllowed('managePackage')) {
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
        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-swupdate", "/swupdate/shop");
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
        //
        //--- Handle form validation:
        //
        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');
        $get_form_data = $BxPage->getGETPOST('GET');

        // -- Actual page logic start:
        $categories = array();

        // Get CODB-Object Shop:
        $shopObj = $CI->cceClient->getObject("Shop", array(), "");
        $api_url = $shopObj['shop_url'];
        $cat_from_codb = $shopObj['shop_category'];

        // Get Serial:
        $serialNumber = $System['serialNumber'];

        if (strlen($serialNumber) == 0) {
            $url_ext = '';
        }
        else {
            $url_ext = $serialNumber;
        }

        if ($BX_SESSION['gui_theme'] == 'adminica') {
            $H_open = "<H3>";
            $H_close = "</H3>";
        }
        else {
            $H_open = "<H6>";
            $H_close = "</H6>";
        }

        // Location (URLs) of the various NewLinQ query resources:
        $bluelinq_server = 'newlinq.blueonyx.it';
        $shoplist_url = "http://$bluelinq_server/showshops/$url_ext";
        $categories_url = "http://$bluelinq_server/showcategories/$url_ext";
        $products_url = "http://$bluelinq_server/showproducts/$url_ext";
        $catprod_url = "http://$bluelinq_server/showcatprod/$url_ext";

        $NL_Responding_Fine = 0;
        $check_output = get_data($shoplist_url);
        if (preg_match('/shop\.blueonyx\.it\",\"AUD\"/', $check_output)) {
            $NL_Responding_Fine = 1;
        }

        // Check if we are online:
        if (($NL_Responding_Fine) && (areWeOnline($shoplist_url, "4"))) {

            // Poll NewLinQ about our status:
            $snstatus = "RED";
            $snstatus = get_data("http://$bluelinq_server/snstatus/$serialNumber");
            if (!$snstatus === "RED") {
                $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
            }
            else {
                if ($snstatus === "ORANGE") {
                    $string = $i18n->interpolateHtml("[[status-sn$snstatus]]");
                    $snstatusx = get_data("http://$bluelinq_server/snchange/$serialNumber");
                }
                else {
                    $ipstatus = get_data("http://$bluelinq_server/ipstatus/$serialNumber");
                    $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                    if ($ipstatus === "ORANGE") {
                        $string = $i18n->interpolateHtml("[[status-ip$ipstatus]]");
                        $ipstatusx = get_data("http://$bluelinq_server/ipchange/$serialNumber");
                    }
                }
            }

            $goodStatus = array("RED", "ORANGE", "GREEN");
            if (!in_array($snstatus, $goodStatus)) {
                $snstatus = "ORANGE";
            }

            // Are we online and in the green?
            if ($snstatus === "GREEN") {
                $online = "1";
            }
        }
        else {
            // Not online, poll of 'newlinq.blueonyx.it' failed. Show error message and good bye:
            $online = "0";

            $errors [] = ErrorMessage($i18n->getHtml("[[base-shop.ErrorMSGdesc]]"), $type="alert_navy", $icon="alert_2", TRUE);

            // Prepare Page:
            $BxPage->setFormUrl("/swupdate/shop");
            $BxPage->setErrors($errors);

            // Set Menu items:
            $BxPage->setVerticalMenu('base_software');
            $page_module = 'base_software';

            $page_body[] = addInputForm($i18n->get("[[base-shop.ShopSelector_General_head]]", false, array()), array("toggle" => "#"), '               <div class="flat_area grid_16">
                                                                ' . $H_open . $i18n->getHtml("[[base-shop.ErrorMSGNoProducts]]", false) . $H_close . '
                                                                <p>' . $i18n->getHtml('[[palette.emptyList]]') . '</p>
                                                            </div>', "", $i18n, $BxPage, $errors);

            // Out with the page:
            return $BxPage->render($page_module, $page_body);
        }

        // Well, we're at least online. So let's continue:
        if (($snstatus === "RED") || ($snstatus === "ORANGE") || ($snstatus === "GREEN")) {

            // Prepare Page:
            $BxPage->setFormUrl("/swupdate/shop");
            $BxPage->setErrors($errors);

            // Set Menu items:
            $BxPage->setVerticalMenu('base_software');
            $page_module = 'base_software';

            // Extra JavaScript to handle CAT_SELECTOR:
            $BxPage->setExtraHeaders('
                <SCRIPT LANGUAGE="javascript">
                // Javascript
                // Javascript function to go to a new page defined by a SELECT element
                function goToPage( id ) {
                  var node = document.getElementById( id );
                  // Check to see if valid node and if node is a SELECT form control
                  if( node &&
                    node.tagName == "SELECT" ) {
                    // Go to web page defined by the VALUE attribute of the OPTION element
                    window.location.href = node.options[node.selectedIndex].value;
                  } // endif
                }
                </SCRIPT>');

            $BxPage->setExtraHeaders('
                    <script>
                        $(document).ready(function() {
                            $(".various").fancybox({
                                overlayColor: "#000",
                                fitToView   : false,
                                width       : "80%",
                                height      : "80%",
                                autoSize    : false,
                                closeClick  : false,
                                openEffect  : "none",
                                closeEffect : "none"
                            });
                        });
                    </script>');

            $start_time = time();

            // Process the Shoplist:
            $output = get_data($shoplist_url);
            $output = preg_replace('/"/', '', $output);
            $arr_shoplist = explode("\n", $output);
            $numshop = "0";

            // Legend:
            // 0 = shop_id    (numerical shop ID)
            // 1 = shop_url   (URL)
            // 2 = shop_cur   (shop currency)
            foreach ($arr_shoplist as $items) {
                $item = explode(",", $items);
                if ((isset($item[0])) && (isset($item[1])) && (isset($item[2]))) {
                    $shop_id[] = $item[0];
                    // Start: Small work around for wrong NewLinQ response on shop URLs
                    if ($item[1] == "shop.solarspeed.net") {
                        $new_item = "www.solarspeed.net";
                        $shop_url[] = $new_item;
                    }
                    elseif ($item[1] == "www2.compassnetworks.com.au") {
                        $new_item = "www.compassnetworks.com.au";
                        $shop_url[] = $new_item;
                    }
                    else {
                        $shop_url[] = $item[1];
                    }
                    // End: Small work around for wrong NewLinQ response on shop URLs
                    $shop_cur[] = $item[2];
                    $numshop++;
                }
            }

            // No shop set? Define default:
            if (!isset($shop_url)) {
                $shop_url[] = 'shop.blueonyx.it';
                $shop_cur[] = 'AUD';
            }

            // Process the Categories:
            $output = get_data($categories_url);
            $output = preg_replace('/"/', '', $output);
            $arr_catlist = explode("\n", $output);

            foreach ($arr_catlist as $items) {
                $item = explode(",", $items);
                if (isset($item[1])) {
                    // For now we ignore the empty platform specific categories that are just there for historic reasons:
                    if (($item[1] != "blueonyx/5106r") && ($item[1] != "blueonyx/5107r") && ($item[1] != "blueonyx/5108r")) {
                        $categories[$item[0]] = $item[1];
                    }
                }
            }

            // Process the Products:
            $output = get_data($products_url);

            // The parsed CSV of the product list has each product end with a quotation mark followed by a newline.
            // So this is where we split the products:
            $arr_prodlist = preg_split('/"\n/', $output, -1, PREG_SPLIT_NO_EMPTY);

            $products = array();
            foreach ($arr_prodlist as $key => $items) {
                $item = explode(",", $items);
                // Legend:
                // 0 = product_id
                // 1 = product_name
                // 2 = product_url
                // 3 = product_img
                // 4 = category
                // 5 = product_desc
                $index = preg_replace('/"/', '', $item[0]);
                $products[$index]["product_id"] = preg_replace('/"/', '', $item[0]);
                $products[$index]["product_name"] = preg_replace('/"/', '', $item[1]);
                $products[$index]["product_url"] = preg_replace('/"/', '', $item[2]);
                $products[$index]["product_img"] = preg_replace('/"/', '', $item[3]);
                $products[$index]["category"] = "n/a"; // We set this to a default early on and sort it further below.
                // Element $item[4] contains a leading double quotation mark, which we need to remove:
                $item[4] = preg_replace('/"/', '', $item[4]);
                // Now it gets messy. As we split $item at the ',' and in the product description we also have them for sure.
                // So the descriptions are probably split up as well. We first remove the four known items via unset() and then
                // impode() the rest back together to get the full description again:
                unset($item[0]);
                unset($item[1]);
                unset($item[2]);
                unset($item[3]);
                // Assemble the product description again:
                $product_desc = implode(",", $item);
                //
                // Clean up some translational issues:
                //
                // Remove newlines:
                $product_desc = preg_replace("/[\n\r]/", '', $product_desc);
                // Replace &#34; with ":
                $product_desc = preg_replace('/&#34;/', '"', $product_desc);
                // Replace \N - And the joys of UTF-8: We have to triple-escape the slash:
                $product_desc = preg_replace("/\\\\N/", '', $product_desc);
                // Need to replace this:
                // <span style="font-family: verdana,arial,helvetica,sans-serif; font-size: x-small;">
                $product_desc = preg_replace('/<span style="(.*)">/i', '', $product_desc);
                $product_desc = preg_replace('/<\/span>/i', '', $product_desc);
                // Need to remove links in the description:
                // <a href="http://www.group-office.com/">GroupOffice</a>
                $product_desc = preg_replace('/<a href="(.*)">/i', '', $product_desc);
                $product_desc = preg_replace('/<\/a>/i', '', $product_desc);

                // Finally, we have a cleaned up product description:
                $products[$index]["product_desc"] = $product_desc;
            }

            // Process the Catprod and map the products to their parent categories:
            $output = get_data($catprod_url);
            $output = preg_replace('/"/', '', $output);
            $arr_catprods = explode("\n", $output);
            foreach ($arr_catprods as $items) {
                $item = explode(",", $items);
                if (isset($categories[$item[0]])) {
                    $products[$item[1]]["category"] = $categories[$item[0]];
                }
            }
        }
        $end_time = time();

        // Do we have form data?
        if (isset($form_data["SHOP_SELECTOR"])) {
            // If so, set the shop selector to the submitted form data:
            $needle = $form_data["SHOP_SELECTOR"];
            $api_url = $shop_url[$needle];
        }

        if (isset($get_form_data["CAT_SELECTOR"])) {
            // If so, set the category selector to the submitted form data:
            $needle = $get_form_data["CAT_SELECTOR"];
            $cat = $needle;
        }

        if ((isset($form_data["SHOP_SELECTOR"])) || (isset($get_form_data["CAT_SELECTOR"]))) {
            if (isset($get_form_data["CAT_SELECTOR"])) {
                $cat = $get_form_data["CAT_SELECTOR"];
            }

            if (isset($form_data["CAT_SELECTOR"])) {
                $long_cat = preg_split('/=/', $form_data["CAT_SELECTOR"]);
                $cat = $long_cat[1];
            }

            // If we have no errors and have POST data, we submit to CODB:
            if (((count($errors) == "0") && ($this->request->getPost(NULL, NULL, TRUE))) || ((count($errors) == "0") && ($this->request->getGet(NULL, NULL, TRUE)))) {
                if (isset($form_data["SHOP_SELECTOR"])) {
                    $CI->cceClient->setObject("Shop", array("shop_url" => $api_url, "update" => time()), "");
                }
                if (isset($cat)) {
                    $CI->cceClient->setObject("Shop", array("shop_category" => $cat, "update" => time()), "");
                }
                // CCE errors that might have happened during submit to CODB:
                $errors = $CI->cceClient->errors();

                // Return to sender:
                $BxPage->ReturnToThisPage($errors, '/swupdate/shop');
            }
        }
        else {
            // Set current category to the last one the user visited (as stored in CODB):
            $cat = $cat_from_codb;
        }

        //
        //### Shop Selector:
        //
        // Selector for Categories:
        //
        // And yes, this is dirty, but the only other way around this would require chanegs to UIFC again:
        $Shop_Label_CatSelector = $i18n->getHtml('[[base-shop.CAT_SELECTOR]]');

        if (is_array($categories)) {
            array_multisort($categories, SORT_STRING, SORT_ASC);
        }

        // Fallback:
        $extra_page_body = "";
        if (!$cat) {
            $cat = "1";
        }

        //-- Generate page:
        if (!isset($BxPage)) {
            // Prepare Page:
            $BxPage = $factory->getPage();
            $BxPage->setFormUrl("/swupdate/shop");
            $BxPage->setErrors($errors);
        }

        if ($BX_SESSION['gui_theme'] == 'adminica') {

            //
            //-- Show shop and category selector block for Adminica:
            //

            // Assemble pulldown HTML code on foot:
            $extra_page_body .= "\n" . '                                 <fieldset class="label_side top">
                                            <label for="CAT_SELECTOR" title="' . $i18n->getHtml("[[base-shop.CAT_SELECTOR_help]]", false) . '" class="tooltip left"> ' . $Shop_Label_CatSelector . '</label>
                                            <div class="clearfix">' . "<select name=\"CAT_SELECTOR\" id=\"CAT_SELECTOR\" onchange=\"goToPage('CAT_SELECTOR')\">";
            foreach ($categories as $cats) {
                if ($cats == $cat) {
                    $selected = " selected=\"selected\"";
                }
                else {
                    $selected = "";
                }
                $extra_page_body .= "<option $selected value=\"/swupdate/shop?CAT_SELECTOR=" . urlencode($cats) . "\">$cats</option>";
            }
            $extra_page_body .= "\n" . "</select></p>";
            $extra_page_body .= "\n" . '</p></div></fieldset>';

            $page_body[] = addInputForm($i18n->get("[[base-shop.ShopSelector_General_head]]", false, array()), array("toggle" => "#"), '               <div class="flat_area grid_16">
                                                                <h2>' . $i18n->getHtml("[[base-shop.ShopSelector_General_configuration]]", false) . '</h2>
                                                                <p>' . $i18n->getHtml('[[base-shop.ShopSelector_Info_Text]]') . '</p>
                                                            </div>' .

            addPullDown("SHOP_SELECTOR", $shop_url, $api_url, "base-shop", $i18n) . $extra_page_body, addSaveButton($i18n), $i18n, $BxPage, $errors);
        }
        else {

            //
            //-- Show shop and category selector block for Elmer:
            //

            $defaultPage = "default";

            $block = $factory->getPagedBlock($i18n->get("[[base-shop.ShopSelector_General_configuration]]", false), array($defaultPage));

            $block->setToggle("#");
            $block->setSideTabs(FALSE);
            $block->setShowAllTabs("#");
            $block->setDefaultPage($defaultPage);

            $ShopSelector_Info_Text = $factory->getRawHTML("ShopSelector_Info_Text", '<p>' . $i18n->getHtml('[[base-shop.ShopSelector_Info_Text]]') . '</p>', "r");
            $block->addFormField(
                $ShopSelector_Info_Text,
                $factory->getLabel("ShopSelector_Info_Text"),
                $defaultPage
            );

            // Pulldowns:
            $elmer_pulldown_list = array();

            if (count($shop_url) > 0) {
                // Shop Selector pulldown:
                ksort($shop_url);
                $ShopSelectorPulldown = $factory->getMultiButton("SHOP_SELECTOR", array_keys($shop_url), array_values($shop_url));
                $shop_url_flipped = array_flip($shop_url);
                $ShopSelectorPulldown->setSelectedIndex($shop_url_flipped[$api_url]);
                $ShopSelectorPulldown->setText("");
                $ShopSelectorPulldown->setOnchange('SUBMIT');
                $elmer_pulldown_list[] = $ShopSelectorPulldown;

                // Category pulldown:
                $cat_selector_data = array();
                $selectedCat = '';

                $catCounter = 0;
                foreach ($categories as $cats) {
                    $cat_selector_data[$cats] = "/swupdate/shop?CAT_SELECTOR=" . urlencode($cats);
                    if ($cats == $cat) {
                        $selectedCat = $catCounter;
                    }
                    $catCounter++;
                }

                $CatSelectorPulldown = $factory->getMultiButton("CAT_SELECTOR", array_values($cat_selector_data), array_keys($cat_selector_data));
                $CatSelectorPulldown->setSelectedIndex($selectedCat);
                $CatSelectorPulldown->setText("");
                $elmer_pulldown_list[] = $CatSelectorPulldown;

            }

            $elmer_header_list = array();

            $SHOP_SELECTOR_label = Label("base-shop", "SHOP_SELECTOR", $i18n);
            $elmer_header_list[] = $factory->getRawHTML("SHOP_SELECTOR_info", $SHOP_SELECTOR_label, "r");

            $CAT_SELECTOR_label = Label("base-shop", "CAT_SELECTOR", $i18n);
            $elmer_header_list[] = $factory->getRawHTML("CAT_SELECTOR_info", $CAT_SELECTOR_label, "r");

            $header_list = $factory->getCompositeFormField($elmer_header_list, '');
            $header_list->setColumnWidths(array('col_25', 'col_25', 'col_25'));
            $header_list->setClass('pt-10');

            $block->addFormField(
                $header_list,
                $factory->getLabel(" "),
                $defaultPage
            );

            $pulldown_list = $factory->getCompositeFormField($elmer_pulldown_list, '');
            $pulldown_list->setColumnWidths(array('col_25', 'col_25', 'col_25'));
            $pulldown_list->setClass('pb-20');

            $block->addFormField(
                $pulldown_list,
                $factory->getLabel(" "),
                $defaultPage
            );

            $page_body[] = $block->toHtml();

        }

        //
        //-- Show Shop Products:
        //
        $ProductsTable = array();
        foreach ($categories as $cats) {
            foreach ($products as $key => $product) {
                if ($cats == $product["category"]) {
                    $cat_product[$cats][] = $product;
                }
            }

            // Count number of Products in this category:
            if (isset($cat_product[$cats])) {
                $num_prods = count($cat_product[$cats]);
            }
            else {
                $num_prods = "0";
            }

            if ($cat == $cats) {
                if ($num_prods > "0") {
                    foreach ($cat_product[$cats] as $product) {
                        // Populate the scroll list rows:
                        if (is_https() == TRUE) {
                            $proto = 'https://';
                        }
                        else {
                            $proto = 'http://';
                        }

                        if (isset($product["product_id"]) && isset($product["category"]) && isset($product["product_name"]) && isset($product["product_url"]) && isset($product["product_img"])) {
                            $image = $proto . $api_url . '/get.php/media/catalog/product' . $product["product_img"];
                            $url_product = $proto . $api_url . '/index.php/' . $product["category"] . '/' . $product["product_url"];
                            $out_img = "<img src=\"$image\" width=\"*\" height=\"150\" align=\"left\">";
                            $out_prod = $H_open . $product["product_name"] . $H_close . $product["product_desc"];

                            $product_output = <<<HTML
                            <table width="100%" cellspacing="0" cellpadding="5" style="border-collapse:collapse;">
                              <tr>
                                <!-- IMAGE COLUMN -->
                                <td style="width:160px; vertical-align:top;">
                                  <img src="$image"
                                       style="max-width:150px; height:auto; display:block;">
                                </td>

                                <!-- TEXT COLUMN -->
                                <td style="vertical-align:top;">
                                  <div style="font-weight:600; font-size:14px; margin-bottom:4px;">
                                    $H_open {$product["product_name"]} $H_close
                                  </div>

                                  <div style="color:#777; font-size:13px; line-height:1.4;">
                                    {$product["product_desc"]}
                                  </div>
                                </td>
                              </tr>
                            </table>
                            HTML;

                            $product_url_button = $factory->getFancyButton($url_product, $i18n->getWrapped('[[base-shop.openURL_help]]'));
                            $product_url_button->setImageOnly(TRUE);
                            $product_url_button->setButtonSize("small");

                            $ProductsTable[0][] = $product_output;
                            $ProductsTable[1][] = $product_url_button->toHtml();

                        }
                    } // Foreach cat_product
                    
                } // if num_prods
                
            } //if cats
            
        }

        if (!isset($ProductsTable[0])) {

            $defaultPage = "default";
            $np_block = $factory->getPagedBlock($i18n->getHtml("[[base-shop.ErrorMSGNoProducts]]", false), array($defaultPage));
            $np_block->setToggle("#");
            $np_block->setSideTabs(FALSE);
            $np_block->setShowAllTabs("#");
            $np_block->setDefaultPage($defaultPage);

            $ErrorMSGNoProducts_Text = $factory->getRawHTML("ErrorMSGNoProducts", '<p>' . $i18n->getHtml('[[base-shop.ErrorNoProductsInCategory]]') . '</p>', "r");
            $np_block->addFormField(
                $ErrorMSGNoProducts_Text,
                $factory->getLabel("ErrorMSGNoProducts"),
                $defaultPage
            );

            $page_body[] = $np_block->toHtml();
        }
        else {

            // For debugging of the loading time of the cURL requests:
            $scrollList = $factory->getScrollList("products", array($i18n->getHtml("[[base-shop.product_name]]"), $i18n->getHtml("[[base-shop.openURL]]")), $ProductsTable);
            $scrollList->setAlignments(array("left", "center"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('1'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            $scrollList->setColumnWidths(array("98%", "35"));

            $page_body[] = $scrollList->toHtml();
        }

        // Out with the page:
        return $BxPage->render($page_module, $page_body);

    }
}
/*
Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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