<?php
// Author: Michael Stauber
// $Id: DatePicker.php
//
// Class to create DatePicker Elements
//

global $isPieChartDefined;
if ( $isPieChartDefined )
    return;
$isPieChartDefined = true;

include_once("uifc2/HtmlComponent.php");

class DatePicker extends HtmlComponent {

    var $BxPage;
    var $id;
    var $value;
    var $Label, $Description;
    var $i18n;
    var $access;
    var $tooltip;
    var $tooltip_placement;
    var $modus = 'all';
    var $minDate;
    var $maxDate;
    var $is_Submit;

    //
    // public methods
    //

    // constructor
    public function __construct($BxPage, $id, $value, $i18n) {
        $this->BxPage = $BxPage;
        $this->id = $id;
        $this->value = $value;
        $this->i18n = $i18n;
    }

    //
    //--- Set up Submit event on date change:
    //

    function setSubmit($var) {
        $this->is_Submit = $var;
    }

    //
    //--- Handle DatePicker modus ('hours', 'days', 'all')
    //

    function setModus($var) {
        $available_modes = array('hours', 'days', 'all');
        if (in_array($var, $available_modes)) {
            $this->modus = $var;
        }
    }

    function getModus() {
        return $this->modus;
    }

    //
    //--- Handle Min/Max Dates:
    //

    function setMinDate($value='') {
        $this->minDate = $value;
    }

    function setMaxDate($value='') {
        $this->maxDate = $value;
    }

    function getMinDate($value='') {
        return $this->minDate;
    }

    function getMaxDate($value='') {
        return $this->maxDate;
    }

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

    function getId() {
        return $this->id;
    }

    function getValue() {
        return $this->value;
    }

    function setAccess($access) {
        $this->access = $access;
    }

    function getAccess() {
        $access = "rw";
        return $access;
    }

    function isOptional() {
        return TRUE;
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

    function toHtml($style = "") {

        $id = $this->getId();
        $picker_id = $id . "_picker";

        if (isset($page->BXLabel[$id])) {
            $LabelArray = array_keys($page->BXLabel[$id]);
            $HtmlLabel = $this->i18n->getHtml($LabelArray[0]);
            $DescArray = array_values($page->BXLabel[$id]);
            $HtmlDesc = $this->i18n->getWrapped($DescArray[0]);
        }

        if (!isset($HtmlLabel)) {
            $HtmlLabel = $this->getCurrentLabel();
        }

        if (!isset($HtmlDesc)) {
            $HtmlDesc = $this->getDescription();
        }

        $tooltip_out = $this->MakeTooltip($HtmlDesc, 'right');
        $label_position = '';

        $timestamp = is_numeric($this->value) ? (int) $this->value : time();
        if ($timestamp <= 0) {
            $timestamp = time();
        }
        $date = new DateTime();
        $date->setTimestamp($timestamp);

        $input_type = 'datetime-local';
        $input_value = $date->format('Y-m-d\TH:i');
        $input_min = '';
        $input_max = '';
        $icons = 'fa fa-calendar';

        if ($this->modus === 'days') {
            $input_type = 'date';
            $input_value = $date->format('Y-m-d');
        }
        elseif ($this->modus === 'hours') {
            $input_type = 'time';
            $input_value = $date->format('H:i');
            $icons = 'fa fa-clock-o';
        }

        if ($this->minDate != '') {
            $input_min = 'min="' . $this->minDate . '"';
        }
        if ($this->maxDate != '') {
            $input_max = 'max="' . $this->maxDate . '"';
        }

        // Set up Submit on date change:
        $do_submit = '';
        if ($this->is_Submit != '') {
            $change_Field = $this->is_Submit;

            $do_submit = <<<HTML
            onchange="
                var selectedDate = this.value;
                $('input[name=\'$change_Field\']').val(selectedDate);
                var form = $(this).closest('form.validate_form');
                $('input[name=\'dateSelected\']', form).val(selectedDate);
                form.submit();
            "
            HTML;
        }

        $out_label =<<<HTML
            <label class="control-label mb-10 text-left $label_position" $tooltip_out>$HtmlLabel</label>
        HTML;

        $out =<<<HTML

                                                        <!-- uifc2/DatePicker.php -->
                                                        <div class="form-group pb-10">
                                                            $out_label
                                                            <div class="input-group" id="$picker_id" name="$picker_id">
                                                                <input type="$input_type" class="form-control" id="$id" name="$id" value="$input_value" $input_min $input_max $do_submit>
                                                                <span class="input-group-addon" role="button" tabindex="0" style="cursor:pointer;" onclick="(function(fieldId){var el=document.getElementById(fieldId);if(!el){return;}try{if(typeof el.showPicker==='function'){el.showPicker();return;}}catch(e){}el.focus();el.click();})('$id');" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                                                                    <span class="$icons"></span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <!-- uifc2/DatePicker.php -->

        HTML;

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
