<?php
// Author: Michael Stauber
// $Id: Bar.php
global $isBarDefined;
if ($isBarDefined) return;
$isBarDefined = true;

include_once ("uifc2/FormField.php");

class Bar extends FormField {
    //
    // private variables
    //
    var $label;
    var $tooltip;
    var $tooltip_placement = 'top';
    var $placeholder;

    //
    // public methods
    //
    //
    //--- Tooltip generation:
    //
    function setTooltip($tooltip) {
        $this->tooltip = $tooltip;
    }

    function setTooltipPlacement($tooltip_placement) {
        $this->tooltip_placement = $tooltip_placement;
    }

    function MakeTooltip($description, $tooltip_placement = 'top') {
        $this->setTooltip($description);
        $this->setTooltipPlacement($tooltip_placement);
        $this->tooltip = 'data-toggle="tooltip" data-placement="' . $this->tooltip_placement . '" title="' . $this->tooltip . '" data-original-title="' . $this->tooltip . '" data-container="body"';
        return $this->tooltip;
    }

    //function Bar(&$page, $id, $value, $i18n) {
    public function __construct(&$page, $id, $value, $i18n) {
        $this->page = $page;
        $this->id = $id;
        $this->value = $value;
        $this->i18n = $i18n;
    }

    function getLabel() {
        return $this->label;
    }

    // description: set label to replace the percentage shown by default
    // param: label: a label in string
    function setLabel($label) {
        $this->label = $label;
    }

    function setBarText($text) {
        $this->bartext = $text;
    }

    // description: set bar to type vertical
    // Deprecated for now.
    function setVertical() {
        $this->orientation = 'v';
    }

    // description: defines where the labels are placed on formfields:
    function setLabelType($labeltype) {
        $this->LabelType = $labeltype;
    }

    // Returns where the labels are placed on formfields:
    function getLabelType() {
        if (!isset($this->LabelType)) {
            $this->LabelType = "label_side top";
        }
        return $this->LabelType;
    }

    // The Helptext of the Bar can either be below the bar (default) or on the right side of it:
    function setHelpTextPosition($htpos) {
        $this->HelpTextPosition = $htpos;
    }

    // Returns HelpTextPosition. "bottom" is default. Alternative is "right":
    function getHelpTextPosition() {
        if (!isset($this->HelpTextPosition)) {
            $this->HelpTextPosition = "bottom";
        }
        return $this->HelpTextPosition;
    }

    function toHtml($style = "") {

        // Handle Label:
        $label_array = $this->page->getLabel($this->id);
        if (!is_array($label_array)) {
            $text = $this->i18n->getHtml($this->id);
            $h = $this->id . '_help';
            $helptext = $this->i18n->getWrapped("[[$h]]");
        }
        else {
            $key = array_keys($label_array);
            $val = array_values($label_array);
            if (strlen($key[0]) > "2") {
                // We have a Label. Use it:
                $text = $this->i18n->getHtml($key[0]);
                $helptext = $val[0];
            }
            else {
                // Use the ID instead:
                $text = $this->i18n->getHtml($this->id);
                $h = $this->id . '_help';
                $helptext = $this->i18n->getWrapped("[[$h]]");
            }
        }

        $percentage = $this->value;

        if (isset($this->bartext)) {
            $percentage_helptext = $this->bartext;
        }
        else {
            $percentage_helptext = $percentage . "%";
        }

        if ($this->getHelpTextPosition() == "right") {
            $ht_right = $percentage_helptext;
            $ht_bottom = '';
        }
        elseif ($this->getHelpTextPosition() == "bottom") {
            $ht_right = '';
            $ht_bottom = '<div align="center">' . $percentage_helptext . '</div>';
        }
        else {
            $ht_right = '';
            $ht_bottom = '';
        }

        $tooltip = $this->MakeTooltip($percentage_helptext, $this->tooltip_placement);

        $out = "                        <!-- Start: /uifc2/Bar.php ($this->id)  -->\n";

        if ($this->getLabelType() != "nolabel") {
            $out .= <<<HTML
                                        <div class="form-group pb-0">
                                            <label for="$this->id" class="control-label mb-10 label_side top" data-toggle="tooltip" data-placement="right" title="" data-original-title="$helptext" data-container="body">$text</label>&nbsp;<span class="text-muted"></span>
            HTML;
        }

        if ($this->getHelpTextPosition() == "right") {
          $out .= <<<HTML
                                                <div class="form-group pt-10" style="display: flex; align-items: center;">
                                                    <div class="progress progress-lg" style="flex: 1;">
                                                        <div class="progress-bar progress-bar-primary" style="width: $percentage%;" role="progressbar" $tooltip>$percentage%</div>
                                                    </div>
                                                    <div class="percentage-text ml-10 pb-10">$percentage%</div>
                                                </div>
            HTML;
        }
        else {
            $out .=<<<HTML

                                                <div class="progress progress-lg">
                                                    <div class="progress-bar progress-bar-primary" style="width: $percentage%;" role="progressbar" $tooltip>$percentage%</div>
                                                </div>
                                                $ht_bottom
            HTML;
        }

        if ($this->getLabelType() != "nolabel") {
            $out .=<<<HTML

                                        </div>

            HTML;
        }

        $out .= "                       <!-- End: /uifc2/Bar.php ($this->id)  -->\n";

        return $out;
    }
}

/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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
