<?php
// Author: Michael Stauber
// $Id: BarGraph.php
//
// This Class has the same name as the BarGraph Class from
// Cobalt Networks and/or the BlueQuartz and BlueOnyx 510[6|7|8]R.
// However, it is a complete rewrite from scratch. Don't try to 
// use this Class by just calling it with the same parameters and 
// functions as before. Because you might get different results.
//
// This new Class uses Flot instead to generate the Graphs:
//
// Flot
// Version: 0.7
// Link: http://code.google.com/p/flot/
// Description: Charting solution, see link for full feature list.
//

global $isBarGraphDefined;
if ( $isBarGraphDefined )
    return;
$isBarGraphDefined = true;

include_once("uifc2/HtmlComponent.php");

class BarGraph extends HtmlComponent {

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

    //
    // public methods
    //

    // constructor
    //function BarGraph($BxPage, $id, $data, $xlabels) {
    public function __construct($BxPage, $id, $data, $xlabels) {
        $this->BxPage = $BxPage;
        $this->id = $id;

        // Set up the X-Axis labels:
        $this->xlabels = $xlabels;

        // Find out how many Graphs our $data contains:
        $this->numgraphs = count(array_keys($data));

        // We use the array keys as labels for our graphs.
        // So we extract the keys first:
        $this->data_labels = array_keys($data);

        // Do a proper encoding of our Graph data:
        for ($i=0; $i < $this->numgraphs; $i++) { 
            $this->graph[$i] = "[ ";
            $endentry = count(array_values($data[$this->data_labels[$i]]));
            $entry = "0";
            foreach ($data[$this->data_labels[$i]] as $key => $value) {
                // The fucking joys of PHP's localization implementation:
                // If we're in 'en_US', the dot is our delimiter. If we're
                // in 'de_DE' or others, the comma is the numeric delimiter.
                // jQuery expects dots as delimiter. So we give it dots:
                $value = preg_replace('/,/', '.', $value);
                
                $this->graph[$i] .= "[" . $key . ", " . $value . "]";
                $this->Lines[$i] = TRUE;
                $this->Points[$i] = TRUE;
                $this->Bars[$i] = FALSE;
                $entry++;
                if ($entry < $endentry) {
                    $this->graph[$i] .= ", ";
                }
            }
            $this->graph[$i] .= " ]";
        }

        // Set up the color array for the line colors:
        $this->LineColor = array(
                            "0" => "#9e253b ",
                            "1" => "#1C5EA0 ",
                            "2" => "#3d8336",
                            "3" => "#4C5766 ",
                            "4" => "#2b4356 ",
                            "5" => "#9b6ca6",
                            "6" => "#53453e"
                            );

        // Set up the X-Axis labels:
        $this->xaxisLabels = "";
        // Do we have an array with the X-Axis labels?
        if (is_array($this->xlabels)) {
            // We do. So we format it properly:
            $this->xaxisLabels .= "xaxis: { ticks:[";
            $counter = "0";
            $maxlabels = count($this->xlabels);
            foreach ($this->xlabels as $key => $value) {
                $this->xaxisLabels .= "[" . $key . ", " . $value . "]";
                $counter++;
                if ($counter < $maxlabels) {
                    $this->xaxisLabels .= ", ";
                }
            }
            $this->xaxisLabels .= "] },";
        }
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

    // For the graph in question it enables the lines display:
    function setLines($barid = "", $lines=TRUE) {
        $graphkey = array_search($barid, $this->data_labels);
        $this->Lines[$graphkey] = $lines;
    }

    // For the graph in question it enables the lines display:
    function setPoints($barid = "", $points=TRUE) {
        $graphkey = array_search($barid, $this->data_labels);
        $this->Points[$graphkey] = $points;
    }

    // For the graph in question it enables the lines display:
    function setBars($barid = "", $bars=TRUE) {
        $graphkey = array_search($barid, $this->data_labels);
        $this->Bars[$graphkey] = $bars;
    }

    function toHtml($style = "") {

        // Get Cookie-Locale to determine the currently used language:
        $CI =& get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $cookie_locale = $BX_SESSION['locale'];

        $i18n = new I18n("palette", $cookie_locale);

        $maximize_icon_helptext = $i18n->getWrapped("[[palette.icon_maximize]]");
        $height = $this->getHeight();
        //$width = $this->getWidth();
        $width = '100%';

        if (isset($this->Xlabel)) {
            $out =<<<HTML

                                    <div class="panel panel-default card-view">
                                        <div class="panel-heading">
                                            <div class="pull-left">
                                                <h6 class="panel-title txt-dark">$this->Xlabel</h6>
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
                                                <div class="flot-container" style="height:$height; width:$width">
                                                    <div id="$this->id" class="demo-placeholder"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                        HTML;
        }
        else {
            $out =<<<HTML

                                    <div class="panel panel-default card-view">
                                        <div class="panel-wrapper collapse in">
                                            <div class="panel-body">
                                                <div class="flot-container" style="height:$height; width:$width">
                                                    <div id="$this->id" class="demo-placeholder"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                        HTML;
        }

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

        $out_scripts = "        <script>
            (function() {
                var graphId = \"" . $this->id . "\";
                var renderRetries = 0;
                var maxRenderRetries = 25;

                function tryRenderGraph() {
                    var \$target = $(\"#\" + graphId);
                    if (!\$target.length) {
                        return;
                    }

                    // Flot fails hard when initialized in hidden/zero-sized containers.
                    if (!\$target.is(\":visible\") || \$target.width() <= 0 || \$target.height() <= 0) {
                        if (renderRetries < maxRenderRetries) {
                            renderRetries++;
                            setTimeout(tryRenderGraph, 120);
                        }
                        return;
                    }

                    try {
                        $.plot(\$target,
            [";

        // Print the scripts for all graphs. One by one.            
        for ($i=0; $i < count($this->graph); $i++) {
            $out_scripts .= "
                    {
                        shadowSize: 5,
                        label:'" . $this->data_labels[$i] . "',
                        color:'" . $this->LineColor[$i] . "',
                        data: " . $this->graph[$i] . ",";

            // Do we want just Lines?
            if ($this->Lines[$i] == TRUE) {
                $out_scripts .= "
                        lines: {
                            show: true,
                            fill: false,
                            lineWidth: 3
                            },";
            }
            // Do we want Points as well?
            if ($this->Points[$i] == TRUE) {
                $out_scripts .= "
                        points: {
                            show: true,
                            fill: true,
                            lineWidth: 2
                            },";
            }
            // Do we want Bars?
            if ($this->Bars[$i] == TRUE) {
                $out_scripts .= "
                        bars: {
                            show: true
                        }";
            }
            $out_scripts .= "
                    }";
            if ($i < count($this->graph)) {
                $out_scripts .= ',' . "\n";
            }
        }
        $out_scripts .= "
            ],
                {
                    grid:{
                        show: true,
                        aboveData: false,
                        backgroundColor: { colors: [\"#fff\", \"#eee\"] },
                        labelMargin: 10,
                        axisMargin: 0,
                        borderWidth: 1,
                        borderColor: '#cccccc',
                        minBorderMargin: 40,
                        clickable: true,
                        hoverable: true,
                        autoHighlight: true,
                        mouseActiveRadius: 10
                    },";
        $out_scripts .= "\n                 " . $this->xaxisLabels;
        $out_scripts .= "                   
                    legend: {
                        show: true,
                        labelBoxBorderColor: '#ddd',
                        noColumns: 5,
                        margin: 10,
                        backgroundColor: 'transparent',
                        backgroundOpacity: 0
                      }
                }
            );
                    } catch (e) {
                        if (renderRetries < maxRenderRetries) {
                            renderRetries++;
                            setTimeout(tryRenderGraph, 120);
                        }
                    }
                }

                $(document).ready(function() {
                    tryRenderGraph();

                    // Retry when containers become visible (tabs/collapses/layout changes).
                    $(document).on(\"shown.bs.tab shown.bs.collapse\", function() {
                        renderRetries = 0;
                        tryRenderGraph();
                    });
                    $(window).on(\"resize\", function() {
                        renderRetries = 0;
                        tryRenderGraph();
                    });
                });
            })();
        </script>\n";

        $this->BxPage->setExtraFooters($out_scripts);

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
