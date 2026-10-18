<?php
// Author: Michael Stauber
// $Id: PieChart.php
//
// This Class works similar than the BarGraph Class, but is used
// to create pie charts instead.
//
// This new Class uses Flot instead to generate the Graphs:
//
// Flot
// Version: 0.8.3
// Link: http://www.flotcharts.org/
// Description: Charting solution, see link for full feature list.
//

global $isPieChartDefined;
if ( $isPieChartDefined )
    return;
$isPieChartDefined = true;

include_once("uifc2/HtmlComponent.php");

class PieChart extends HtmlComponent {

    var $Ylabel;
    var $Xlabel;
    var $width;
    var $height;
    var $id;
    var $Label, $Description;
    var $numgraphs;
    var $graph;
    var $data_labels;
    var $xlabels;
    var $xaxisLabels;
    var $showSliceLabels;

    //
    // public methods
    //

    // constructor
    //function PieChart($BxPage, $id, $data) {
    public function __construct($BxPage, $id, $data) {
        $this->BxPage = $BxPage;
        $this->id = $id;
        $this->showSliceLabels = false;

        // Find out how many Graphs our $data contains:
        $this->numgraphs = count(array_keys($data));

        // We use the array keys as labels for our graphs.
        // So we extract the keys first:
        $this->data_labels = array_keys($data);

        // Start sane:
        $this->graph = "    var data = [\n";

        // Do a proper encoding of our Graph data:
        $endentry = count($this->data_labels);
        $entry = "0";
        foreach ($data as $key => $value) {
            // The fucking joys of PHP's localization implementation:
            // If we're in 'en_US', the dot is our delimiter. If we're
            // in 'de_DE' or others, the comma is the numeric delimiter.
            // jQuery expects dots as delimiter. So we give it dots:
            $value = preg_replace('/,/', '.', $value);

            $this->graph .= '                                       { label: ' . json_encode((string)$key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',  data: ' . $value . ' }';
            $entry++;
            if ($entry < $endentry) {
                $this->graph .= ", \n";
            }
        }

        // End sane:
        $this->graph .= "\n                                ];\n";
    }

    function getId() {
        return $this->id;
    }

    function getValue() {
        // This is a dummy return so we can live in a PageBlock():
        return FALSE;
    }

    function getAccess() {
        $access = "rw";
        return $access;
    }

    function isOptional() {
        return TRUE;
    }

    function setYLabel($label) {
        $this->Ylabel = $label;
    }

    function setXLabel($label) {
        $this->Xlabel = $label;
    }

    // On-slice percent labels. Default off: existing pies (email, etc.)
    // stay legend-only. Hover tooltips are always on.
    function setShowSliceLabels($show) {
        $this->showSliceLabels = ($show) ? true : false;
    }

    function setSize($width = "739", $height = "450") {
        $this->width = $width . "px";
        $this->height = $height . "px";
    }

    function getWidth() {
        if (!isset($this->width)) {
            $this->width = "739px";
        }
        return $this->width;
    }

    function getHeight() {
        if (!isset($this->height)) {
            $this->height = "450px";
        }
        return $this->height;
    }

    // Sets the current label
    function setCurrentLabel($label) {
        $this->Label = $label;
    }

    // Returns the current label
    function getCurrentLabel() {
        if (!isset($this->Label)) {
            $this->Label = "";
        }
        return $this->Label;
    }

    // Sets the current label-description:
    function setDescription($description) {
        if (!isset($this->Description)) {
            $this->Description = "";
        }
        $this->Description = $description;
    }

    // Returns the current label-description:
    function getDescription() {
        return $this->Description;
    }

    function toHtml($style = "") {

        $out_label = '';
        if (isset($this->Xlabel)) {
            $out_label = '<h6 class="panel-title txt-dark">' . $this->Xlabel . '</h6>';
        }

        $height = $this->getHeight();
        $sliceLabelShow = (!empty($this->showSliceLabels)) ? 'true' : 'false';

        $out_script =<<<HTML
                            <script>
                                $(document).ready(function () {

                                    $this->graph

                                    function formatPieValue(bytes) {
                                        var n = Number(bytes);
                                        if (!isFinite(n)) {
                                            return String(bytes);
                                        }
                                        var abs = Math.abs(n);
                                        if (abs >= 1073741824) {
                                            return (n / 1073741824).toFixed(2) + ' GiB';
                                        }
                                        if (abs >= 1048576) {
                                            return (n / 1048576).toFixed(1) + ' MiB';
                                        }
                                        if (abs >= 1024) {
                                            return (n / 1024).toFixed(1) + ' KiB';
                                        }
                                        if (abs === Math.floor(abs)) {
                                            return String(n) + ' B';
                                        }
                                        return n.toFixed(2);
                                    }

                                    function pieSliceText(label, series) {
                                        var val = (series.data && series.data[0]) ? series.data[0][1] : 0;
                                        var pct = (typeof series.percent === 'number') ? series.percent.toFixed(1) : '0.0';
                                        return label + ': ' + formatPieValue(val) + ' (' + pct + '%)';
                                    }

                                    var options = {
                                        series: {
                                            pie: {
                                                show: true,
                                                radius: 1,
                                                innerRadius: 0,
                                                label: {
                                                    show: $sliceLabelShow,
                                                    radius: 0.65,
                                                    formatter: function (label, series) {
                                                        return '<div style="font-size:11px;text-align:center;padding:3px 5px;color:#fff;">' +
                                                            label + '<br>' + series.percent.toFixed(1) + '%</div>';
                                                    },
                                                    background: { opacity: 0.55, color: '#000' }
                                                }
                                            }
                                        },
                                        legend: {
                                            show: true
                                        },
                                        grid: {
                                            hoverable: true,
                                            clickable: false
                                        }
                                    };

                                    var chartId = '$this->id';
                                    var retries = 0;
                                    var maxRetries = 25;
                                    var \$pieTip = $('#uifc2-pie-tooltip');
                                    if (!\$pieTip.length) {
                                        \$pieTip = $('<div id="uifc2-pie-tooltip" class="flot-tooltip"></div>').appendTo('body');
                                    }

                                    function tryRenderPieChart() {
                                        var \$target = $('#' + chartId);
                                        if (!\$target.length) {
                                            return;
                                        }

                                        if (!\$target.is(':visible') || \$target.width() <= 0 || \$target.height() <= 0) {
                                            if (retries < maxRetries) {
                                                retries++;
                                                setTimeout(tryRenderPieChart, 120);
                                            }
                                            return;
                                        }

                                        try {
                                            $.plot('#' + chartId, data, options);
                                            \$target.off('plothover.uifc2pie mouseleave.uifc2pie')
                                                .on('plothover.uifc2pie', function (event, pos, item) {
                                                    if (!item || !item.series) {
                                                        \$pieTip.hide();
                                                        return;
                                                    }
                                                    \$pieTip.html(pieSliceText(item.series.label, item.series))
                                                        .css({
                                                            top: (pos.pageY + 12) + 'px',
                                                            left: (pos.pageX + 12) + 'px',
                                                            position: 'absolute',
                                                            display: 'block',
                                                            zIndex: 1040
                                                        })
                                                        .show();
                                                })
                                                .on('mouseleave.uifc2pie', function () {
                                                    \$pieTip.hide();
                                                });
                                        } catch (e) {
                                            if (retries < maxRetries) {
                                                retries++;
                                                setTimeout(tryRenderPieChart, 120);
                                            }
                                        }
                                    }

                                    tryRenderPieChart();

                                    $(document).on('shown.bs.tab shown.bs.collapse', function () {
                                        retries = 0;
                                        tryRenderPieChart();
                                    });

                                    $(window).on('resize', function () {
                                        retries = 0;
                                        tryRenderPieChart();
                                    });
                                });
                            </script>
                            <!-- End: uifc2/PieChart.php ($this->id) -->
        HTML;

        $out =<<<HTML
                            <!-- Start: uifc2/PieChart.php ($this->id) -->
                            <div class="panel panel-default card-view">
                                <div class="panel-heading">
                                    <div class="pull-left">
                                        $out_label
                                    </div>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="panel-wrapper collapse in">
                                    <div class="panel-body">
                                        <div class="flot-container" style="height:$height">
                                            <div id="$this->id" class="demo-placeholder"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
        $out_script
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

        $this->BxPage->setExtraFooters($extraFooters);

        return $out;
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
