<?php
// Author: Kenneth C.K. Leung
// $Id: TimeZone.php
global $isTimeZoneDefined;
if ($isTimeZoneDefined) return;
$isTimeZoneDefined = true;

include_once ("uifc2/FormField.php");
include_once ("uifc2/FormFieldBuilder.php");

//
// protected class variables
//
class TimeZone extends FormField {

    var $Label = "";
    var $Description = "";
    var $tooltip;
    var $tooltip_placement;
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

        $page = $this->getPage();
        $form = $page->getForm();
        $formId = $form->getId();
        $id = $this->getId();
        $i18n = $page->getI18n();
        $value = $this->getValue();
        if (isset($page->BXLabel[$id])) {
            $LabelArray = array_keys($page->BXLabel[$id]);
            $HtmlLabel = $i18n->getHtml($LabelArray[0]);
            $DescArray = array_values($page->BXLabel[$id]);
            $HtmlDesc = $i18n->getWrapped($DescArray[0]);
        }

        if (!isset($HtmlLabel)) {
            $HtmlLabel = $this->getCurrentLabel();
        }

        if (!isset($HtmlDesc)) {
            $HtmlDesc = $this->getDescription();
        }

        $tooltip_out = $this->MakeTooltip($HtmlDesc, 'right');
        $label_position = '';

        // make sure there is a default value
        $value = ($value == "") ? "US/Eastern" : $value;

        $selector_out = ln_display_timezone_selector($value);

        $html_out =<<<HTML
                    <div class="form-group pb-10">
                        <label for="$id" class="control-label mb-10 $label_position" $tooltip_out>$HtmlLabel</label>
                            $selector_out
                        <span class="glyphicon form-control-feedback pt-5" aria-hidden="true"></span>
                        <div class="help-block with-errors"></div>
                    </div>
        HTML;

        return $html_out;

    }

}

/*
Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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
