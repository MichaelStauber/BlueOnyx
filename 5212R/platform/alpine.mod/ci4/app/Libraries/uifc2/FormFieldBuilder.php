<?php
// Author: Kevin K.M. Chiu
// $Id: FormFieldBuilder.php
// description:
// This class helps to build form field components.
//
// applicability:
// Any form field can use this class to build components.
global $isFormFieldBuilderDefined;
if ($isFormFieldBuilderDefined) return;
$isFormFieldBuilderDefined = true;

include_once ("System.php");
include_once ("ArrayPacker.php");

class FormFieldBuilder {

    var $Label = "";
    var $Description = "";
    var $tooltip;
    var $tooltip_placement;
    var $placeholder;
    var $RangeMin;
    var $RangeMax;
    var $setSorted;
    var $LabelType;
    var $columnWidths;
    var $maxLength;
    var $size;

    //
    // public methods
    //

    function setPlaceholder($val) {
        $this->placeholder = $val;
    }

    function getPlaceholder() {
        return $this->placeholder;
    }

    function setRangeMin($min) {
        $this->RangeMin = $min;
    }

    function setRangeMax($max) {
        $this->RangeMax = $max;
    }

    // Sets the current label
    function setCurrentLabel($label) {
        $this->Label = $label;
    }

    // Returns the current label
    function getCurrentLabel() {
        $label = $this->Label;
        return $label;
    }

    // Sets the current label-description:
    function setDescription($description) {
        $this->Description = $description;
    }

    // Returns the current label-description:
    function getDescription() {
        return $this->Description;
    }

    // description: define if output should be sorted:
    function setSorted($sort) {
        $this->setSorted = $sort;
    }

    // description: returns if sorting is enabled:
    function getSorted() {
        if (!isset($this->setSorted)) {
            $this->setSorted = false;
        }
        return $this->setSorted;
    }

    // description: defines where the labels are placed on formfields:
    function setLabelType($type) {
        $this->LabelType = $type;
    }

    // Returns where the labels are placed on formfields:
    function getLabelType() {
        if (!isset($this->LabelType)) {
            $this->LabelType = "";
        }
        return $this->LabelType;
    }

    // description: Allows to define column widths
    // param: array with column widths. Not in pixels,
    // but 'col_25', 'col_33', 'col_50', 'col_100' instead.
    function setColumnWidths($columnWidths) {
        $this->columnWidths = $columnWidths;
    }

    // description: get the column widths for items in entries
    // returns: an array of widths
    // see: setColumnWidths()
    function getColumnWidths() {
        return $this->columnWidths;
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

    // description: make a Divider in the same style as we make FormFields. Replaces addDivider()
    // returns: a divider FormField object
    function makeBxDivider($id, $label, $i18n) {
        if (!$label) {
            $label = $i18n->get($id);
            $tooltip = $i18n->getWrapped($id);
        }
        else {
            $label = $i18n->get($label);
            $tooltip = $i18n->getWrapped($label);
        }

        $tooltip_out = $this->MakeTooltip($tooltip, 'top');

        $out =<<<HTML
                                    <!-- BxDivider -->
                                    <div class="col-sm-12 alert alert-tag alert-dismissable alert-style-2">
                                        <span $this->tooltip><i class="fa fa-tag"></i>$label</span>
                                    </div>
                                    <!-- /BxDivider -->
        HTML;
        return $out;
    }

    // description: make a checkbox field <-- Is now an alias for makeRadioField()!
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: checked: if it has a value checked, false otherwise
    // param: onClick: the onClick attribute of the field
    // param: extraClasses: extra stylesheet classes to pass on
    // returns: HTML that represents the field
    function makeCheckboxField($id, $value, $access, $i18n, $onClick = "", $extraClasses = "", $doOldCheckbox = 'xxx') {
        return $this->makeRadioField($id, $value, $access, $i18n, $onClick = "", $extraClasses = "");
    }

    // description: make a checkbox field
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: checked: if it has a value checked, false otherwise
    // param: onClick: the onClick attribute of the field
    // param: extraClasses: extra stylesheet classes to pass on
    // returns: HTML that represents the field
    function makeRadioField($id, $value, $access, $i18n, $onClick = "", $extraClasses = "") {

        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }

        if ((!is_array($value))) {
            // Single radio:
            $checked = $value ? "CHECKED" : "";
            $hidden_field = $this->makeHiddenField('checkbox-' . $id, $value);
            $tooltip_out = $this->MakeTooltip($helptext, 'right');

            $label_line = '';
            if (!preg_match('/nolabel/', $this->getLabelType())) {
                $label_line =<<<HTML
                                        <div class="col-xs-6 col-sm-3">
                                            <label class="control-label pt-20" $tooltip_out>$label</label>
                                        </div>

                HTML;
            }

            $access_disabled = '';
            if (($access === "rw") || ($access === "w") || ($access === "r")) {
                if ($access === "r") {
                    $access_disabled = 'disabled';
                }
                $out =<<<HTML
                                $hidden_field
                                <div class="row pb-10">
                                    <div class="form-group">
                                        $label_line
                                        <div class="col-xs-6 col-sm-3">
                                            <div class="checkbox">
                                                <input type="checkbox" id="$id" name="$id" $checked class="js-switch js-switch-1" data-color="#4aa23c" data-secondary-color="#f8b32d" data-size="small" $access_disabled>
                                            </div>
                                        </div>
                                        <!-- Optional: clear the XS cols if their content doesn't match in height -->
                                        <div class="clearfix visible-xs"></div>
                                    </div>
                                </div>
                HTML;
            }
            else {
                $out =<<<HTML
                                $hidden_field
                HTML;            
            }
        }
        else {
            // $value is an array for multiple radios and contains array(id => value) for each instead:
            $tooltip_out = $this->MakeTooltip($helptext, 'right');

            $label_line = '';
            if (!preg_match('/nolabel/', $this->getLabelType())) {
                $label_line =<<<HTML
                                    <div class="col-xs-6 col-sm-3">
                                        <label class="control-label pt-20" $tooltip_out>$label</label>
                                    </div>

                HTML;
            }

            $out =<<<HTML
                            <div class="row pb-10">
                                <div class="form-group">
                                    $label_line
                                    <div class="col-xs-6 col-sm-3">
                                        <div class="row mt-40">
                                            <div class="col-sm-12">

            HTML;

            $fields = '';
            foreach ($value as $id_key => $setting) {
                $Option_Text = $i18n->get($id_key);
                $opt_tooltip_out = $i18n->getWrapped($id_key . "_help");

                if ($setting == "1") {
                    $out .= "\n" . $this->makeHiddenField('radio-' . $id, $id_key);
                    $out .=<<<HTML
                                                <div class="radio">
                                                    <input type="radio" name="$id" id="$id_key" value="$id_key" checked="">
                                                    <label for="$id_key" title="$opt_tooltip_out">$Option_Text</label>
                                                </div>
                    HTML;
                }
                else {
                    $out .=<<<HTML
                                                <div class="radio">
                                                    <input type="radio" name="$id" id="$id_key" value="$id_key">
                                                    <label for="$id_key" title="$opt_tooltip_out">$Option_Text</label>
                                                </div>
                    HTML;
                }
            }

            $out .=<<<HTML

                                            </div>
                                        </div>
                                    </div>
                                    <!-- Optional: clear the XS cols if their content doesn't match in height -->
                                    <div class="clearfix visible-xs"></div>
                                </div>
                            </div>
            HTML;
        }
        return $out;
    }

    // description: make a file upload field
    // param: id: the identifier of the field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the length of the field
    // param: maxLength: maximum number of characters
    //     that can be entered into the field
    // param: onChange: the onChange attribute of the field
    // returns: HTML that represents the field
    function makeFileUploadField($page, $id, $access, $i18n, $size, $maxLength, $onChange) {
        if ($access == "" || $access == "r") return $this->makeHiddenField($id, "");

        // find size
        $size = ($size > 0) ? "SIZE=\"$size\"" : "";

        // find max size
        $maxLength = ($maxLength > 0) ? "MAXLENGTH=\"$maxLength\"" : "";

        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }

        $out_label = '';
        if ($this->getLabelType() != 'nolabel') {
            $tooltip_out = $this->MakeTooltip($helptext, 'right');
            $label_position = $this->getLabelType();
            $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
            $out_label =<<<HTML
                                    <label for="$id" class="control-label mb-10 $label_position" $tooltip_out>$label</label>&nbsp;<span class="text-muted">$optional_text</span>
            HTML;
        }

        $out =<<<HTML

                                <div class="form-group pb-10">
                                    $out_label
                                    <input type="file" id="$id" name="$id" class="dropify" />
                                </div>

        HTML;

        $page->setExtraHeaders('
                <!-- Bootstrap Dropify CSS -->
                <link href="/.elm/vendors/bower_components/dropify/dist/css/dropify.min.css" rel="stylesheet" type="text/css"/>
            ');

        $page->setExtraFooters('
                <!-- Bootstrap Dropify JavaScript -->
                <script src="/.elm/vendors/bower_components/dropify/dist/js/dropify.min.js"></script>
                
                <!-- Form Flie Upload Data JavaScript -->
                <script src="/.elm/dist/js/form-file-upload-data.js"></script>
            ');

        return $out;
    }

    // description: make a hidden field
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // returns: HTML that represents the field
    function makeHiddenField($id, $value = "", $useFormspecialchars = true) {

        // HTML safe
        if ($useFormspecialchars == true) {
            $value = formspecialchars($value);
        }

        return "<INPUT TYPE=\"HIDDEN\" NAME=\"$id\" VALUE=\"$value\">\n";
    }

    // description: make javascript for form fields
    // param: formField: the form field to generate javascript for
    // param: changeHandler: the Javascript function
    //     that is called when the form field change
    // param: submitHandler: the Javascript function
    //     that is called when the form field submits
    // returns: HTML that represents the field
    function makeJavaScript($formField, $changeHandler, $submitHandler) {
        $access = $formField->getAccess();
        if ($access != "w" && $access != "rw") {
            return "";
        }

        $emptyMessage = $formField->getEmptyMessage();
        $invalidMessage = $formField->getInvalidMessage();
        $id = $formField->getId();
        $page = $formField->getPage();
        $form = $page->getForm();
        $formId = $form->getId();
        $isOptional = $formField->isOptional() ? "true" : "false";

        $javascript = "";

        return $javascript;
    }

    /**
     * Class and Function List:
     * Function list:
     * - makePasswordField()
     * Classes list:
     */
    // description: make a password field
    // param: id: the identifier of the field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the length of the field
    // param: onChange: the onChange attribute of the field
    // returns: HTML that represents the field
    function makePasswordField($id, $value, $access, $i18n, $checktype = "password", $isOptional = false, $size = "", $maxLength = "", $onChange = "", $confirm = "", $page = "", $checkPass = true) {
        if ($access == "" || $access == "r") {
            return $this->makeHiddenField($id, "");
        }

        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }
        $tooltip_out = '';
        if ((isset($helptext)) && (strlen(trim($helptext)) > 0)) {
            $tooltip_out = $this->MakeTooltip($helptext, 'right');
        }

        if (($isOptional == true) || ($isOptional == 'silent')) {
            $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_line = '';
            $required_line = '';
            $validate_me = 'data-validate="false"';
        }
        else {
            $optional_text = "";
            $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($checktype, $i18n) . '"></div>';
            $required_line = 'required=""';
            $validate_me = 'data-validate="false"';
        }

        if ($checkPass == true) {
            $key_up = ' onKeyUp="validate_' . $id . '(this.value)"';
            $check_results = $i18n->get("pwCheckStr", "palette");
        }
        else {
            $key_up = '';
            $check_results = '';
        }

        $id_confirm = '_' . $id . '_repeat';
        $topdiv_id = $id . '_topdiv';
        $topdiv_id_confirm = $id_confirm . '_topdiv';
        $resultId = '_' . $id . '_pwresults';

        $pwd_text = $i18n->get('[[base-alpine.loginPagePassword]]');
        $pwd_repeat_text = $i18n->get('[[palette.repeat]]');
        $pwds_mismatch = $i18n->get('[[palette.pw_not_identical]]');

        $out =<<<HTML
                    <!-- Start: /uifc2/FormFieldBuilder::makePasswordField()  -->
                    <div class="form-group">
                        <label for="$id" class="control-label mb-10" $tooltip_out>$label</label>
                        <div class="row">
                            <div id="$topdiv_id" class="form-group col-sm-12">
                                <input type="password" data-minlength="8" class="form-control" id="$id" name="$id" placeholder="$pwd_text" $key_up $validate_me $required_line>
                                <div class="help-block pwresults" id="$resultId">$check_results</div>
        HTML;

        if ((isset($helptext)) && (strlen(trim($helptext)) > 0)) {
            $helptext = htmlspecialchars($helptext, ENT_COMPAT, 'UTF-8');
            $out .=<<<HTML
                                <div class="help-block">$helptext</div>
            HTML;
        }

        $out .=<<<HTML
                            </div>
        HTML;

        if ($confirm == true) {
            $out .=<<<HTML

                                <div id="$topdiv_id_confirm" class="form-group col-sm-12">
                                    <input type="password" class="form-control" id="$id_confirm" data-match="#$id" name="$id_confirm" data-match-error="$pwds_mismatch" placeholder="$pwd_repeat_text" $validate_me $required_line>
                                    <div class="help-block with-errors">
                                        <ul class="list-unstyled">
                                            <li></li>
                                        </ul>
                                    </div>
                                </div>

            HTML;
        }
        $out .=<<<HTML
                            <div id="results"></div>
                        </div>
                    </div>
                    <!-- End: /uifc2/FormFieldBuilder::makePasswordField()  -->

        HTML;

        if ($checkPass == true) {

            $pw_way_too_short = $i18n->getHtml("[[palette.pw_way_too_short]]");
            $strong_password_msg = $i18n->getHtml("[[palette.pw_strong_password]]");
            $val_required_msg = $i18n->getHtml("[[palette.val_required]]");
            $pw_not_identical = $i18n->getHtml("[[palette.pw_not_identical]]");

            if (($isOptional == false) || ($isOptional == 'silent')) {

                // Password is a REQUIRED input:
                if ($confirm == true) {
                    $page->setExtraFooters('
                        <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>

                        <script language="Javascript">
                            // Define a function specific to ' . $id . ' validation
                            function validate_' . $id . '(word) {
                                var checkpassOBJ = function () {
                                    this.onFailure = function () {
                                        alert("Unable to validate password");
                                    };
                                    this.OnSuccess = function () {
                                        var response = this.GetResponseText();
                                        var passwordTopDiv = document.getElementById("' . $id . '_topdiv");
                                        var pwResultsDiv = document.getElementById("_' . $id . '_pwresults");
                                        var passwordRepeatDiv = document.getElementById("_' . $id . '_repeat_topdiv");
                                        var passwordRepeatHelpBlock = document.querySelector("#_' . $id . '_repeat_topdiv .help-block.with-errors ul li");

                                        // Reset classes for both fields
                                        pwResultsDiv.classList.remove("has-error");
                                        passwordTopDiv.classList.remove("has-error");
                                        passwordRepeatDiv.classList.remove("has-error");

                                        // Compare passwords
                                        var passwordValue = document.getElementById("' . $id . '").value;
                                        var passwordRepeatValue = document.getElementById("_' . $id . '_repeat").value;

                                        if (passwordValue === "") {
                                            pwResultsDiv.innerHTML = "' . $val_required_msg . '";
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        } else if (passwordValue.length < 8) {
                                            pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        } else if (response.includes("' . $strong_password_msg . '")) {
                                            pwResultsDiv.innerHTML = "' . $strong_password_msg . '";
                                            pwResultsDiv.classList.remove("has-error");
                                        } else {
                                            pwResultsDiv.innerHTML = response;
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        }

                                        if (passwordRepeatValue === "") {
                                            passwordRepeatHelpBlock.innerHTML = "' . $val_required_msg . '";
                                            passwordRepeatDiv.classList.add("has-error");
                                        } else if (passwordValue !== passwordRepeatValue) {
                                            passwordRepeatHelpBlock.innerHTML = "' . $pw_not_identical . '";
                                            passwordRepeatDiv.classList.add("has-error");
                                        } else {
                                            passwordRepeatHelpBlock.innerHTML = "";
                                            passwordRepeatDiv.classList.remove("has-error");
                                        }
                                    };
                                };

                                checkpassOBJ.prototype = new ajax_lib();
                                var checkpass = new checkpassOBJ();
                                var URL = "/gui/check_password";
                                var PARAM = "password=" + word;
                                checkpass.post(URL, PARAM);
                            }

                            // Event listener for ' . $id . '
                            document.getElementById("' . $id . '").addEventListener("keyup", function () {
                                validate_' . $id . '(this.value);
                            });

                            document.getElementById("_' . $id . '_repeat").addEventListener("input", function () {
                                validate_' . $id . '(document.getElementById("' . $id . '").value);
                            });

                            // Initial validation on page load
                            window.addEventListener(\'DOMContentLoaded\', function () {
                                var passwordRepeatElement = document.getElementById("_' . $id . '_repeat");
                                if (passwordRepeatElement && passwordRepeatElement.hasAttribute("required")) {
                                    validate_' . $id . '(document.getElementById("' . $id . '").value);
                                }
                            });
                        </script>
                    ');
                }
                else {
                    $page->setExtraFooters('
                        <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>

                        <script language="Javascript">
                            // Define a function specific to ' . $id . ' validation
                            function validate_' . $id . '(word) {
                                var checkpassOBJ = function () {
                                    this.onFailure = function () {
                                        alert("Unable to validate password");
                                    };
                                    this.OnSuccess = function () {
                                        var response = this.GetResponseText();
                                        var passwordTopDiv = document.getElementById("' . $id . '_topdiv");
                                        var pwResultsDiv = document.getElementById("_' . $id . '_pwresults");

                                        // Reset classes for the field
                                        pwResultsDiv.classList.remove("has-error");
                                        passwordTopDiv.classList.remove("has-error");

                                        var passwordValue = document.getElementById("' . $id . '").value;

                                        if (passwordValue === "") {
                                            pwResultsDiv.innerHTML = "' . $val_required_msg . '";
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        } else if (passwordValue.length < 8) {
                                            pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        } else if (response.includes("' . $strong_password_msg . '")) {
                                            pwResultsDiv.innerHTML = "' . $strong_password_msg . '";
                                            pwResultsDiv.classList.remove("has-error");
                                        } else {
                                            pwResultsDiv.innerHTML = response;
                                            pwResultsDiv.classList.add("has-error");
                                            passwordTopDiv.classList.add("has-error");
                                        }
                                    };
                                };

                                checkpassOBJ.prototype = new ajax_lib();
                                var checkpass = new checkpassOBJ();
                                var URL = "/gui/check_password";
                                var PARAM = "password=" + word;
                                checkpass.post(URL, PARAM);
                            }

                            // Event listener for ' . $id . '
                            document.getElementById("' . $id . '").addEventListener("keyup", function () {
                                validate_' . $id . '(this.value);
                            });

                            // Initial validation on page load if "password" is required
                            window.addEventListener(\'DOMContentLoaded\', function () {
                                var passwordElement = document.getElementById("' . $id . '");
                                if (passwordElement && passwordElement.hasAttribute("required")) {
                                    validate_' . $id . '(passwordElement.value);
                                }
                            });
                        </script>
                        ');

                }
            }
            else {
                // Password is an OPTIONAL input, so we only check if a password is given:

                if ($confirm == true) {
                    $page->setExtraFooters('
                        <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>

                        <script language="Javascript">
                        <!--

                        checkpassOBJ = function () {
                            this.onFailure = function () {
                                alert("Unable to validate password");
                            }
                            this.OnSuccess = function () {
                                var response = this.GetResponseText();
                                // console.log("Response from server:", response); // Log the actual response
                                var passwordTopDiv = document.getElementById("' . $topdiv_id . '");
                                var pwResultsDiv = document.getElementById("' . $resultId . '");
                                var passwordRepeatDiv = document.getElementById("' . $topdiv_id_confirm .'");
                                var passwordRepeatHelpBlock = document.querySelector("#' . $topdiv_id_confirm .' .help-block.with-errors ul li");

                                // Reset classes for both fields
                                pwResultsDiv.classList.remove("has-error");
                                passwordTopDiv.classList.remove("has-error");
                                passwordRepeatDiv.classList.remove("has-error");

                                // Compare passwords
                                var passwordValue = document.getElementById("' . $id . '").value;
                                var passwordRepeatValue = document.getElementById("' . $id_confirm . '").value;

                                // Add "has-error" class if the "password" field is required and empty
                                if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue === "") {
                                    pwResultsDiv.innerHTML = "' . $val_required_msg . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                    return;
                                }

                                // Add "has-error" class if the "password" field is required and too short
                                if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue.length < 8) {
                                    pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                    return;
                                }

                                if (passwordValue.length < 8) {
                                    // console.log("Password is too short");
                                    pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                } else {
                                    if (response.includes("' . $strong_password_msg . '")) {
                                        // console.log("We have: Strong password", response);
                                        pwResultsDiv.innerHTML = "' . $strong_password_msg . '";

                                        // Remove "has-error" class
                                        pwResultsDiv.classList.remove("has-error");
                                    } else {
                                        // console.log("We DO NOT have: Strong password", response);
                                        pwResultsDiv.innerHTML = response;
                                        pwResultsDiv.classList.add("has-error");
                                        passwordTopDiv.classList.add("has-error");
                                    }
                                }

                                // Add "has-error" class if the "password_repeat" field is required and empty
                                if (document.getElementById("' . $id_confirm . '").hasAttribute("required") && passwordRepeatValue === "") {
                                    passwordRepeatHelpBlock.innerHTML = "' . $val_required_msg . '";
                                    passwordRepeatDiv.classList.add("has-error");
                                    return;
                                }

                                if (passwordValue === passwordRepeatValue) {
                                    passwordRepeatHelpBlock.innerHTML = "";

                                    // Remove "has-error" class
                                    passwordRepeatDiv.classList.remove("has-error");
                                    // console.log("Passwords match");
                                } else {
                                    // console.log("Passwords DO NOT match");
                                    passwordRepeatHelpBlock.innerHTML = "' . $pw_not_identical . '";
                                    passwordRepeatDiv.classList.add("has-error");
                                }
                            }
                        }

                        function validate_password(word) {
                            checkpassOBJ.prototype = new ajax_lib();
                            checkpass = new checkpassOBJ();
                            var URL = "/gui/check_password";
                            var PARAM = "password=" + word;
                            checkpass.post(URL, PARAM);
                        }

                        document.getElementById("' . $id_confirm . '").addEventListener("input", function () {
                            // console.log("Cross-checking password/password_repeat");
                            validate_password(document.getElementById("' . $id . '").value);
                        });

                        // Trigger the validation on page load if "password_repeat" is required
                        window.addEventListener(\'DOMContentLoaded\', function () {
                            var passwordRepeatElement = document.getElementById("' . $id_confirm . '");
                            if (passwordRepeatElement && passwordRepeatElement.hasAttribute("required")) {
                                validate_password(passwordRepeatElement.value);
                            }
                        });

                        //-->
                        </script>
                    ');
                }
                else {
                    $page->setExtraFooters('

                        <script language="Javascript" type="text/javascript" src="/libJs/ajax_lib.js"></script>

                        <script language="Javascript">
                        <!--
                        checkpassOBJ = function () {
                            this.onFailure = function () {
                                alert("Unable to validate password");
                            }
                            this.OnSuccess = function () {
                                var response = this.GetResponseText();
                                var passwordTopDiv = document.getElementById("' . $id . '_topdiv");
                                var pwResultsDiv = document.getElementById("_' . $id . '_pwresults");

                                // Reset classes for the field
                                pwResultsDiv.classList.remove("has-error");
                                passwordTopDiv.classList.remove("has-error");

                                var passwordValue = document.getElementById("' . $id . '").value;

                                // Add "has-error" class if the "password" field is required and empty
                                if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue === "") {
                                    pwResultsDiv.innerHTML = "' . $val_required_msg . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                    return;
                                }

                                // Add "has-error" class if the "password" field is required and too short
                                if (document.getElementById("' . $id . '").hasAttribute("required") && passwordValue.length < 8) {
                                    pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                    return;
                                }

                                if (passwordValue.length < 8) {
                                    pwResultsDiv.innerHTML = "' . $pw_way_too_short . '";
                                    pwResultsDiv.classList.add("has-error");
                                    passwordTopDiv.classList.add("has-error");
                                } else {
                                    if (response.includes("' . $strong_password_msg . '")) {
                                        pwResultsDiv.innerHTML = "' . $strong_password_msg . '";

                                        // Remove "has-error" class
                                        pwResultsDiv.classList.remove("has-error");
                                    } else {
                                        pwResultsDiv.innerHTML = response;
                                        pwResultsDiv.classList.add("has-error");
                                        passwordTopDiv.classList.add("has-error");
                                    }
                                }
                            }
                        }

                        function validate_password(word) {
                            checkpassOBJ.prototype = new ajax_lib();
                            checkpass = new checkpassOBJ();
                            var URL = "/gui/check_password";
                            var PARAM = "password=" + word;
                            checkpass.post(URL, PARAM);
                        }

                        document.getElementById("' . $id . '").addEventListener("input", function () {
                            validate_password(document.getElementById("' . $id . '").value);
                        });

                        // Trigger the validation on page load if "password" is required
                        window.addEventListener(\'DOMContentLoaded\', function () {
                            var passwordElement = document.getElementById("' . $id . '");
                            if (passwordElement && passwordElement.hasAttribute("required")) {
                                validate_password(passwordElement.value);
                            }
                        });

                        //-->
                        </script>

                    ');
                }
            }
        }
        return $out;
    }

    // description: make a text field
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only and "rw" for read and write
    // param: i18n: i18n Object for translations
    // param: checktype: Defines which routine from /gui/validation we're using to verify the form input with. Like 'ipaddr', 'email_address' and so on.
    // param: isOptional: Defines if this form field requires input or is optional (TRUE/FALSE)
    // param: size: the length of the field
    // param: maxLength: maximum number of characters that can be entered into the field
    // param: onChange: the onChange attribute of the field
    // returns: HTML that represents the field
    
    /**
     * Class and Function List:
     * Function list:
     * - makeTextField()
     * Classes list:
     */
    function makeTextField($id, $value, $access, $i18n, $checktype = "", $isOptional = false, $size = "", $maxLength = "", $onChange = "", $range = "") {
        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }
        $min_max = ' ';
        $shortval = $value;
        if (($maxLength > 0) && (strlen($value) > $maxLength)) {
            $shortval = substr($value, 0, $maxLength) . ' ...';
        }

        // Handle the range of the allowed values:
        $this->range = $range;
        if (isset($this->range)) {
            if ($this->range != '') {
                $this->range = '<span class="text-muted pl-10">' . $this->range . '</span>';
            }
        }
        else {
            $this->range = '';
        }

        // Handle size of input fields:
        $this->size = $size;
        if ($this->size) {
            $size_tag = ' SIZE="' . $this->size . '"';
        }
        else {
            $size_tag = '';
        }

        // Handle maxLength of input fields:
        $this->maxLength = $maxLength;
        if ($this->maxLength) {
            $maxLength_tag = '  maxlength="' . $this->maxLength . '"';
        }
        else {
            $maxLength_tag = '';
        }

        switch ($access) {
            case "":
                return $this->makeHiddenField($id, $value);

            case "html":
                // HTML safe
                $shortval = $shortval;
                $value = $value;
                $HTMLaccess = 'r';
            break;

            case "r":
                // HTML safe
                $shortval = htmlspecialchars($shortval, ENT_COMPAT, 'UTF-8');
                $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
            break;

            case "R":
                // assume $shortval is already html-safe
                return $shortval . $this->makeHiddenField($id, $value);

            case "w":
                $value = "";
            break;

            case "rw":
                // HTML safe
                $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
            break;
        }

        if (isset($HTMLaccess)) {
            $access = 'r';
        }

        // log activity if necessary
        $system = new System();
        $logChange = ($system->getConfig("logPath") != "") ? "top.code.uiLog_log('change', 'FormField', '$id', this.value);" : "";

        // find size
        $size = ($size > 0) ? "SIZE=\"$size\"" : "";

        // find max size
        $maxLength = ($maxLength > 0) ? "MAXLENGTH=\"$maxLength\"" : "";

        // find onChange handler
        if ($onChange != "" || $logChange != "") $onChange = "onChange=\"$logChange $onChange\"";

        // Assemble HTML:
        if ($isOptional == true) {
            $optional_txt = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_text = "&nbsp;<span class=\"text-muted\">$optional_txt</span>";
            $optional_class = ' ';
            $optional_line = '';
        }
        else {
            $optional_text = '';
            $optional_class = 'required ';
            $optional_line = '                                        <div class="required_tag tooltip hover left uniform" title="' . get_i18n_error_for_inputvalidation($checktype, $i18n) . '"></div>';
        }

        $readonly = '';
        if ($access == "hidden") {
            $input_type = "hidden";
            $show_only = '';
            // Need to reset any existing 'required' stuff:
            $optional_text = '';
            $optional_class = '';
            $optional_line = '';
        }
        elseif ($access == "rw") {
            $input_type = "text";
            $show_only = '';
            // When checktype is "range" we need to set the minimum and maximum allowed vals into the input type, too:
            if ($checktype == "range") {
                $input_type = "text";
                // But only if we really have a min and max value:
                if ((isset($this->RangeMin)) && (isset($this->RangeMax))) {
                    $min_max = ' min="' . $this->RangeMin . '" max="' . $this->RangeMax . '"';
                    // We have a range, so $input_type is a number:
                    $input_type = 'number';
                }
            }
            elseif (($checktype == "URL") || ($checktype == "url")) {
                $input_type = "url";
                // We also need to change the $checktype or the jQuery check kicks in again:
                $checktype = "nativeurl";
            }
        }
        else {
            // Covers 'r' and anything else:
            $input_type = "hidden";
            //$show_only = '<p class="text-muted" style="word-wrap: anywhere;">' . $value . '</p>';
            // Need to reset any existing 'required' stuff:
            $optional_text = '';
            $optional_class = '';
            $optional_line = '';

            // Can't use 'readonly' yet as some GUI pages uses getTextField() to show info text.
            $input_type = "text";
            $readonly = 'readonly';
            $show_only = '';
        }

        $tooltip_out = $this->MakeTooltip($helptext, 'right');
        $required_tt = $this->MakeTooltip(get_i18n_error_for_inputvalidation($checktype, $i18n), 'left');
        $label_position = $this->getLabelType();
        $optional_line = '<div class="required_tag tooltip hover left uniform" title="' . get_i18n_error_for_inputvalidation($checktype, $i18n) . '"></div>';
        $is_required =<<<HTML
                            <span class="input-group-btn" $required_tt>
                                <button type="button" class="btn btn-danger"><i class="fa fa-star"></i></button>
                            </span> 
        HTML;
        $is_required = '';

        $placeholder = '';
        if ($this->getPlaceholder() != '') {
            $placeholder = 'placeholder="' . $this->getPlaceholder() . '"';
        }
        $range_line = $this->range . "\n";

        $validation_regexp = '';
        $val_test = bx_validation();
        if (isset($val_test[$checktype])) {
            $validation_regexp = 'pattern="' . $val_test[$checktype] . '"';
        }

        $data_error = '';
        $validation_errorMsg = $i18n->getHtml("[[palette.val_remote]]");
        if (isset($val_test['MESSAGES'][$checktype])) {
            $validation_errorMsg = $val_test['MESSAGES'][$checktype];
            $data_error = 'data-error="' . $validation_errorMsg . '"';
        }

        $label_position_html = 'form-horizontal';



        if (!preg_match('/left/', $label_position)) {
            $label_position_html = '';

            $label_line = '';
            if (!preg_match('/nolabel/', $label_position)) {
                $label_line =<<<HTML
                    <label for="$id" class="control-label mb-10" $tooltip_out>$label</label>&nbsp;<span class="text-muted">$optional_text</span>
                HTML;
            }

            $out = '' . "\n";
            $out .=<<<HTML
                        <!-- Start: /uifc2/FormFieldBuilder::makeTextField($id)  -->
                        <div class="$label_position_html form-group pb-10">
                            $label_line
                            <input type="$input_type" $validation_regexp class="form-control" $min_max id="$id" name="$id" $maxLength_tag $placeholder value="$value" $data_error $optional_class $readonly>
                            $show_only
                            <span class="glyphicon form-control-feedback pt-5" aria-hidden="true"></span>
                            <div class="help-block with-errors"></div>
                            $is_required
                            $range_line
                        </div>
            HTML;
            $out .= '<!-- End: ' . "/uifc2/FormFieldBuilder::makeTextField($id)" . ' -->' . "\n";

        }
        else {

            $label_line = '';
            if (!preg_match('/nolabel/', $label_position)) {
                $label_line =<<<HTML
                    <label for="$id" class="col-sm-3 control-label mb-10" style="padding-left: 0px;" $tooltip_out>$label</label>$optional_text
                HTML;
            }

            $out = '' . "\n";
            $out .=<<<HTML
                        <!-- Start: /uifc2/FormFieldBuilder::makeTextField($id)  -->
                        <div class="form-group pb-10">
                            $label_line
                            <div class="col-sm-9">
                                <input type="$input_type" $validation_regexp class="form-control" $min_max id="$id" name="$id" $maxLength_tag $placeholder value="$value" $data_error $optional_class $readonly>
                                $show_only
                                <span class="glyphicon form-control-feedback pt-5" aria-hidden="true"></span>
                                <div class="help-block with-errors"></div>
                                $is_required
                                $range_line
                            </div>
                        </div>
            HTML;
            $out .= '<!-- End: ' . "/uifc2/FormFieldBuilder::makeTextField($id)" . ' -->' . "\n";
        }

        return $out;
    }

    // description: make a select field
    // param: id: the identifier of the field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the SIZE attribute of the HTML SELECT tag
    // param: width: the minimum width
    //     Select field width is static in Netscape, dynamic in IE
    // param: isMultiple: true if multiple items can be selected, false otherwise
    // param: formId: the ID of the form this field lives in
    // param: onChange: the onChange attribute of the field. Optional.
    // param: labels: an array of labels in string. Optional.
    //     Must have same length with values
    // param: values: an array of values in string. Optional.
    //     Must have same length with labels
    // param: selectedIndexes: an array of indexes of labels for the selected
    // returns: HTML that represents the field
    function makeSelectField($id, $access, $i18n, $size, $width, $isMultiple, $formId, $onChange = "", $labels = array(), $values = array(), $selectedIndexes = array(), $isOptional = false) {

        if ($access === "") {
            if (!$isMultiple) {
                return $this->makeHiddenField($id, $values[$selectedIndexes[0]]);
            }

            $result = "";
            for ($i = 0; $i < count($selectedIndexes); $i++) {
                $result .= $this->makeHiddenField($id, $values[$selectedIndexes[$i]]);
            }
            return $result;
        }
        elseif ($access === "r") {
            if (!$isMultiple) {
                // HTML safe
                return htmlspecialchars($labels[$selectedIndexes[0]], ENT_COMPAT, 'UTF-8') . $this->makeHiddenField($id, $values[$selectedIndexes[0]]);
            }

            $result = "";
            for ($i = 0; $i < count($selectedIndexes); $i++) {
                // HTML safe
                $result .= htmlspecialchars($labels[$selectedIndexes[$i]], ENT_COMPAT, 'UTF-8') . $this->makeHiddenField($id, $values[$selectedIndexes[$i]]);
            }
            return $result;
        }
        elseif ($access === "w" || $access === "rw") {
            $multiple = ($isMultiple) ? "MULTIPLE" : "";

            // log activity if necessary
            $system = new System();
            // log value if only one option can be selected
            $value = !$isMultiple ? ", this.options[this.selectedIndex].value" : "";
            $logChange = ($system->getConfig("logPath") != "") ? "top.code.uiLog_log('change', 'FormField', '$id' $value);" : "";

            $onChange = ($onChange !== "" || $logChange !== "") ? "onChange=\"$logChange $onChange\"" : "";

            if ($isOptional == true) {
                $is_required = '';
            }
            else {
                $is_required = 'required';
            }

            $result = '';

            if ($isMultiple) {
                $result .= '<select name="'. $id . '" id="'. $id . '" class="select2 select2-multiple" multiple="multiple" data-placeholder="Choose" ' . $is_required . '>' . "\n";
            }
            else {
                $result .= '<select name="'. $id . '" id="'. $id . '" class="form-control select2">' . "\n";
            }

            $selector_pairs = array();

            foreach ($labels as $i => $label) {
                $value = $values[$i];

                // HTML safe
                $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
                $selector_pairs[$value] = $label;
            }

            // Do we need to sort?
            if ($this->getSorted()) {
                // Sort the array, but maintain key => value association:
                natsort($selector_pairs);
            }

            // Render output of the option values:
            foreach ($selector_pairs as $key => $value) {
                $selected = (in_array($key, $selectedIndexes)) ? "SELECTED" : "";
                $result .= "                                        <OPTION VALUE=\"$key\" $selected>$value</OPTION>\n";
            }

            // do not put any new lines here because fields that use this code may
            // want no line breaks to be shown on screen
            $result .= "                                    </select>";

            // If a Label and Description are set, we use them. If not, then we
            // calculate these based on the ID of the FormObject:
            if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
                $label = $this->Label;
            }
            else {
                $label = $i18n->getHtml($id);
            }
            if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
                $helptext = $this->Description;
            }
            else {
                $h = $id . '_help';
                $helptext = $i18n->getWrapped("[[$h]]");
            }

            //$optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_txt = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_text = "&nbsp;<span class=\"text-muted\">$optional_txt</span>";

            if ($access == "hidden") {
                $input_type = "hidden";
                // Need to reset any existing 'required' stuff:
                $optional_text = '';
            }
            elseif ($access == "rw") {
                $input_type = "text";
            }
            else {
                // Covers 'r' and anything else:
                $input_type = "hidden";
                // Need to reset any existing 'required' stuff:
                $optional_text = '';
            }

            $tooltip_out = $this->MakeTooltip($helptext, 'right');
            $label_position = $this->getLabelType();

            $out = '';
            if (($access != "hidden") && ($this->getLabelType() != "nolabel")) {

                $out .=<<<HTML
                                            <!-- Start: /uifc2/FormFieldBuilder::makeSelectField($id) -->
                                            <div class="form-group" style="margin-bottom: 15px;">
                                                <label class="control-label mb-0 $label_position" $tooltip_out>$label</label>
                                                    $result
                                            </div>
                                            <span class="mb-10"></span>
                                            <!-- End: /uifc2/FormFieldBuilder::makeSelectField($id) -->
            
                HTML;
            }
            return $out;
        }
    }

    // description: make a select field but without the surrounding HTML-baggage:
    // param: id: the identifier of the field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the SIZE attribute of the HTML SELECT tag
    // param: width: the minimum width
    //     Select field width is static in Netscape, dynamic in IE
    // param: isMultiple: true if multiple items can be selected, false otherwise
    // param: formId: the ID of the form this field lives in
    // param: onChange: the onChange attribute of the field. Optional.
    // param: labels: an array of labels in string. Optional.
    //     Must have same length with values
    // param: values: an array of values in string. Optional.
    //     Must have same length with labels
    // param: selectedIndexes: an array of indexes of labels for the selected
    // returns: HTML that represents the field
    function makeNakedSelectField($id, $access, $i18n, $size, $width, $isMultiple, $formId, $onChange = "", $labels = array(), $values = array(), $selectedIndexes = array()) {
        switch ($access) {
            case "":
                if (!$isMultiple) return $this->makeHiddenField($id, $values[$selectedIndexes[0]]);

                $result = "";
                for ($i = 0;$i < count($selectedIndexes);$i++) $result .= $this->makeHiddenField($id, $values[$selectedIndexes[$i]]);
                return $result;

            case "r":
                if (!$isMultiple)
                // HTML safe
                return htmlspecialchars($labels[$selectedIndexes[0]], ENT_COMPAT, 'UTF-8') . $this->makeHiddenField($id, $values[$selectedIndexes[0]]);

                $result = "";
                for ($i = 0;$i < count($selectedIndexes);$i++)
                // HTML safe
                $result .= htmlspecialchars($labels[$selectedIndexes[$i]], ENT_COMPAT, 'UTF-8') . $this->makeHiddenField($id, $values[$selectedIndexes[$i]]);
                return $result;

                // impossible case
                
            case "w":

            case "rw":
                $multiple = ($isMultiple) ? "MULTIPLE" : "";

                // log activity if necessary
                $system = new System();
                // log value if only one option can be selected
                $value = !$isMultiple ? ", this.options[this.selectedIndex].value" : "";
                $logChange = ($system->getConfig("logPath") != "") ? "top.code.uiLog_log('change', 'FormField', '$id' $value);" : "";

                $onChange = ($onChange != "" || $logChange != "") ? "onChange=\"$logChange $onChange\"" : "";

                if ($isMultiple) {
                    $result = '<select name="'. $id . '" id="'. $id . '" class="select2 select2-multiple" multiple="multiple" data-placeholder="Choose" ' . $is_required . '>' . "\n";
                }
                else {
                    $result = '<select name="'. $id . '" id="'. $id . '" class="form-control select2">' . "\n";
                }

                $selector_pairs = array();

                for ($i = 0;$i < count($labels);$i++) {
                    $label = $labels[$i];
                    $value = $values[$i];

                    // HTML safe
                    $label = htmlspecialchars($label, ENT_COMPAT, 'UTF-8');
                    $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
                    $selector_pairs[$value] = $label;
                }

                // Do we need to sort?
                if ($this->getSorted()) {
                    // Sort the array, but maintain key => value association:
                    natsort($selector_pairs);
                }

                // Render output of the option values:
                $i = "0";
                foreach ($selector_pairs as $key => $value) {
                    $selected = (in_array($key, $selectedIndexes)) ? "SELECTED" : "";
                    $result .= "<OPTION VALUE=\"$key\" $selected>$value</OPTION>\n";
                    $i++;
                }

                // do not put any new lines here because fields that use this code may
                // want no line breaks to be shown on screen
                $result .= "</SELECT>";

                // If a Label and Description are set, we use them. If not, then we
                // calculate these based on the ID of the FormObject:
                if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
                    $label = $this->Label;
                }
                else {
                    $label = $i18n->getHtml($id);
                }
                if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
                    $helptext = $this->Description;
                }
                else {
                    $h = $id . '_help';
                    $helptext = $i18n->getWrapped("[[$h]]");
                }

                $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";

                if ($access == "hidden") {
                    $input_type = "hidden";
                    // Need to reset any existing 'required' stuff:
                    $optional_text = '';
                }
                elseif ($access == "rw") {
                    $input_type = "text";
                }
                else {
                    // Covers 'r' and anything else:
                    $input_type = "hidden";
                    // Need to reset any existing 'required' stuff:
                    $optional_text = '';
                }

                $out = '';
                $out .= '
                                            ' . $result;
                return $out;
        }
    }

    // description: make a select field but without the surrounding HTML-baggage, but make it a multiselect:
    // This is used for the new getSetSelector() UIFC Class.
    // param: id: the identifier of the field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the SIZE attribute of the HTML SELECT tag
    // param: width: the minimum width
    //     Select field width is static in Netscape, dynamic in IE
    // param: isMultiple: true if multiple items can be selected, false otherwise
    // param: formId: the ID of the form this field lives in
    // param: onChange: the onChange attribute of the field. Optional.
    // param: labels: an array of labels in string. Optional.
    //     Must have same length with values
    // param: values: an array of values in string. Optional.
    //     Must have same length with labels
    // param: selectedIndexes: an array of indexes of labels for the selected
    // returns: HTML that represents the field
    function makeMultiSelectField($id, $access, $i18n, $size, $width, $isMultiple, $formId, $onChange = "", $labels = array(), $values = array(), $selectedIndexes = array()) {
        switch ($access) {
            case "":
                if (!$isMultiple) {
                    return $this->makeHiddenField($id, $values[$selectedIndexes[0]]);
                }

                $result = "";
                for ($i = 0;$i < count($selectedIndexes);$i++) {
                    $result .= $this->makeHiddenField($id, $values[$selectedIndexes[$i]]);
                }
                return $result;

            case "r":
                if (!$isMultiple) {
                    // HTML safe
                    return htmlspecialchars($labels[$selectedIndexes[0]], ENT_COMPAT, 'UTF-8') . $this->makeHiddenField($id, $values[$selectedIndexes[0]]);
                }
                $result = "";
                $result = $this->makeHiddenField($id, '&' . implode('&', array_values($selectedIndexes)) . '&', false);

                $result .= '
                                        <fieldset class="no_lines nolabel">
                                                <div>';
                foreach ($selectedIndexes as $SIDkey => $SIDvalue) {
                    $IOIkey = array_search($SIDvalue, $values);
                    if (isset($labels[$IOIkey])) {
                        $result .= '          
                                                    <p>' . htmlspecialchars($labels[$IOIkey], ENT_COMPAT, 'UTF-8') . '</p>' . "\n";
                    }
                }
                $result .= '
                                                </div>
                                        </fieldset>' . "\n";
                return $result;

                // impossible case
                
            case "w":

            case "rw":
                $multiple = ($isMultiple) ? "multiple=\"multiple\"" : "";

                // log activity if necessary
                $system = new System();
                // log value if only one option can be selected
                $value = !$isMultiple ? ", this.options[this.selectedIndex].value" : "";
                $logChange = ($system->getConfig("logPath") != "") ? "top.code.uiLog_log('change', 'FormField', '$id' $value);" : "";

                $onChange = "";

                // Uniform dropdown selectors look like the crap:
                // So we don't use them for now:
                $brackets = "[]";

                $result = "  <SELECT $multiple NAME=\"$id$brackets\" ID=\"$id\" $onChange SIZE=\"$size\" style=\"position: absolute; left: -9999px;\">\n";

                $selector_pairs = array();

                for ($i = 0;$i < count($labels);$i++) {
                    $label = $labels[$i];
                    if (isset($values[$i])) {
                        $value = $values[$i];
                    }

                    // HTML safe
                    $label = htmlspecialchars($label, ENT_COMPAT, 'UTF-8');
                    $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
                    $selector_pairs[$value] = $label;
                }

                // Do we need to sort?
                if ($this->getSorted()) {
                    // Sort the array, but maintain key => value association:
                    natsort($selector_pairs);
                }

                // Render output of the option values:
                $i = "0";
                foreach ($selector_pairs as $key => $value) {
                    if (!is_array($selectedIndexes)) {
                        $selectedIndexes = scalar_to_array($selectedIndexes);
                    }

                    if (in_array($key, $selectedIndexes)) {
                        $result .= "<OPTION VALUE=\"$key\" selected=\"selected\">$value</OPTION>\n";
                    }
                    else {
                        $result .= "<OPTION VALUE=\"$key\">$value</OPTION>\n";
                    }
                    $i++;
                }

                // do not put any new lines here because fields that use this code may
                // want no line breaks to be shown on screen
                $result .= "</SELECT>";

                // If a Label and Description are set, we use them. If not, then we
                // calculate these based on the ID of the FormObject:
                if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
                    $label = $this->Label;
                }
                else {
                    $label = $i18n->getHtml($id);
                }
                if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
                    $helptext = $this->Description;
                }
                else {
                    $h = $id . '_help';
                    $helptext = $i18n->getWrapped("[[$h]]");
                }

                $optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";

                if ($access == "hidden") {
                    $input_type = "hidden";
                    // Need to reset any existing 'required' stuff:
                    $optional_text = '';
                }
                elseif ($access == "rw") {
                    $input_type = "text";
                }
                else {
                    // Covers 'r' and anything else:
                    $input_type = "hidden";
                    // Need to reset any existing 'required' stuff:
                    $optional_text = '';
                }

                $out = '';
                $out .= '
                                                    ' . $result;
                return $out;

        }
    }

    // description: make a HTML field
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: size: the length of the field
    // param: maxLength: maximum number of characters
    //     that can be entered into the field
    // param: onChange: the onChange attribute of the field
    // returns: HTML that represents the field
    //  function makeHtmlField($id, $value, $access, $size, $maxLength, $onChange) {
    //    $shortval = $value;
    //    if (($maxLength > 0) && (strlen($value) > $maxLength)) {
    //     //$shortval = substr($value, 0, $maxLength) . ' ...';
    //    }
    //
    //    switch($access) {
    //      case "":
    //  return $this->makeHiddenField($id, $value);
    //
    //      case "r":
    //  return $shortval;
    //
    //      case "R":
    //  return $shortval;
    //
    //      case "w":
    //  $value = "";
    //  break;
    //
    //      case "rw":
    //  $value = "VALUE=\"$value\"";
    //  break;
    //    }
    //
    //    // log activity if necessary
    //    $system = new System();
    //    $logChange = ($system->getConfig("logPath") != "") ? "top.code.uiLog_log('change', 'FormField', '$id', this.value);" : "";
    //
    //    // find size
    //    $size = ($size > 0) ? "SIZE=\"$size\"" : "";
    //
    //    // find max size
    //    $maxLength = ($maxLength > 0) ? "MAXLENGTH=\"$maxLength\"" : "";
    //
    //    // find onChange handler
    //    if($onChange != "" || $logChange != "")
    //      $onChange = "onChange=\"$logChange $onChange\"";
    //
    //    return "<INPUT TYPE=\"TEXT\" NAME=\"$id\" $value $size $maxLength $onChange>\n";
    //  }
            

    // description: make a text area field
    // param: id: the identifier of the field
    // param: value: the value of the HTML input field
    // param: access: "" for hidden, "r" for read-only, "w" for write-only
    //     and "rw" for read and write
    // param: rows: the number of rows
    // param: columns: the number of columns
    // param: onChange: the onChange attribute of the field
    // param: wrap: "on", "hard" or "off". "on" means word wrapping occurs on
    //     boundaries. "hard" means wrapping points are converted to CR-LF in
    //     the value submitted. "off" means no wrapping. Optional and "off" by
    //     default.
    // returns: HTML that represents the field
    function makeTextAreaField($page, $id, $value, $access, $i18n, $type = "", $isOptional = false, $rows = "1", $columns = "1", $onChange = "", $wrap = "off") {

        $this->i18n = $i18n;
        $this->type = $type;
        $logChange = "";

        // find onChange handler
        if ($onChange != "" || $logChange != "") {
            //$onChange = "onChange=\"$logChange $onChange\"";
            $onChange = "";
        }

        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }

        $textarea_name = $id;

        if ($isOptional == true) {
            //$optional_text = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_txt = "(" . $i18n->get("[[palette.optional]]") . ")";
            $optional_text = "&nbsp;<span class=\"text-muted\">$optional_txt</span>";
            $optional_class = ' ';
            $optional_line = '';
        }
        else {
            $optional_text = '';
            $optional_class = ' required ';
            $optional_line = '<div class="required_tag tooltip hover left" title="' . get_i18n_error_for_inputvalidation($this->type, $i18n) . '"></div>';
        }

        $numberOfLines = substr_count($value, "\n") + 2; // Add 2 because the last line won't have a newline character

        $readonly = '';
        $custom_validator = '';
        $helptext_line = $i18n->get("[[palette.autogrow_prefill]]");

        if ($access === "") {
            return $this->makeHiddenField($id, $value);
        }
        elseif ($access === "r") {
            // HTML safe
            $value = htmlspecialchars($value, ENT_COMPAT, 'UTF-8');

            // preserve line breaks
            //$value = preg_replace("/\r?\n/", "<BR>", $value);
            $optional_text = '';
            $optional_line = '';
            $readonly = 'readonly';
            $textarea = '<textarea name="' . $textarea_name . '" id="' . $textarea_name . '" class="form-control"' . ' ' . $readonly . '>' . $value . '</textarea>' . "\n";
        }
        elseif (($access === "rw") || ($access === "w")) {
            $validation_regexp = '';
            $val_test = bx_validation();
            if (isset($val_test[$this->type])) {
                $validation_regexp = 'pattern="' . $val_test[$this->type] . '"';
            }

            $data_error = '';
            $validation_errorMsg = $i18n->getHtml("[[palette.val_remote]]");
            if (isset($val_test['MESSAGES'][$this->type])) {
                $validation_errorMsg = $val_test['MESSAGES'][$this->type];
                $data_error = 'data-error="' . $validation_errorMsg . '"';
            }

            $textarea = '<textarea name="' . $textarea_name . '" id="' . $textarea_name . '" title="' . $helptext_line . '" class="form-control" placeholder="' . $helptext_line . '" ' . ' ' . $readonly . $optional_class . '>' . $value . '</textarea>' . "\n";

            if (isset($val_test[$this->type])) {
                $custom_validator = '
                    <!-- Start: /uifc2/FormFieldBuilder::makeTextAreaField(' . $id . ') -->
                    <script>
                        // JavaScript for custom input validation of field \'' . $id . '\' using \'' . $this->type . '\' regexp
                        document.getElementById(\'' . $id . '\').addEventListener(\'input\', function () {
                            var regex = /' . $val_test[$this->type] . '/;
                            var isValid = regex.test(this.value);
                            if (!isValid) {
                                this.setCustomValidity(\'' . $validation_errorMsg . '\');
                            }
                            else {
                                this.setCustomValidity(\'\');
                            }
                        });
                    </script>
                    <!-- End: /uifc2/FormFieldBuilder::makeTextAreaField(' . $id . ') -->
                    ' . "\n";
            }
        }

        $tooltip_out = $this->MakeTooltip($helptext, 'right');
        $label_position = $this->getLabelType();
        $is_required = '';

        $page->setExtraFooters('

                <!-- Start: /uifc2/FormFieldBuilder::makeTextAreaField(' . $id . ') -->
                <script language="Javascript">
                    document.addEventListener("DOMContentLoaded", function() {
                        var ' . $id . ' = document.getElementById("' . $id . '");
                        setTextareaHeight(' . $id . ', 2, 21); // Set initial height based on current content

                        // Attach event listener for dynamic resizing
                        ' . $id . '.addEventListener("input", function() {
                            setTextareaHeight(' . $id . ', 2, 21);
                        });
                    });
                </script>
                <!-- End: /uifc2/FormFieldBuilder::makeTextAreaField(' . $id . ') -->

            ');


        $out = '' . "\n";
        $out .=<<<HTML
                    <!-- Start: /uifc2/FormFieldBuilder::makeTextAreaField($id) -->
                    <div class="form-group pb-10">
                        <label for="$id" class="control-label mb-10 $label_position" $tooltip_out>$label</label>$optional_text
                        $textarea
                        <span class="glyphicon form-control-feedback pt-5" aria-hidden="true"></span>
                        <div class="help-block with-errors"></div>
                        $is_required
                    </div>
                    $custom_validator

        HTML;
        $out .= '            <!-- End: /uifc2/FormFieldBuilder::makeTextAreaField(' . $id . ') -->' . "\n";

        return $out;
    }

    // description: make a text list field
    // param: id: the identifier of the field
    // param: values: an array of values in string
    // param: access: "" for hidden, "r" for read-only, "w" for write-only and "rw" for read and write
    // param: $i18n: Parent objects i18n object
    // param: $type: type to validate against. As per Schema and /gui/validation
    // param: formId: the identifier of the form this field lives in
    // param: rows: the number of rows
    // param: columns: the number of columns
    // returns: HTML that represents the field
    function makeTextListField($page, $id, $values, $access, $i18n, $type = "", $isOptional = false, $formId = "", $rows = "1", $columns = "1") {
        $valueString = arrayToString($values);
        $this->i18n = $i18n;
        $this->type = $type;
        $this->isOptional = $isOptional;
        $result = "";

        switch ($access) {
            case "":
                return $this->makeHiddenField($id, $valueString);

            case "r":
                for ($i = 0;$i < count($values);$i++) {
                    if ($i > 0) {
                        $result .= "<BR>";
                    }
                    // HTML safe
                    $result .= htmlspecialchars($values[$i], ENT_COMPAT, 'UTF-8');
                }
                $result .= $this->makeHiddenField($id, $valueString);

                //
                $valueText = implode("\n", $values);
                $result = $this->makeTextAreaField($page, $id, $valueText, "r", $this->i18n, $this->type, $this->isOptional, $rows, $columns, "");
                //
                return $result;

            case "w":
                // clear off values
                $values = array();
            break;

            case "rw":
            break;
        }

        $valueText = implode("\n", $values);

        // make text area field
        $text = $this->makeTextAreaField($page, $id, $valueText, $access, $this->i18n, $this->type, $this->isOptional, $rows, $columns, "");

        // make hidden field
        $hidden = $this->makeHiddenField("textarea-" . $id, $valueString);

        $out = <<<HTML
            $hidden
            $text
            HTML;

        return $out;
    }

    // This is for the laaaaaazy way to get native HTML code into your pages.
    // It simply returns whatever "values" (your HTML code) you stuffed into it.
    // This is done via uifc2/RawHTML.php and by adding a FormField via something
    // like $factory->getRawHTML("applet", $applet)
    function makeRawHTMLField($id, $values) {
        return $values;
    }

    function makeHTMLField($id, $value, $i18n) {

        // If a Label and Description are set, we use them. If not, then we
        // calculate these based on the ID of the FormObject:
        if ((isset($this->Label)) && (strlen($this->Label) > "0")) {
            $label = $this->Label;
        }
        else {
            $label = $i18n->getHtml($id);
        }
        if ((isset($this->Description)) && (strlen($this->Description) > "0")) {
            $helptext = $this->Description;
        }
        else {
            $h = $id . '_help';
            $helptext = $i18n->getWrapped("[[$h]]");
        }
        $out = '';

        $poss_lables = array('label_side', 'nolabel', 'label_top', 'top');
        if (!in_array($this->getLabelType(), $poss_lables)) {
            $this->setLabelType($this->getLabelType() . ' label_side');
        }

        $out_label = '';
        if ($this->getLabelType() != 'nolabel') {
            $label_position = $this->getLabelType();
            $optional_text = '';
            $tooltip_out = $this->MakeTooltip($helptext, 'right');
            $required_tt = $this->MakeTooltip(get_i18n_error_for_inputvalidation('required', $i18n), 'left');
            $out_label =<<<HTML
                        <label for="$id" class="control-label mb-10 $label_position" $tooltip_out>$label</label>&nbsp;<span class="text-muted">$optional_text</span>
            HTML;
        }

        $out = '' . "\n";
        $out .=<<<HTML
                    <!-- Start: /uifc2/FormFieldBuilder::makeHTMLField($id)  -->
                    <div class="form-group pb-20">
                        $out_label
                        <p>$value</p>
                    </div>
        HTML;
        $out .= '<!-- End: ' . "/uifc2/FormFieldBuilder::makeHTMLField($id)" . ' -->' . "\n";

        return $out;
    }
}

/*
Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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
