<?php

/**
 * UIFC BlueOnyx Next Generation Helper Library
 *
 * UIFC helper for BlueOnyx NG on Codeigniter
 *
 * @package   CI UIFC NG
 * @author    Michael Stauber
 * @copyright Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
 * @link      http://www.solarspeed.net
 * @license   http://devel.blueonyx.it/pub/BlueOnyx/licenses/SUN-modified-BSD-License.txt
 * @version   3.0
 */

function Donations($i18n, $theme) {
    $donations_txt = $i18n->get("[[base-alpine.call_for_donations]]");
    $donations_txt_sanitized = br2nl($donations_txt);

    if ($theme === 'elmer') {

        $support_blueonyx = $i18n->get("[[base-alpine.support_blueonyx]]");
        $donations_one_txt = $i18n->get("[[base-alpine.donations_one]]");
        $donations_two_txt = $i18n->get("[[base-alpine.donations_two]]");
        $donations_three_txt = $i18n->get("[[base-alpine.donations_three]]");
        $donations_txt = $donations_one_txt . '<br>' . $donations_two_txt . '<br>' . $donations_three_txt;

        $Donations =<<<HTML

                                        <table width="100%" align="center" cellspacing="15" cellpadding="15" border="0" class="mb-15" style="background:#f5f7fa;border:1px solid #d6d9de;padding:15px;border-radius:4px;">
                                            <tbody>
                                                <tr>
                                                    <td style="vertical-align: middle;" class="pa-20">
                                                        <span class="pl-30">
                                                            <a href="https://www.blueonyx.it/support-blueonyx.html" target="_blank">
                                                                <button type="button" class="btn btn-primary btn-icon-anim left-icon" data-toggle="tooltip" data-placement="right" title="$donations_txt_sanitized" data-original-title="$donations_txt_sanitized" data-container="body"><i class="icon-paypal"></i><span class="pl-10" style="font-weight:bold;">Support BlueOnyx</span></button>
                                                            </a>
                                                        </span>
                                                    </td>
                                                    <td class="" style="vertical-align: middle;">
                                                        <span>$donations_txt</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
        HTML;
    }
    else {
        $Donations =<<<HTML
                                        <br>
                                        <div class="box grid_16">
                                            <div class="toggle_container">
                                                <div class="block">
                                                    <fieldset class="label_side top bottom indented_button_bar">
                                                        <label>
                                                        <a href="https://www.paypal.com/donate/?hosted_button_id=NNJDSVJCHQUME" target="_blank" class="light on_dark">
                                                            <img src="/.adm/images/btn_donateCC_LG.gif" alt="PayPal - The safer, easier way to pay online!" />
                                                        </a>
                                                        </label>
                                                        <div class="clearfix">$donations_txt</div>
                                                    </fieldset>
                                                </div>
                                            </div>
                                        </div>
        HTML;
    }
    return $Donations;
}

function StartPageStats($cceClient, $BxPage, $i18n) {

        $maximize_icon_helptext = $i18n->getWrapped("[[palette.icon_maximize]]");
        $cpu_usage_helptext = $i18n->getWrapped("[[base-am.amCPUName]]");
        $memory_usage_helptext = $i18n->getWrapped("[[base-am.amMemoryName]]");
        $network_usage_helptext = $i18n->getWrapped("[[palette.NetworkUsage]]");
        $disk_usage_helptext = $i18n->getWrapped("[[base-disk.groupDiskUsage]]");

        $StartPageStats =<<<HTML
                            <!--- INSERT -->
                            <div class="col-lg-3">
                                <div class="panel panel-default card-view">
                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <h6 class="panel-title txt-dark">$cpu_usage_helptext</h6>
                                        </div>
                                        <div class="pull-right">
                                            <a href="#" class="pull-left inline-block full-screen mr-15">
                                                <span data-toggle="tooltip" data-placement="bottom" title="$maximize_icon_helptext" data-original-title="$maximize_icon_helptext" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                            </a>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="panel-wrapper collapse in">
                                        <div class="panel-body">
                                            <div class="flot-container" style="height:250px">
                                                <div id="cpuChart" class="demo-placeholder"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>  
                            </div>
                            
                            <div class="col-lg-3">
                                <div class="panel panel-default card-view">
                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <h6 class="panel-title txt-dark">$memory_usage_helptext</h6>
                                        </div>
                                        <div class="pull-right">
                                            <a href="#" class="pull-left inline-block full-screen mr-15">
                                                <span data-toggle="tooltip" data-placement="bottom" title="$maximize_icon_helptext" data-original-title="$maximize_icon_helptext" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                            </a>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="panel-wrapper collapse in">
                                        <div class="panel-body">
                                            <div class="flot-container" style="height:250px">
                                                <div id="memoryChart" class="demo-placeholder"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>  
                            </div>

                            <div class="col-lg-3">
                                <div class="panel panel-default card-view">
                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <h6 class="panel-title txt-dark">$network_usage_helptext</h6>
                                        </div>
                                        <div class="pull-right">
                                            <a href="#" class="pull-left inline-block full-screen mr-15">
                                                <span data-toggle="tooltip" data-placement="bottom" title="$maximize_icon_helptext" data-original-title="$maximize_icon_helptext" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                            </a>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="panel-wrapper collapse in">
                                        <div class="panel-body">
                                            <div class="flot-container" style="height:250px">
                                                <div id="trafficChart" class="demo-placeholder"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>  
                            </div>

                            <div class="col-lg-3">
                                <div class="panel panel-default card-view">
                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <h6 class="panel-title txt-dark">$disk_usage_helptext</h6>
                                        </div>
                                        <div class="pull-right">
                                            <a href="#" class="pull-left inline-block full-screen mr-15">
                                                <span data-toggle="tooltip" data-placement="bottom" title="$maximize_icon_helptext" data-original-title="$maximize_icon_helptext" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                            </a>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="panel-wrapper collapse in">
                                        <div class="panel-body">
                                            <div class="flot-container" style="height:250px">
                                                <canvas id="diskChart" height="200"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>  
                            </div>
                            <!--- /INSERT -->

        HTML;

        $extraFooters =<<<HTML

            <style>
                .flot-tooltip {
                    color: #fff !important;
                    background-color: #000 !important;
                    border: 1px solid #fff !important;
                    font-size: 10px !important;
                    padding: 8px !important; /* Adjust the padding as needed */
                }
            </style>

            <script>
                window.metricsData = {};
                window.chartUpdateCallbacks = [];

                function pollMetricsData() {
                    $.ajax({
                        url: "/gui/metrics",
                        method: "GET",
                        dataType: "json",
                        success: function (data) {
                            window.metricsData = data;

                            // Call all registered chart update callbacks
                            window.chartUpdateCallbacks.forEach(fn => {
                                try {
                                    fn(data);
                                } catch (e) {
                                    console.error("Chart update failed:", e);
                                }
                            });
                        },
                        error: function (err) {
                            console.error("Error polling /gui/metrics:", err);
                        }
                    });
                }

                $(document).ready(function () {
                    pollMetricsData();              // Initial fetch
                    setInterval(pollMetricsData, 5000); // Poll every 5s
                });
            </script>

            <!-- Flot Charts JavaScript -->
            <script src="/.elm/vendors/bower_components/Flot/excanvas.min.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.categories.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.pie.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.resize.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.time.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.stack.js"></script>
            <script src="/.elm/vendors/bower_components/Flot/jquery.flot.crosshair.js"></script>
            <script src="/.elm/vendors/bower_components/flot.tooltip/js/jquery.flot.tooltip.min.js"></script>

            <!-- ChartJS JavaScript -->
            <script src="/.elm/vendors/chart.js/Chart.min.js"></script>
        HTML;

        // Retrieve metrics data from session data (was set by /gui/metrics):
        $shortload = session()->get('shortload');
        $shortmem  = session()->get('shortmem');
        $aggnet    = session()->get('aggnet');

        $BxPage->setExtraFooters($extraFooters);

        $plotName = 'cpuChart';
        $metrics = 'shortload';
        $load_average_1 = $i18n->get("[[palette.load_average_1]]");
        $load_average_5 = $i18n->get("[[palette.load_average_5]]");
        $load_average_15 = $i18n->get("[[palette.load_average_15]]");
        $labels = '{
            "node_load1": "' . $load_average_1 . '",
            "node_load5": "' . $load_average_5 . '",
            "node_load15": "' . $load_average_15 . '"
        }';
        $height = '250px';
        $theme = 'light';
        $spacing = '4';
        displayFlotChart($BxPage, $plotName, $metrics, $labels, $height, $theme, $spacing);

        $plotName = 'memoryChart';
        $metrics = 'shortmem';
        $TotalMemory = $i18n->get("[[palette.TotalMemory]]");
        $AvailableMemory = $i18n->get("[[palette.AvailableMemory]]");
        $FreeMemory = $i18n->get("[[palette.FreeMemory]]");
        $TotalSwap = $i18n->get("[[palette.TotalSwap]]");
        $FreeSwap = $i18n->get("[[palette.FreeSwap]]");
        $labels = '{
            "node_memory_MemTotal_bytes": "' . $TotalMemory . '",
            "node_memory_MemAvailable_bytes": "' . $AvailableMemory . '",
            "node_memory_MemFree_bytes": "' . $FreeMemory . '",
            "node_memory_SwapTotal_bytes": "' . $TotalSwap . '",
            "node_memory_SwapFree_bytes": "' . $FreeSwap . '",
        }';
        $height = '250px';
        $theme = 'light';
        $spacing = '6.5';
        displayFlotChart($BxPage, $plotName, $metrics, $labels, $height, $theme, $spacing);

        $plotName = 'trafficChart';
        $metrics = 'aggnet';
        $receive_bytes_total_eth0 = $i18n->get("[[palette.receive_bytes_total_eth0]]");
        $transmit_bytes_total_eth0 = $i18n->get("[[palette.transmit_bytes_total_eth0]]");

        $primary_interface = get_primary_interface();
        if ($primary_interface != 'eth0') {
            $rx_locale = $i18n->get("[[palette.receive_bytes_total]]") . " " . $primary_interface;
            $tx_locale = $i18n->get("[[palette.transmit_bytes_total]]") . " " . $primary_interface;
            $labels = '{
                "receive_bytes_total_' . $primary_interface . '": "' . $rx_locale . '",
                "transmit_bytes_total_' . $primary_interface . '": "' . $tx_locale . '"
            }';

        }
        else {
            $labels = '{
                "receive_bytes_total_eth0": "' . $receive_bytes_total_eth0 . '",
                "transmit_bytes_total_eth0": "' . $transmit_bytes_total_eth0 . '"
            }';
        }

        $height = '250px';
        $theme = 'light';
        $spacing = '2.5';
        renderSingleFlotChartDeltas($BxPage, $plotName, $metrics, $labels, $height, $theme, $spacing);

        $plotName = 'diskChart';
        $height = '250px';
        $warnPercentage = '0.85';
        $metrics = '';
        displayDiskChart($BxPage, $cceClient, $plotName, $metrics, $warnPercentage, $height);

        return $StartPageStats;
}

function addIframe ($url, $height, $BxPage) {
    // Generate an iframe from the passed on URL:
    //
    // $url:        URL of the iframe content.
    // $height:     Height. If "auto", it will be auto-calculated. 
    // $BxPage:     parents BXPage object

    if ($height == "auto") {
        $height = '';
    }
    else {
        $height = 'height="' . $height . '" ';
    }

    $CI =& get_instance();
    $BX_SESSION = $CI->getBX_SESSION();

    if (is_HTTPS() == TRUE) {
        $url = 'https://' . $_SERVER['SERVER_NAME'] . ':' . $BX_SESSION['GUI_PORT'] . $url;
    }
    else {
        $url = 'http://' . $_SERVER['SERVER_NAME'] . ':444' . $url; 
    }

    $out = '
        <iframe src="' . $url . '" class="column" scrolling="no" frameborder="0" width="100%" ' . $height . '></iframe>
        <script type="text/javascript">
            (function($) {
                var $iframes = $("iframe.column");
                if ($.isFunction($.fn.iframeAutoHeight)) {
                    $iframes.iframeAutoHeight({debug: true, diagnostics: false});
                    return;
                }
                $iframes.each(function() {
                    var iframe = this;
                    var resizeIframe = function() {
                        try {
                            var doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
                            if (!doc) {
                                return;
                            }
                            var body = doc.body;
                            var html = doc.documentElement;
                            var newHeight = Math.max(
                                body ? body.scrollHeight : 0,
                                body ? body.offsetHeight : 0,
                                html ? html.scrollHeight : 0,
                                html ? html.offsetHeight : 0,
                                html ? html.clientHeight : 0
                            );
                            if (newHeight > 0) {
                                iframe.style.height = newHeight + "px";
                            }
                        }
                        catch (ignore) {}
                    };
                    $(iframe).on("load", resizeIframe);
                    resizeIframe();
                    setTimeout(resizeIframe, 250);
                    setTimeout(resizeIframe, 1000);
                });
            })(jQuery);
        </script>
    ';
    return $out;
}

function getBar ($palette, $name, $percentage, $bartext, $i18n) {
    // Generates a progressbar with a hovering helptext and a bartext below:
    // $palette:    which module related i18n locales we check against ('base-user', 'base-vsite').
    // $name:       name of the item
    // $percentage: percentage of the progress bars progress
    // $bartext:    text below progress bar
    // $i18n:       parents $i18n object

    $combined = $palette . "." . $name;
    $text = $i18n->getHtml("[[$combined]]");
    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");
    $percentage_helptext = $bartext;

    $out = '
                                                        <fieldset class="label_side">
                                                                <label title="' . $helptext . '" class="tooltip hover">' . $text . '</label>
                                                                <div>
                                                                        <div title="' . $percentage_helptext . '" id="progressbar" class="progressbar tooltip hover"></div>
                                                                            <p align="center">' . $percentage_helptext . '</p>
                                                                            <script>
                                                                                $( "#progressbar" ).progressbar({
                                                                                    value: ' . $percentage . '
                                                                                });
                                                                            </script>

                                                                </div>
                                                        </fieldset>';
    return $out;
}

function Label ($palette, $name, $i18n) {
    // Generates a Label with a hovering helptext:
    // $palette:    which module related i18n locales we check against ('base-user', 'base-vsite').
    // $name:       name of the item
    // $i18n:       parents $i18n object

    $text = $i18n->getHtml("[[$palette.$name]]");

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");

    $out =<<<HTML
        <label class="control-label" data-toggle="tooltip" data-placement="right" title="" data-original-title="$helptext" data-container="body">$text</label>
    HTML;

    return $out;

}

function addToggleAbleAutoGrowField ($name, $type="", $required = "required", $name_opt1 = "", $name_opt2 ="", $val_opt1 = "", $val_opt2 = "", $textarea_name="", $textarea_help="", $textarea_span="", $textarea_value="", $palette = "palette", $i18n="", $cceClient="") {
    // name:            main heading of the input field
    // type:            validation for the textarea. Like "email", "text", "fqdn" or similar.
    // required:        hass the textarea required input if enabled?
    // name_opt1:       name of the first checbox option
    // val_opt1:        value of the first checkbox option
    // name_opt2:       name of the second checbox option
    // val_opt2:        value of the second checkbox option 
    // checked_opt1:    Is that checkbox ticked?
    // checked_opt2:    Is that checkbox ticked?
    // textarea_name:   name of the textarea
    // textarea_help:   tooltip of the texarea heading
    // textarea_span:   Optional <span></span> text for the textarea heading.
    // textarea_value:  pre-filled text for textarea
    // palette:         which module related i18n locales we check against ('base-user', 'base-vsite'). Defaults to 'palette'.
    // $i18n:           parents $i18n object

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");
    $ht = $palette . "." . $textarea_help;
    $area_helptext = $i18n->getWrapped("[[$ht]]");
    if ($textarea_span) {
        $my_textarea_span = $i18n->get("[[$palette.$textarea_span]]");
    }
    else {
        $my_textarea_span = "";
    }

    if ($required == "required") {
        $optional_text = '';
        $optional_class = 'required ';
        $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($type, $i18n) . '"></div>';
    }
    else {
        //$optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
        $optional_text = "";
        $optional_class = ' ';
        $optional_line = '';
    }

    $htp1 = $palette . "." . $name . '_help';
    $htp2 = $palette . "." . $name_opt2 . '_help';

    $my_name_opt1 = $i18n->get("[[$palette.$name_opt1]]");
    $my_name_opt2 = $i18n->get("[[$palette.$name_opt2]]");

    $help_opt1 = $i18n->getWrapped("[[$palette.$htp1]]");
    $help_opt2 = $i18n->getWrapped("[[$palette.$htp2]]");

    if ($val_opt1 == "1") {
        $val_opt1 = " checked ";
    }
    else {
        $val_opt1 = "";
    }
    if ($val_opt2 == "1") {
        $val_opt2 = " checked ";
    }
    else {
        $val_opt2 = "";
    }


    $out = '
        <div class="columns">
                <fieldset class="label_side col_25 no_lines">
                    <div class="section">
                        <label for="' . $name . '" title="' . $helptext . '" class="tooltip hover">' . $i18n->get("[[$palette.$name]]") . '<span>' . $optional_text . '</span></label>
                    </div>
                </fieldset>
            <div class="col_25">
                <div class="section">
                    <fieldset class="label_top bottom no_lines">
                            <div class="uniform inline clearfix">
                                    <INPUT TYPE="HIDDEN" NAME="checkbox-' . $name_opt1 . '" VALUE="' . $val_opt1 . '">
                                    <label for="' . $name_opt1 . '" title="' . $help_opt1 . '" class="tooltip hover"><input type="checkbox" class="mcb-' . $name_opt1 . '" name="' . $name_opt1 . '" id="' . $name_opt1 . '"' . $val_opt1 . '/>'. $my_name_opt1 .'</label>
                                    <INPUT TYPE="HIDDEN" NAME="checkbox-' . $name_opt2 . '" VALUE="' . $val_opt2 . '">
                                    <label for="' . $name_opt2 . '" title="' . $help_opt2 . '" class="tooltip hover"><input type="checkbox" class="mcb-' . $name_opt2 . '" name="' . $name_opt2 . '" id="' . $name_opt2 . '"' . $val_opt2 . '/>'. $my_name_opt2 .'</label>
                            </div>
                    </fieldset>
                </div>
            </div>
            <div class="col_50">
                <div class="section">
                        <INPUT TYPE="HIDDEN" NAME="textarea-'. $textarea_name . '" VALUE="' . $textarea_value . '">
                        <fieldset class="label_top no_lines lesspadding">
                            <label for="' . $textarea_name . '" title="' . $area_helptext . '" class="tooltip">' . $i18n->get("[[$palette.$textarea_name]]") . '<span>' . $my_textarea_span . '</span></label>
                                <div class="clearfix' . $optional_class . '">
                                        <textarea name="'. $textarea_name . '" title="' . $i18n->get("[[palette.autogrow_expanding]]") . '" class="tooltip autogrow ' . $type . '" placeholder="' . $i18n->get("[[palette.autogrow_prefill]]") . '">' . $cceClient->scalar_to_string($textarea_value) . '</textarea>
                                        ' . $optional_line . '
                                </div>
                            </span>
                        </fieldset>
                </div>
            </div>                                          
        </div>';

  return $out;
}                                       

function addFreeButton ($label, $tooltip, $class = "no_margin_bottom div_icon has_text", $icon = "ui-icon ui-icon-check", $palette = "palette", $i18n="") {
    // label:   label of the button
    // tooltip: button has tooltip
    // class:   defines the appearance of the button
    // icon:    defines if the button has an icon and if so, which.
    // palette: which module related i18n locales we check against ('base-user', 'base-vsite'). Defaults to 'palette'.
    // $i18n:   parents $i18n object

    $helptext = $palette . "." . $label . "_help";

    $out = "";
    if ($tooltip == "tooltip") {
        $out .= '                               <label title="' . $i18n->getWrapped("[[$helptext]]") . '" class="tooltip right">';
    }
    $out .= '
                                    <button class="' . $class . '" type="submit" formmethod="post">
                                        <div class="' . $icon . '"></div>
                                        <span>' . $i18n->get("[[$palette.$label]]") . '</span>
                                    </button>';

    if ($tooltip == "tooltip") {
        $out .= '                               </label>';
    }

  return $out;
}

function addSaveButton ($i18n) {
    // $i18n:   parents $i18n object
    $out = '
                                <label title="' . $i18n->getWrapped("[[palette.save_help]]") . '" class="tooltip right">
                                    <button class="no_margin_bottom div_icon has_text" type="submit" formmethod="post">
                                        <div class="ui-icon ui-icon-check"></div>
                                        <span>' . $i18n->get("[[palette.save]]") . '</span>
                                    </button>
                                </label>';
  return $out;
}

function addCancelButton ($i18n, $URL) {
    // $i18n:   parents $i18n object
    // $URL:    URL that the button points to

    if ($URL != "") {
        $data_link = ' data-link="' . $URL . '"';
        $linkable = ' link_button';
    }
    else {
        $data_link = '';
        $linkable = '';
    }

    $out = '
                                        <button title="' . $i18n->getWrapped("[[palette.cancel_help]]") . '" class="light send_right close_dialog tooltip right' . $linkable . '"' . $data_link . '>
                                            <div class="ui-icon ui-icon-closethick"></div>
                                                <span>' . $i18n->get("[[palette.cancel]]") . '</span>
                                        </button>';                                     
  return $out;
}

function addOldInputForm ($form_header, $grabber = "nograbber", $toggle = "notoggle", $form_body="", $buttons = "", $i18n="", $errors = "") {

    // form_header:     heading of the form
    // grabber:         defines if the form can be grabbed and moved.
    // toggle:          defines if the form can be toggled.
    // form_body:       HTML of the form body, or functions that define the output inside the form.
    // save_button:     defines if the form has a save button
    // cancel_button:   defines if the form has a cancel button
    // $i18n:           parents $i18n object
    // $errors:         form validation errors

    $CI =& get_instance();
    $csrf = array(
            'name' => $CI->security->get_csrf_token_name(),
            'hash' => $CI->security->get_csrf_hash()
    );

    $out = '
                    <div class="box grid_16">
                        <h2 class="box_head">' . $form_header . '</h2>
                        <div class="controls">';
    if ($grabber == "grabber") {
        $out .= '                       <a href="#" class="grabber tooltip hover" title="' . $i18n->getWrapped("[[palette.icon_grabber]]") .'"></a>';
    }
    if ($toggle == "toggle") {
        $out .= '                       <a href="#" class="toggle tooltip hover" title="' . $i18n->getWrapped("[[palette.icon_toggle]]") .'"></a>';
    }
    $out .= '
                        </div>
                        <div class="toggle_container">
                            <div class="block">';

    if (is_array($errors)) {
        if (count($errors) > 0) { 
            foreach ($errors as $key => $value) {
                $out .= $value; 
            }           
        }
    }

    $out .= '                       <form class="validate_form" method="post">'
                                    . $form_body . '
                                    <div class="button_bar clearfix">';
    if ($buttons) {
        $out .= $buttons;
    }

    $out .= '                                           

                                    </div>
                                    <input type="hidden" name="' . $csrf["name"] . '" value="' . $csrf["hash"] . '" />
                                </form>
                            </div>
                        </div>
                    </div>
                </form>';

  return $out;

}

function addInputForm($form_header, $elements = array("grabber" => "#", "toggle" => "#", "window" => "#"), $form_body="", $buttons = "", $i18n="", $BxPage="", $errors="", $post_url="") {

    return addInputFormElmer($form_header, $elements, $form_body, $buttons, $i18n, $BxPage, $errors, $post_url);
}

function addInputFormAdminica($form_header, $elements = array("grabber" => "#", "toggle" => "#", "window" => "#"), $form_body="", $buttons = "", $i18n="", $BxPage="", $errors="", $post_url="") {

    // form_header:     heading of the form
    // elements:        Array that defines which header elements (buttons) the form has (grabber, toggle, window)
    // form_body:       HTML of the form body, or functions that define the output inside the form.
    // save_button:     defines if the form has a save button
    // cancel_button:   defines if the form has a cancel button
    // $i18n:           parents $i18n object
    // $errors:         form validation errors

    $CI =& get_instance();
    $BX_SESSION = $CI->getBX_SESSION();

    $csrf = array(
            'name' => $BX_SESSION['csrf_token_name'],
            'hash' => $BX_SESSION['csrf_cookie_name']
    );

    $csrf_cookie_name = '';
    if (isset($_COOKIE['BlueOnyx_CSRF_cookie'])) {
      $csrf_cookie_name = $_COOKIE['BlueOnyx_CSRF_cookie'];
    }

    $post_url_code = '';
    if (!(empty($post_url))) {
        $post_url_code = 'action="' . $post_url . '" ';
    }

    $out = '
                    <div class="box grid_16">
                        <h2 class="box_head">' . $form_header . '</h2>
                        <div class="controls">';

    if (isset($elements['grabber'])) {
        $out .= '                       <a href="#" class="grabber tooltip hover" title="' . $i18n->getWrapped("[[palette.icon_grabber]]") .'"></a>';
    }
    if (isset($elements['toggle'])) {
        $out .= '                       <a href="#" class="toggle tooltip hover" title="' . $i18n->getWrapped("[[palette.icon_toggle]]") .'"></a>';
    }
    if (isset($elements['window'])) {

        $BxPage->setExtraHeaders('<script>');
        $BxPage->setExtraHeaders('function open_win()');
        $BxPage->setExtraHeaders('{');
        $BxPage->setExtraHeaders('window.open("' . $elements['window'] . '","_blank","toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800");');
        $BxPage->setExtraHeaders('}');
        $BxPage->setExtraHeaders('</script>');

        $out .= '                       <a href="#" class="show_window tooltip hover" onclick="open_win()" title="' . $i18n->getWrapped("[[palette.icon_window]]") .'"></a>';

    }   
    $out .= '
                        </div>
                        <div class="toggle_container">
                            <div class="block">';

    if (is_array($errors)) {
        if (count($errors) > 0) { 
            foreach ($errors as $key => $value) {
                $out .= $value; 
            }           
        }
    }

    $out .= '                       <form class="validate_form" method="post" ' . $post_url_code. '>'
                                    . $form_body . '
                                    <div class="button_bar clearfix">';
    if ($buttons) {
        $out .= $buttons;
    }

    $out .= '                                           

                                    </div>
                                    <input type="hidden" name="' . $csrf["name"] . '" value="' . $csrf_cookie_name . '" />
                                </form>
                            </div>
                        </div>
                    </div>
                </form>';
  return $out;
}

function addInputFormElmer($form_header, $elements = array("grabber" => "#", "toggle" => "#", "window" => "#"), $form_body="", $buttons = "", $i18n="", $BxPage="", $errors="", $post_url="") {

    // form_header:     heading of the form
    // elements:        Array that defines which header elements (buttons) the form has (grabber, toggle, window)
    // form_body:       HTML of the form body, or functions that define the output inside the form.
    // save_button:     defines if the form has a save button
    // cancel_button:   defines if the form has a cancel button
    // $i18n:           parents $i18n object
    // $errors:         form validation errors

    $CI =& get_instance();
    $BX_SESSION = $CI->getBX_SESSION();

    $csrf = array(
            'name' => $BX_SESSION['csrf_token_name'],
            'hash' => $BX_SESSION['csrf_cookie_name']
    );

    $csrf_cookie_name = '';
    if (isset($_COOKIE['BlueOnyx_CSRF_cookie'])) {
      $csrf_cookie_name = $_COOKIE['BlueOnyx_CSRF_cookie'];
    }

    $csrf_name = $BX_SESSION['csrf_token_name'];

    $post_url_code = '';
    if (!(empty($post_url))) {
        $post_url_code = 'action="' . $post_url . '" ';
    }

    $minimize_toggle = '';
    $external_link = '';

    if (isset($elements['toggle'])) {
        $toggle_text = $i18n->getWrapped("[[palette.icon_toggle]]");
        $minimize_toggle =<<<HTML
        <!-- Minimize Panel Body -->
                                                    <a class="pull-left inline-block mr-15" data-toggle="collapse" href="#collapse_1" aria-expanded="true">
                                                        <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$toggle_text" data-container="body"><i class="zmdi zmdi-chevron-down"></i></span>
                                                        <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$toggle_text" data-container="body"><i class="zmdi zmdi-chevron-up"></i></span>
                                                    </a>
                                                    <!-- /Minimize Panel Body -->
        HTML;
    }

    if (isset($elements['window'])) {

        $BxPage->setExtraHeaders('<script>');
        $BxPage->setExtraHeaders('function open_win()');
        $BxPage->setExtraHeaders('{');
        $BxPage->setExtraHeaders('window.open("' . $elements['window'] . '","_blank","toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800");');
        $BxPage->setExtraHeaders('}');
        $BxPage->setExtraHeaders('</script>');

        $open_in_new_window = $i18n->getWrapped("[[palette.icon_window]]");

        $external_link =<<<HTML
        <!-- Open in new window -->
                                                    <a href="#" class="pull-left inline-block mr-15 show_window" onclick="open_win()">
                                                        <span data-toggle="tooltip" data-placement="bottom" title="" data-original-title="$open_in_new_window" data-container="body"><i class="fa fa-external-link"></i></span>
                                                    </a>
                                                    <!-- /Open in new window -->
        HTML;
    }

    $maximize_text = $i18n->getWrapped("[[palette.icon_maximize]]");

    $errors_out = '';
    if (is_array($errors)) {
        if (count($errors) > 0) { 
            foreach ($errors as $key => $value) {
                $errors_out .= $value; 
            }           
        }
    }

    $out =<<<HTML
                    <!-- addInputFormElmer() -->
                    <input id="SelectedTab" type="hidden" name="SelectedTab" value="basicSettingsTab" />
                    <div class="col-md-12">
                        <div class="panel panel-default  card-view">
                            <div class="panel-heading">
                                <div class="pull-left">
                                    <h6 class="panel-title txt-dark">$form_header</h6>
                                </div>
                                <div class="pull-right">
                                    <div class="tab-struct custom-tab-1">
                                        <ul role="tablist" class="nav nav-tabs" id="snmpSettings">
                                            <!-- Header Buttons Right -->
                                            <li>
                                                $external_link
                                                <!-- Maximize Element -->
                                                <a href="#" class="pull-left inline-block full-screen mr-15">
                                                    <span data-toggle="tooltip" data-placement="bottom" title="" data-original-title="$maximize_text" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                                </a>
                                                <!-- /Maximize Element -->
                                                    
                                                $minimize_toggle
                                            </li>
                                            <!-- /Header Buttons Right -->
                                        </ul>
                                    </div>  
                                </div>
                                <div class="clearfix"></div>
                            </div>
                            <div id="collapse_1" class="panel-wrapper collapse in"><!-- panel-wrapper -->
                                <div class="panel-body"><!-- panel-body -->
                                    <form class="validate_form" method="post" $post_url_code>
                                        $errors_out
                                        <input type="hidden" name="$csrf_name" value="$csrf_cookie_name" />
                                        $form_body
                                    </form>
                                </div><!-- /panel-body -->
                            </div><!-- /panel-wrapper -->
                        </div>
                    </div>
                    <!-- /addInputFormElmer() -->

    HTML;
    return $out;
}

function addTextField($name, $type = "", $value = "", $palette = "palette", $required = "required", $read_write="", $i18n="") {

    // name:        name of the input field
    // type:        type of check that we validate against ('fqdn', 'email' or others). If empty, no validation.
    // value:       value to populate the input with.
    // palette:     which module related i18n locales we check against ('base-user', 'base-vsite'). Defaults to 'palette'.
    // required:    is this a required field? Usually 'required' or anything else for not required.
    // $read_write: Defines if this field is editable.  "hidden" = invisble form field. "rw" = editable form field, "r" = visible text + invisble form field.
    // $i18n:       parents $i18n object

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");

    if ($required == "required") {
        $optional_text = '';
        $optional_class = 'required ';
        $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($type, $i18n) . '"></div>';
    }
    else {
        $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
        $optional_class = ' ';
        $optional_line = '';
    }
    if ($read_write == "hidden") {
        $input_type = "hidden";
        $show_only = '';
        // Need to reset any existing 'required' stuff:
        $optional_text = '';
        $optional_class = '';
        $optional_line = '';

    }
    elseif ($read_write == "rw") {
        if ($type == "password") {
            $input_type = "password";
        }
        else {
            $input_type = "text";   
        }
        $show_only = '';
    }
    else {
        // Covers 'r' and anything else:
        $input_type = "hidden";
        $show_only = '<p>' . $value . '</p>';
        // Need to reset any existing 'required' stuff:
        $optional_text = '';
        $optional_class = '';
        $optional_line = '';
    }

    $out = '';
    if ($read_write != "hidden") {
        $out .= '
                                    <fieldset class="label_side top">
                                            <label for="' . $name . '" title="' . $helptext . '" class="tooltip right">' . $i18n->get("[[$palette.$name]]") . '<span>' . $optional_text . '</span></label>
                                            <div>';
    }
    $out .= '
                                                <input type="' . $input_type . '" name="' . $name . '" VALUE="' . $value . '" id="' . $name . '" class="' . $optional_class . $type . ' error">
                                                ' . $show_only . $optional_line;
    if ($read_write != "hidden") {
        $out .= '
                                            </div>
                                    </fieldset>';
    }

  return $out;
}

function addTopTextField($name, $type = "", $value = "", $palette = "palette", $required = "required", $read_write="", $i18n="") {

    // name:        name of the input field
    // type:        type of check that we validate against ('fqdn', 'email' or others). If empty, no validation.
    // value:       value to populate the input with.
    // palette:     which module related i18n locales we check against ('base-user', 'base-vsite'). Defaults to 'palette'.
    // required:    is this a required field? Usually 'required' or anything else for not required.
    // $read_write: Defines if this field is editable.  "hidden" = invisble form field. "rw" = editable form field, "r" = visible text + invisble form field.
    // $i18n:       parents $i18n object

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");

    if ($required == "required") {
        $optional_text = '';
        $optional_class = 'required ';
        $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($type, $i18n) . '"></div>';
    }
    else {
        $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
        $optional_class = ' ';
        $optional_line = '';
    }
    if ($read_write == "hidden") {
        $input_type = "hidden";
        $show_only = '';
        // Need to reset any existing 'required' stuff:
        $optional_text = '';
        $optional_class = '';
        $optional_line = '';

    }
    elseif ($read_write == "rw") {
        $input_type = "text";
        $show_only = '';
    }
    else {
        // Covers 'r' and anything else:
        $input_type = "hidden";
        $show_only = '<p>' . $value . '</p>';
        // Need to reset any existing 'required' stuff:
        $optional_text = '';
        $optional_class = '';
        $optional_line = '';
    }

    $out = '';
    if ($read_write != "hidden") {
        $out .= '
                                    <fieldset class="label_top top">
                                            <label for="' . $name . '" title="' . $helptext . '" class="tooltip right">' . $i18n->get("[[$palette.$name]]") . '<span>' . $optional_text . '</span></label>
                                            <div>';
    }
    $out .= '
                                                <input type="' . $input_type . '" name="' . $name . '" VALUE="' . $value . '" id="' . $name . '" class="' . $optional_class . $type . ' error">
                                                ' . $show_only . $optional_line;
    if ($read_write != "hidden") {
        $out .= '
                                            </div>
                                    </fieldset>';
    }

  return $out;
}

function addPasswordField($name, $palette = "palette", $required = "required", $i18n="", $BxPage="") {

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");

    if ($required == "required") {
        $optional_text = "";
        $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($type, $i18n) . '"></div>';
    }
    else {
        $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
        $optional_line = '';
    }   

    $BxPage->setExtraHeaders('
          <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>
          <script language="Javascript">
            <!--
              checkpassOBJ = function() {
                this.onFailure = function() {
                  alert("Unable to validate password");
                }
                this.OnSuccess = function() {
                  var response = this.GetResponseText();
                  document.getElementById("results").innerHTML = response;
                }
              }


              function validate_password ( word ) {
                checkpassOBJ.prototype = new ajax_lib();
                checkpass = new checkpassOBJ();
                var URL = "/gui/check_password";
                var PARAM = "password=" + word;
                checkpass.post(URL, PARAM);
              }

            //-->
          </script>
        ');
    
    $out = '
                                <fieldset class="label_side top">
                                    <label for="' . $name . '" title="' . $helptext . '" class="tooltip right">' . $i18n->get("[[$palette.$name]]") . '<span>' . $optional_text . '</span></label>
                                    <div>
                                        <INPUT id="pass" TYPE="PASSWORD" NAME="' . $name . '" VALUE="" SIZE="20" onKeyUp="validate_password(this.value)" >
                                        <div id="results">'. $i18n->get("pwCheckStr", "palette") . '</div>
                                        <INPUT id="pass" TYPE="PASSWORD" NAME="_' . $name . '_repeat" VALUE="" SIZE="20" onKeyUp="validate_password(this.value)" >' . $i18n->get("repeat", "palette") . '
                                    </div>
                                </fieldset>';
  return $out;
}

function addPullDown($name, $options = array(), $set_val = "", $palette = "palette", $i18n="") {

    // name:        name of the dropdown
    // options:     array of select options
    // set_val:     if specified, the desired option will be pre-selected by default.
    // required:    is this a required field? '0' = No, '1' = Yes
    // $i18n:       parents $i18n object

    $h = $palette . "." . $name . '_help';
    $helptext = $i18n->getWrapped("[[$h]]");
    $key = array_search($set_val, $options);

    $out = '
                                <fieldset class="label_side top">
                                        <label for="' . $name . '" title="' . $helptext . '" class="tooltip left">' . $i18n->getHtml("[[$palette.$name]]") . '</label>
                                        <div class="clearfix">' .
                                                form_dropdown($name, $options, $key) . '
                                        </div>
                                </fieldset>';
  return $out;
}

/**
 * get_i18n_error_for_inputvalidation($checktype)
 *
 * Checks if a validation check is handled by jQuery's built in checks, or if we
 * use a native check of BlueOnyx for the validation.
 *
 * Returns the required i18n code to display the error message when the check fails.
 *
 * @param VAR   $checktype      : Short name of the check to be performed
 * @return VAR  i18n code to display the error message
 */

function get_i18n_error_for_inputvalidation($checktype, $i18n) {

    // Get Cookie-Locale to determine the currently used language:
    $CI =& get_instance();
    $BX_SESSION = $CI->getBX_SESSION();
    $cookie_locale = $BX_SESSION['locale'];

    $i18n = new I18n("palette", $cookie_locale);

    $internal_checks = array(
                    'required' => $i18n->getHtml("[[palette.val_required]]"),
                    'remote' => $i18n->getHtml("[[palette.val_remote]]"),
                    'email' => $i18n->getHtml("[[palette.val_email]]"),
                    'url' => $i18n->getHtml("[[palette.val_url]]"),
                    'date' => $i18n->getHtml("[[palette.val_date]]"),
                    'dateISO' => $i18n->getHtml("[[palette.val_dateISO]]"),
                    'number' => $i18n->getHtml("[[palette.val_number]]"),
                    'digits' => $i18n->getHtml("[[palette.val_digits]]"),
                    'creditcard' => $i18n->getHtml("[[palette.val_creditcard]]"),
                    'equalTo' => $i18n->getHtml("[[palette.val_equalTo]]"),
                    'accept' => $i18n->getHtml("[[palette.val_accept]]"),
                    'maxlength' => $i18n->getHtml("[[palette.val_maxlength]]"),
                    'minlength' => $i18n->getHtml("[[palette.val_minlength]]"),
                    'rangelength' => $i18n->getHtml("[[palette.val_rangelength]]"),
                    'range' => $i18n->getHtml("[[palette.val_range]]"),
                    'max' => $i18n->getHtml("[[palette.val_max]]"),
                    'min' => $i18n->getHtml("[[palette.val_min]]")
                );

    if ((in_array($checktype, $internal_checks)) && ($checktype != "")) {
        return $internal_checks[$checktype];
    }
    else {
        return  $i18n->get("[[palette.val_required]]");
    }

}

function showStyleSwitcher($i18n) {

/**
 * showStyleSwitcher($i18n)
 *
 * Shows the style switcher for the theme.
 *
 * Returns the required code to display the style switcher at the bottom of the page.
 *
 * @param VAR   $i18n       : parents $i18n object
 * @return VAR              : code to display the error message
 */

    $out = '
        <div id="template_options" class="clearfix">
            <div class="layout_size"><label>' . $i18n->get("[[base-product.productName]]") . ': ' . $i18n->get("[[base-user.styleField]]") . '</label></div>
            <div class="layout_size">
                <label>' . $i18n->get("[[palette.layout]]") . ':</label>
                <a href="/.adm/styles/themes/layout_switcher.php?style=switcher.css">' . $i18n->get("[[palette.fluid]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/layout_switcher.php?style=layout_fixed.css">' . $i18n->get("[[palette.fixed]]") . '</a>
            </div>
            <div class="layout_position">
                <label>' . $i18n->get("[[palette.menus]]") . ': </label>
                <a href="/.adm/styles/themes/nav_switcher.php?style=switcher.css">' . $i18n->get("[[palette.side]]") . '</a><span>|</span>
                <!-- <a href="/.adm/styles/themes/nav_switcher.php?style=nav_stacks.css">' . $i18n->get("[[palette.stacks]]") . '</a><span>|</span> -->
                <a href="/.adm/styles/themes/nav_switcher.php?style=nav_top.css">' . $i18n->get("[[palette.top]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/nav_switcher.php?style=nav_slideout.css">' . $i18n->get("[[palette.slide]]") . '</a>
            </div>
            <div class="layout_position">
                <label>' . $i18n->get("[[palette.theme]]") . ': </label>
                <a href="/.adm/styles/themes/skin_switcher.php?style=multiple&skin_switcher.php=switcher.css&bg_switcher.php=switcher.css">' . $i18n->get("[[palette.dark]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/skin_switcher.php?style=multiple&skin_switcher.php=skin_light.css&bg_switcher.php=switcher.css">' . $i18n->get("[[palette.light]]") . '</a>
            </div>
            <div class="theme_colour">
                <label class="display_none">Colour:</label>
                <a class="black" href="/.adm/styles/themes/theme_switcher.php?style=switcher.css"><span>Black</span></a>
                <a class="blue" href="/.adm/styles/themes/theme_switcher.php?style=theme_blue.css"><span>Blue</span></a>
                <a class="navy" href="/.adm/styles/themes/theme_switcher.php?style=theme_navy.css"><span>Navy</span></a>
                <a class="red" href="/.adm/styles/themes/theme_switcher.php?style=theme_red.css"><span>Red</span></a>
                <a class="green" href="/.adm/styles/themes/theme_switcher.php?style=theme_green.css"><span>Green</span></a>
                <a class="magenta" href="/.adm/styles/themes/theme_switcher.php?style=theme_magenta.css"><span>Magenta</span></a>
                <a class="orange" href="/.adm/styles/themes/theme_switcher.php?style=theme_brown.css"><span>Brown</span></a>
            </div>
            <div class="theme_background" id="bg_dark">
                <label>' . $i18n->get("[[palette.BGs]]") . ':</label>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_wunder.css">' . $i18n->get("[[palette.metal]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=switcher.css">' . $i18n->get("[[palette.boxes]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_punched.css">' . $i18n->get("[[palette.punched]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_honeycomb.css">' . $i18n->get("[[palette.honeycomb]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_wood.css">' . $i18n->get("[[palette.wood]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_dark_wood.css">' . $i18n->get("[[palette.timber]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_noise.css">' . $i18n->get("[[palette.noise]]") . '</a>
            </div>
            <div class="theme_background" id="bg_light">
                <label>' . $i18n->get("[[palette.BGs]]") . ':</label>
                <a href="/.adm/styles/themes/bg_switcher.php?style=switcher.css">' . $i18n->get("[[palette.silver]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_white_wood.css">' . $i18n->get("[[palette.wood]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_squares.css">' . $i18n->get("[[palette.squares]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_noise_zero.css">' . $i18n->get("[[palette.noise]]") . '</a><span>|</span>
                <a href="/.adm/styles/themes/bg_switcher.php?style=bg_stripes.css">' . $i18n->get("[[palette.stripes]]") . '</a>
            </div>
        </div>';
    return $out;
}

function minutes_round ($minutes = '03', $step = '15') {
    $rounded = round($minutes / ($step)) * ($step);
    return $rounded;
}

function simplify_number ($number, $literal, $cnt) {

    // Return our numbers nicely formatted.
    //
    // Arguments:
    //
    // $number:         The number we're formatting
    // $literal:        "K"  = one thousand = factor 1000
    //                  "KB" = one thousand = factor 1024
    // $cnt:            Number of digits after the dot
    //
    // Returns nicely formatted number including the factor.

    // Simple: If it's a '0' to begin with, we're done right here and now:
    if ($number == "0") {
        return $number;
    }

    if ($literal == "K") {
        $multi = "1000";
    }
    elseif ($literal == "KB") {
        $multi = "1024";
    }
    else {
        $multi = "1024";
    }    
    // Handle case where we don't have a number, but are set to 'unlimited':
    if ($number === "unlimited") {
        return "unlimited";
    }
    // Handle cases where '*_b' or '*_l' already have a unit assigned:
    $pattern = '/^(.*)(K|M|G|T)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        return $number;
    }
    if ((strlen($number)) > "16") {
        return "Unlimited";
    }
    $units = array('B', 'K', 'M', 'G', 'T');
    for ($i = 0; $number >= $multi && $i < count($units) - 1; $i++ ) {
        $number /= $multi;
    }
    $result = round(floatval($number), floatval($cnt)).''.$units[$i];
    return $result;
}

function unsimplify_number ($number, $literal, $cnt="") {

    // Return our numbers in machine readable format.
    //
    // Arguments:
    //
    // $number:         The number we're formatting
    // $literal:        "K"  = one thousand = factor 1000
    //                  "KB" = one thousand = factor 1024
    // $cnt:            Number of digits after the dot
    //
    // Returns numbers without factors or units in machine readable integers.

    if ($literal == "K") {
        $multi = "1000";
    }
    elseif ($literal == "KB") {
        $multi = "1024";
    }
    else {
        $multi = "1024";
    }    
    // Handle case where we don't have a number, but are set to 'unlimited':
    if ($number === "unlimited") {
        return "unlimited";
    }

    $number = preg_replace('/\,/', '.', $number);

    // Handle cases where '*_b' or '*_l' already have a unit assigned:
    $pattern = '/^(\d*[(\.)|(\,)]{0,1}\d+)(K|M|G|T)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        $split_numbers = preg_split("/(K|M|G|T)/", $number, 0, PREG_SPLIT_DELIM_CAPTURE);
        $number = $split_numbers[0];
        $format = $split_numbers[1];

        // Based on the unit multiply the number to get the integer back:
        if ($format == "M") {
            $mod = $multi;
            $number = $number*$mod;
        }
        if ($format == "G") {
            $mod = $multi*$multi;
            $number = $number*$mod;
        }
        if ($format == "T") {
            $mod = $multi*$multi*$multi;
            $number = $number*$mod;
        }
        if ($format == "P") {
            $mod = $multi*$multi*$multi*$multi;
            $number = $number*$mod;
        }

        // Return the recalculated integer without unit:
        return $number;
    }

    // Check for positive decimal number without unit
    $pattern = '/^(\d*\.{0,1}\d+)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        $integer = roundToNearest($number);
        // Return the recalculated and rounded integer:
        return $integer;
    }
}

function roundToNearest($number,$nearest=50) {
    $number = round($number);
    if ($nearest>$number || $nearest <= 0) {
        return $number;
    }
    else {
        $x = ($number%$nearest);
        return ($x<($nearest/2))?$number-$x:$number+($nearest-$x);
    }
}

function simplify_number_pages ($number, $literal, $cnt) {

    // NOTE: Slightly different from 'simplify_diskspace', as the parameters
    // 'physpages' and 'swappages' use a multiplicator of 4096, as OpenVZ
    // indeed handles them as pages. So when we get an integer, we need to
    // multiply it by 4096 to get the real amount of memory.

    // Return our numbers nicely formatted.
    //
    // Arguments:
    //
    // $number:         The number we're formatting
    // $literal:        "K"  = one thousand = factor 1000
    //                  "KB" = one thousand = factor 1024
    // $cnt:            Number of digits after the dot
    //
    // Returns nicely formatted number including the factor.

    if ($literal == "K") {
        $multi = "1000*1000";
    }
    elseif ($literal == "KB") {
        $multi = "1024";
    }
    else {
        $multi = "1024";
    }
    // Handle case where we don't have a number, but are set to 'unlimited':
    if ($number === "unlimited") {
        return "unlimited";
    }
    // Handle cases where '*_b' or '*_l' already have a unit assigned:
    $pattern = '/^(.*)(K|M|G|T)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        return $number;
    }

    // Check for positive decimal number without unit:
    $pattern = '/^(\d*\.{0,1}\d+)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        // We have an integer or number w/o unit. So this is in pages.
        // Multiply with the factor of the page size:
        $number = $number*4096;
        // Get the length of the string and set the unit accordingly:
        $len = strlen($number);
        if ($len <= "3") {
            //return sprintf("%.${cnt}f$format", "$number");
        }
        if (($len > "3") && ($len <= "6")) {
            $format = "K";
            $mod = $multi;
            $number = $number/$mod;
        }
        if (($len > "6") && ($len <= "9")) {
            $format = "M";
            $mod = $multi*$multi;
            $number = $number/$mod;
        }
        if (($len > "9") && ($len <= "12")) {
            $format = "G";
            $mod = $multi*$multi*$multi;
            $number = $number/$mod;
        }
        if ($len > "12") {
            $format = "E";
            $mod = $multi*$multi*$multi*$multi;
            $number = $number/$mod;
        }
        return sprintf("%.${cnt}f$format", "$number");
    }
}

function simplify_number_diskspace ($number, $literal, $cnt, $extra) {

    // NOTE: Slightly different from 'simplify_number', as the diskspace
    // needs to be multiplied with another factor of 1024 if it is an integer!

    // Return our numbers nicely formatted.
    //
    // Arguments:
    //
    // $number:         The number we're formatting
    // $literal:        "K"  = one thousand = factor 1000
    //                  "KB" = one thousand = factor 1024
    // $cnt:            Number of digits after the dot
    //
    // $extra:          Text to display at the end of the output.
    //
    // Returns nicely formatted number including the factor.

    if ($literal == "K") {
        $multi = "1000";
    }
    elseif ($literal == "KB") {
        $multi = "1024";
    }
    else {
        $multi = "1024";
    }
    // Handle case where we don't have a number, but are set to 'unlimited':
    if ($number === "unlimited") {
        return "unlimited";
    }
    // Handle cases where '*_b' or '*_l' already have a unit assigned:
    $pattern = '/^(.*)(K|M|G|T)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        return $number;
    }

    // Check for positive decimal number without unit:
    $pattern = '/^(\d*\.{0,1}\d+)$/';
    if (preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
        // Get the length of the string and set the unit accordingly:
        $len = strlen($number);
        if ($len <= "3") {
            $format = "";
            return sprintf("%.${cnt}f$format$extra", "$number");
        }
        if (($len > "3") && ($len <= "6")) {
            $format = "M";
            $mod = $multi;
            $number = $number/$mod;
        }
        if (($len > "6") && ($len <= "9")) {
            $format = "G";
            $mod = $multi*$multi;
            $number = $number/$mod;
        }
        if (($len > "9") && ($len <= "12")) {
            $format = "T";
            $mod = $multi*$multi*$multi;
            $number = $number/$mod;
        }
        if ($len > "12") {
            $format = "E";
            $mod = $multi*$multi*$multi*$multi;
            $number = $number/$mod;
        }
        return sprintf("%.${cnt}f$format$extra", "$number");
    }
}

function string_convert_adminica_error_to_elmer_error ($error) {

    if ($error instanceof CceError) {
        // CceError's are Objects, not strings. Manually construct error:
        $alert_message = 'CCE Error: ' . $error->code . ' - OID: ' . $error->oid;
        $out_error = ErrorMessage($alert_message);
        return $out_error;
    }
    else {

        // Create a DOMDocument instance
        $dom = new DOMDocument;
        $dom->loadHTML($error);

        // Check if there is an image tag
        $imgs = $dom->getElementsByTagName('img');
        if ($imgs->length > 0) {
            // Get the first div element
            $div = $dom->getElementsByTagName('div')->item(0);

            // Extract alert classes
            $classes = $div->getAttribute('class');
            $alert_classes = preg_replace('/\balert\b\s*/', '', $classes); // Remove the leading "alert"

            // Extract icon filename (without extension)
            $img = $div->getElementsByTagName('img')->item(0);
            $icon_path = $img->getAttribute('src');
            $icon_filename = pathinfo($icon_path, PATHINFO_FILENAME);

            // Extract alert message
            $strong = $div->getElementsByTagName('strong')->item(0);
            $alert_message = strip_tags($strong->nodeValue);

            // Output the results
            $out_error = ErrorMessage($alert_message, $alert_classes, $icon_filename, TRUE);

            return $out_error;
        }
        else {
            // No image tag found, must be new Elmer style error message. Continue.
            return $error;
        }
    }
}

function convert_adminica_error_to_elmer_error ($error) {
    if (is_array($error)) {
        $out_errors = [];
        foreach ($error as $key => $value) {
            $out_errors[] = string_convert_adminica_error_to_elmer_error($value);
        }
        return $out_errors;
    }
    else {
        return string_convert_adminica_error_to_elmer_error($error);
    }
}

// Safe deserialize: tries JSON first to prevent PHP Object Injection,
// falls back to PHP unserialize with allowed_classes=false for legacy data.
function safe_deserialize($data) {
    if (!is_string($data)) {
        return [];
    }
    $decoded = json_decode($data, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }
    // Legacy PHP serialized data — safe deserialize (no object injection allowed)
    $result = @unserialize($data, ['allowed_classes' => false]);
    if ($result === false && $data !== 'b:0;') {
        return [];
    }
    return is_array($result) ? $result : [];
}

// Generate ErrorMessages:
function ErrorMessage ($errMsg, $type="alert_red", $icon="alarm_bell", $dismissible=TRUE) {
    $diss_fill = '';
    $dismiss_elmer = '';
    $dismiss_html_elmer = '';
    if ($dismissible == TRUE) {
        $diss_fill = 'dismissible ';
        $dismiss_elmer = 'alert-dismissable';
        $dismiss_html_elmer = '<button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>';
    }

    $possible_alert_icons = array('alarm_bell.png', 'info_about.png', 'alert_2.png', 'alert.png', 'alarm_bell', 'info_about', 'alert_2', 'alert');

    $replacement_alert_icons = array(
        'alarm_bell.png' => 'fa fa-warning',
        'info_about.png' => 'fa fa-info-circle',
        'alert_2.png' => 'fa fa-warning',
        'alert.png' => 'fa fa-warning',
        'alarm_bell' => 'fa fa-warning',
        'info_about' => 'fa fa-info-circle',
        'alert_2' => 'fa fa-warning',
        'alert' => 'fa fa-warning',
    );

    $alert_type_replacements = array(
        'alert_green' => 'alert-success',
        'alert_white' => 'alert-success',
        'alert_navy' => 'alert-info',
        'alert_light' => 'alert-warning',
        'alert_red' => 'alert-danger',
    );

    $possible_alert_types = array_keys($alert_type_replacements);

    if (in_array($icon, $possible_alert_icons)) {
        $icon = $replacement_alert_icons[$icon];
    }

    if (in_array($type, $possible_alert_types)) {
        $type = $alert_type_replacements[$type];
    }

    $out = <<<HTML

                                            <div class="alert $type $dismiss_elmer">$dismiss_html_elmer
                                                <div class="row align-items-center">
                                                    <div class="col-sm-1" style="width: 35px;">
                                                        <i class="$icon pr-10"></i>
                                                    </div>
                                                    <div class="col-sm-10">
                                                        <p>$errMsg</p>
                                                    </div>
                                                    <div class="col-sm-1">
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

        HTML;
    return $out;
}

// Meaner used by /sitestats/summaryEmail:
function Meaner ($size, $count="", $mult="K", $nachkomma="2", $suffix="B") {
    // Prevent division by zero:
    if (($count == "0") || ($count == "")) {
        $count = "1";
    }
    if ($size == "0") {
        return "0K" . $suffix;
    }

    if (preg_match('/[A-Za-z]/', $size)) {
        return $size;
    }

    $out = simplify_number(roundToNearest(floatval($size) / floatval($count)), $mult, $nachkomma) . $suffix;
    return $out;
}

// simpler simplify_number used by /sitestats/summaryEmail:
function SimNum ($number, $mult="K", $nachkomma="2", $suffix="B") {
    $out = simplify_number($number, $mult, $nachkomma) . $suffix;
    return $out;
}

// If the passed value is empty, it will be set to a default:
function defaulter ($number="0", $default = "0") {
    if ($number === '') {
        $number = $default;
    }
    return $number;
}

function stringshortener ($string, $length='15') {
    $lngt = strlen($string);
    $lngt = $lngt+5;
    if ($lngt > $length) {
        $diff = ($lngt-$length)*('-1');
        $outstring = substr($string, 0, $diff);
        $string = $outstring . '(...)';
    }
    return $string;
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
Copyright (c) 2003 Sun Microsystems, Inc. 
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
