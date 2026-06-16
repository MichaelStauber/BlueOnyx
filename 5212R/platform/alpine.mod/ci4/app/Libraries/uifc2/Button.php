<?php

include_once "uifc2/HtmlComponent.php";

class Button extends HtmlComponent {

    public $page;
    private $type;
    private $action;
    private $label;
    private $labelDisabled;
    private $thisDemoOverride;
    private $isDemo;
    private $ButtonLabel;
    private $ButtonDescription;
    private $i18n;
    private $langs;
    private $tooltip = '';
    private $tooltip_placement = 'top';
    private $Icon;
    private $customOnClick = '';
    private $isDisabled = false;
    private $waiter;
    private $ImageOnly = false;

    private $class = array('btn');
    private $linkButton = true;
    private $linkType = 'external'; // Default link type

    private $buttonSize;
    private $buttonColor;
    private $buttonGeneralStyle;
    private $buttonDisabled;
    private $is_Submit_Button = false;
    private $button_extra_class;
    private $button_is_linkbutton;
    private $buttonSpecialStyle;
    private $targetFrame;
    private $TextOnly;
    private $modal;

    public function __construct(&$page, $action, $type, &$label, $labelDisabled = "", $thisDemoOverride = false) {
        parent::__construct($page);

        $this->page = $page;
        $this->action = $action;

        // Consistency check:
        if ($type instanceof Label) {
            // Type had not been set and is really our Label!
            // Push relevant construct variables left:
            $this->type = '';
            if (($labelDisabled === TRUE) || ($labelDisabled === FALSE)) {
                $thisDemoOverride = $labelDisabled;
            }
            if (!$label) {
                $labelDisabled = '';
            }
            $this->setLabel($type, $labelDisabled);
        }
        else {
            $this->type = $type;
            $this->setLabel($label, $labelDisabled);
        }

        // Set defaults:
        $this->setDisabled(false);
        $this->setDemo(is_file("/etc/DEMO") && $thisDemoOverride !== "DEMO-OVERRIDE");

        $this->i18n = $page->getI18n();
        $this->langs = $this->i18n->getLocales();

        if ($label instanceof ImageLabel) {
            // $label is an instance of ImageLabel
            $this->ButtonLabel = $label->label;
            $this->ButtonDescription = $this->i18n->get("detail_help", "palette");
        }

        // Set our Label and Description in cleartext, too:
        if (isset($label->page->Label)) {
            $this->ButtonLabel = $label->page->Label['label'];
            $this->ButtonDescription = $label->page->Label['description'];

            // We have no ButtonDescription, so we use the ButtonLabel as description:
            if ($this->ButtonDescription === $this->ButtonLabel . '_help') {
                $this->ButtonDescription = $this->ButtonLabel;
            } 
        }

        // Waiting overlay:
        $this->waiter = $this->getWaiter();

        // Get Modal:
        $this->modal = $this->getModal();
    }

    public function setImageOnly($imageOnly) {
        $this->ImageOnly = $imageOnly;
    }

    public function setButtonDisabled($btn_state) {
        $possibleButtonEnablementStates = [TRUE => 'disabled', FALSE => '' ];
        $this->buttonDisabled = $possibleButtonEnablementStates[$btn_state] ?? '';
    }

    public function getButtonDisabled() {
        return $this->buttonDisabled;
    }

    // Get the status of the waiting overlay:
    public function getWaiter() {
        return $this->waiter;
    }

    // Set status of waiting overlay
    public function setWaiter($waiter) {
        if (($waiter === TRUE) || ($waiter === FALSE)) {
            $this->waiter = $waiter;
        }
    }

    public function setSubmit($is_submit) {
        $this->is_Submit_Button = false;
        if ($is_submit == true) {
            $this->is_Submit_Button = $is_submit;
        }
    }

    public function getSubmit() {
        return $this->is_Submit_Button;
    }

    // description: get the action to perform when the button is pressed
    public function getAction() {
        return $this->action;
    }

    // description: set the action to perform when the button is pressed
    public function setAction($action) {
        $this->action = $action;
    }

    public function addButtonClass($class) {
        if ($class != '') {
            $this->class[] = $class;
        }
    }

    // description: replace a class in the button classes array:
    public function replaceButtonClass($find, $replace) {
        if (in_array($find, $this->class)) {
            $key = array_search($find, $this->class);
            $this->class[$key] = $replace;
        }
    }

    public function getButtonClasses() {
        $out_classes = implode(' ', $this->class);
        return $out_classes;
    }

    public function setButtonSpecialStyle($var) {
        $possibleButtonSpecialStyles = ['default' => '', 'icon_left' => 'btn-icon left-icon', 'icon_right' => 'btn-icon right-icon', 'block' => 'btn-block', 'animated' => 'btn-anim', 'square_animated' => 'btn-icon-anim btn-square' ];

        if (isset($possibleButtonSpecialStyles[$var])) {
            $this->addButtonClass($possibleButtonSpecialStyles[$var]);
            $this->buttonSpecialStyle = $possibleButtonSpecialStyles[$var];
        }
    }

    public function getButtonSpecialStyle() {
        return $this->buttonSpecialStyle;
    }

    public function setButtonGeneralStyle($gen_style) {
        $possibleButtonGeneralStyles = ['default' => '', 'rounded' => 'btn-rounded', 'outline' => 'btn-outline', 'outline-rounded' => 'btn-outline btn-rounded' ];
        if (isset($possibleButtonGeneralStyles[$gen_style])) {
            $this->addButtonClass($possibleButtonGeneralStyles[$gen_style]);
            $this->buttonSpecialStyle = $possibleButtonGeneralStyles[$gen_style];
        }
    }

    public function getButtonGeneralStyle() {
        return $this->buttonGeneralStyle;
    }

    public function setButtonColor($color) {
        $possibleButtonColors = ['default' => 'btn-default', 'info' => 'btn-info', 'primary' => 'btn-primary', 'success' => 'btn-success', 'danger' => 'btn-danger', 'warning' => 'btn-warning' ];
        if (isset($possibleButtonColors[$color])) {
            $this->addButtonClass($possibleButtonColors[$color]);
            $this->buttonColor = $possibleButtonColors[$color];
        }
        else {
            $this->addButtonClass('btn-default');
            $this->buttonColor = 'btn-default';
        }
    }

    public function getButtonColor() {
        if (!isset($this->buttonColor)) {
            $this->buttonColor = 'btn-default';
        }
        return $this->buttonColor;
    }

    // Yeah, this is a typo. But we keep it for compat:
    public function setButtonSite($size) {
        $this->setButtonSize($size);
    }

    // The real deal w/o typo:
    public function setButtonSize($size) {
        $possibleButtonSizes = ['default' => '', 'large' => 'btn-lg', 'small' => 'btn-sm', 'xs' => 'btn-xs', 'xxs' => 'btn-xxs' ];
        if (isset($possibleButtonSizes[$size])) {
            $this->addButtonClass($possibleButtonSizes[$size]);
            $this->buttonSize = $size;
        }
    }

    // description: set the target frame of the action
    public function setTarget($target) {
        $this->targetFrame = $target;
    }

    // description: get the target frame of the action
    public function getTarget() {
        return isset($this->targetFrame) ? $this->targetFrame : "_self";
    }

    // Demo mode:
    // description: see if the button is disabled
    public function isDemo() {
        return $this->isDemo;
    }

    // description: set the disabled flag
    public function setDemo($isDemo) {
        $this->isDemo = $isDemo;
    }

    // description: see if the button is disabled
    public function isDisabled() {
        return $this->isDisabled;
    }

    // description: set the disabled flag
    public function setDisabled($isDisabled) {
        $this->isDisabled = $isDisabled;
    }

    public function setTextOnly($txt) {
        $this->TextOnly = $txt;
    }

    public function getTextOnly() {
        return isset($this->TextOnly) ? $this->TextOnly : false;
    }

    public function setIcon($icon) {
        $this->Icon = $icon;
    }

    public function setOnClick($javascript) {
        $this->customOnClick = $javascript;
    }

    public function getOnClick() {
        return $this->customOnClick;
    }

    public function getIcon() {
        return isset($this->Icon) ? $this->Icon : null;
    }

    public function setDescription($desc) {
        $this->ButtonDescription = $desc;
    }

    public function setModal($modalName='dialog', $modal_url='javascript:void(0);') {
        $this->modal = ' data-url="' . $modal_url . '" data-modal-id="' . $modalName . '"';

        // Special case: If we use modals, we need to activate tooltips for them manually (once per page):
        //$this->page->setExtraFooters('
        //        <script>
        //            // Activate tooltips for modals:
        //            $(\'[data-toggle="modal"]\').tooltip();
        //        </script>
        //        ');
    }

    public function getModal() {
        return $this->modal;
    }

    public function setTooltip($tooltip) {
        $this->tooltip = $tooltip;
    }

    public function setTooltipPlacement($tooltip_placement) {
        $this->tooltip_placement = $tooltip_placement;
    }

    public function MakeTooltip($description, $tooltip_placement = 'top') {
        $this->tooltip = 'data-placement="' . $this->tooltip_placement . '" title="' . $this->ButtonDescription . '" data-original-title="' . $this->ButtonDescription . '" data-container="body" ' . $this->modal;
    }

    public function setLinkButton($linkType = 'external') {
        $this->linkButton = true;
        $this->linkType = $linkType;
    }

    // description: set the label for the button
    public function setLabel(&$label, $labelDisabled = "") {
        $this->label = $label;
        $this->labelDisabled = $labelDisabled !== "" ? $labelDisabled : $label;
    }

    // description: get the label for normal state of the button
    public function &getLabel() {
        return $this->label;
    }

    // description: get the label for disabled state of the button
    public function &getLabelDisabled() {
        return $this->labelDisabled;
    }

    public function getLinkButton() {
        return $this->linkButton ? $this->linkType : false;
    }

    public function toHtml($style = "") {

        if ($this->type == "save") {
            if ($this->getIcon() == null) {
                $this->Icon = "icon-rocket";
            }

            // Is a Submit-Button:
            $this->setSubmit(TRUE);

            // Is animated:
            $this->setButtonSpecialStyle('animated');

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('success');
            }

        }
        elseif ($this->type == "cancel") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-times";
            }

            // Is a Submit-Button:
            $this->setSubmit(FALSE);

            // Is animated:
            $this->setButtonSpecialStyle('animated');

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('danger');
            }

            // Cancel buttons are usually on the right:
            $this->addButtonClass('pull-right');

            $this->setLinkButton(TRUE);
        }
        elseif ($this->type == "add") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-plus-square";
            }

            // Is a Submit-Button:
            $this->setSubmit(FALSE);

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            $this->setLinkButton(TRUE);
        }
        elseif ($this->type == "urlbutton") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-external-link";
            }

            // Is a Submit-Button:
            $this->setSubmit(FALSE);

            // Is animated:
            $this->setButtonSpecialStyle('square_animated');

            // Default to open in new tab:
            $this->setTarget('_blank');

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            $this->setLinkButton(TRUE);

            $this->setImageOnly(TRUE);
        }
        elseif ($this->type == "fancybutton") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-search";
            }

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            // Is animated:
            $this->setButtonSpecialStyle('square_animated');

            // Is not a Submit-Button:
            $this->setSubmit(FALSE);

            $this->setLinkButton(TRUE);

            $this->setImageOnly(TRUE);

            // Uses Fancybox:
            $this->addButtonClass('fancybox');

            if (!$this->isDisabled) {
                $this->page->setExtraHeaders('
                    <script>
                        function openFancybox(url) {
                            $.fancybox.open({
                                href: url,
                                type: \'iframe\',
                                padding: 5,
                                width: \'80%\',
                                height: \'80%\',
                                autoSize: false,
                                scrolling: \'auto\',
                                iframe: {
                                    preload: true
                                }
                            });
                        }
                    </script>'
                );
            }
        }
        elseif ($this->type == "fancytextbutton") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-search";
            }

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            // Style:
            $this->setButtonSpecialStyle('btn-default btn-icon left-icon');

            // Is not a Submit-Button:
            $this->setSubmit(FALSE);

            $this->setLinkButton(TRUE);

            // Uses Fancybox:
            $this->addButtonClass('fancybox');

            if (!$this->isDisabled) {
                $this->page->setExtraHeaders('
                    <script>
                        function openFancybox(url) {
                            $.fancybox.open({
                                href: url,
                                type: \'iframe\',
                                padding: 5,
                                width: \'80%\',
                                height: \'80%\',
                                autoSize: false,
                                scrolling: \'auto\',
                                iframe: {
                                    preload: true
                                }
                            });
                        }
                    </script>'
                );
            }
        }
        elseif ($this->type == "linkbutton") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-share";
            }

            // Is a Submit-Button:
            $this->setSubmit(FALSE);

            // Is animated:
            $this->setButtonSpecialStyle('square_animated');

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            $this->setLinkButton(TRUE);

            $this->setImageOnly(TRUE);
        }
        elseif ($this->type == "back") {
            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
                $this->Icon = "fa fa-arrow-left";
            }
        }
        elseif ($this->type == "modify") {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-edit";
            }

            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

        }
        elseif ($this->type == "uninstall") {
            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }
        }
        elseif ($this->type == "detail") {
            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }
        }
        elseif ($this->type == "remove") {
            // ButtonColor:
            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }
        }
        elseif ($this->type == "featurebutton") {
            if ($this->getIcon() == null) {
                $this->Icon = '';
            }

            if (!$this->buttonColor) {
                $this->setButtonColor('default');
            }

            // Wipe $this->class and replace it with this special button's classes:
            // $this->class = ['adminica-button', 'tiny', 'text_only', 'has_text', 'hover'];
            $this->class = ['btn', $this->getButtonColor(), 'btn-xxs', 'ma-5'];

        }
        else {
            if ($this->getIcon() == null) {
                $this->Icon = "fa fa-pencil";
            }

            if (!$this->buttonColor) {
                $this->setButtonColor('primary');
            }

            // Is animated:
            //$this->setButtonSpecialStyle('square_animated');

            // Is not a Submit-Button:
            $this->setSubmit(FALSE);

            $this->setLinkButton(TRUE);
        }

        if (($this->buttonSize === 'small') && ($this->buttonSpecialStyle === 'btn-icon-anim btn-square')) {
            $this->replaceButtonClass('btn-icon-anim btn-square', 'btn-icon-anim btn-square-mini');
        }
        elseif ((($this->buttonSize === 'xs') || ($this->buttonSize === 'xxs')) && ($this->buttonSpecialStyle === 'btn-icon-anim btn-square')) {
            $this->replaceButtonClass('btn-icon-anim btn-square', 'btn-icon-anim');
        }

        // Tooltip:
        $this->MakeTooltip($this->ButtonDescription, $this->tooltip_placement);

        // Render the button:
        if ($this->linkButton) {
            // print_rp("Is linkButton");
            return $this->renderLinkButton();
        }
        else {
            // print_rp("Is NOT a linkButton");
            return $this->renderRegularButton();
        }
    }

    private function renderRegularButton() {

        // print_rp("Using renderRegularButton for " . $this->ButtonLabel);
        
        $buttonType = 'type="button"';
        if ($this->is_Submit_Button === true) {
            $buttonType = 'type="submit"';
        }
        $action = $this->action ? 'onclick="window.location=\'' . $this->action . '\';"' : '';
        if ($this->getOnClick() !== '') {
            $action = 'onclick="' . htmlspecialchars($this->getOnClick(), ENT_QUOTES, 'UTF-8') . '"';
        }

        $button_id = '';
        if ($this->type == "save") {
            $button_id = ' id="SaveButton"';

            $this->page->setExtraHeaders('
                <script type="text/javascript">
                jQuery(function ($) {

                  var $saveBtn = $(\'#SaveButton\');
                  var $saving  = $(\'#bxSavingNav\');

                  $saving.hide();

                  // Show as soon as user initiates save
                  $saveBtn.on(\'mousedown keydown\', function (e) {
                    if (e.type === \'mousedown\' || e.key === \'Enter\' || e.key === \' \') {
                      $saving.show();
                    }
                  });

                  // If validation blocks submit, hide again
                  $saveBtn.on(\'click\', function () {
                    setTimeout(function () {
                      if (!$saveBtn.prop(\'disabled\')) {
                        $saving.hide();
                      }
                    }, 300);
                  });

                  // If submit happens, lock UI (page will navigate anyway)
                  $saveBtn.closest(\'form\').on(\'submit\', function () {
                    $saving.show();
                    $saveBtn.prop(\'disabled\', true).addClass(\'disabled\');
                  });

                });
                </script>');
        }

        $iconLine = '';
        $icon_text_spacer = '';
        if ($this->ImageOnly === FALSE) {
            $icon_text_spacer = ' mr-10';
        }

        if ($this->getIcon()) {
            $iconLine = '<i class="' . $this->getIcon() . $icon_text_spacer . '"></i>';
            $labelLine = '<span class="btn-text">' . $this->ButtonLabel . '</span>';
        }
        else {
            $labelLine = '<span>' . $this->ButtonLabel . '</span>';
        }

        if ($this->ImageOnly === TRUE) {
            $labelLine = '';
        }

        if ($this->getTextOnly() === TRUE) {
            $iconLine = '';
        }

        $out_button = '<!-- RB: ' . $this->type . ' -->' . "\n";
        $out_button .= '<button ' . $buttonType . $button_id . ' class="' . $this->getButtonClasses() . ' ma-5" ' . $action . ' ' . $this->tooltip . '>' . $iconLine . $labelLine . '</button>';
        $out_button .= '<!-- /RB: ' . $this->type . ' -->' . "\n";
    }

    private function renderLinkButton() {

        $possibleTargets = ['' => '', '_blank' => '_blank', '_top' => '_top', '_self' => '_self', 'new_window' => 'new_window', 'copyToClipboard' => 'copyToClipboard', 'window.top.location.href' => 'window.top.location.href' ];
        $target = $possibleTargets[$this->getTarget()] ?? '_self';

        $button_id = '';
        if ($this->type == "save") {
            $button_id = ' id="SaveButton"';
        }

        $URL = $this->getAction();
        if ($URL === '') {
            $URL = 'javascript:void(0);';
        }

        $data_link = '';
        $targetLine = '';
        $onClick_event = '';
        if ($target === 'new_window') {
            $onClick_event = ' onclick="window.open(\'' . $URL . '\',\'child\',\'scrollbars,width=800,height=600\'); return false"';
        }
        elseif ($target === 'copyToClipboard') {
            $onClick_event = ' onclick="copyToClipboard(\'' . $URL . '\')"';
        }
        elseif ($target === 'window.top.location.href') {
            $onClick_event = ' onclick="window.top.location.href = \'' . $URL . '\'"';
        }
        else {
            $onClick_event = ' onclick="openUrl(\'' . $URL . '\', \'' . $target . '\')"';
        }

        if ($this->getOnClick() !== '') {
            $onClick_event = ' onclick="' . htmlspecialchars($this->getOnClick(), ENT_QUOTES, 'UTF-8') . '"';
        }

        $buttonType = 'type="button"';
        if ($this->is_Submit_Button === true) {
            $buttonType = 'type="submit"';
        }

        // DEBUG: May not be empty!
        $method = '';

        if (($this->type === "fancybutton") || ($this->type === "fancytextbutton")) {
            $targetLine = '';
            $onClick_event = ' onclick="openFancybox(\'' . $URL . '\')"';
        }

        if ($this->isDemo() == true) {
            $btn_disabled = ' disabled="disabled"';
            $demoLabel = ' (' . $this->i18n->get("[[palette.demo_mode_short]]") . ')';
        }
        else {
            $btn_disabled = '';
            $demoLabel = '';
        }

        $iconLine = '';
        $icon_text_spacer = '';

        if ($this->ImageOnly === FALSE) {
            $icon_text_spacer = ' mr-10';
        }

        if ($this->getIcon()) {
            $iconLine = '<i class="' . $this->getIcon() . $icon_text_spacer . '"></i>';
            $labelLine = '<span class="btn-text">' . $this->ButtonLabel . '</span>';
        }
        elseif ($this->type === 'featurebutton') {
            $labelLine = '<span class="btn-txt" style="font-size: 10px;">' . $this->ButtonLabel . '</span>';
        }
        else {
            $labelLine = '<span class="btn-text">' . $this->ButtonLabel . '</span>';
        }

        if (($this->ImageOnly === TRUE) || ($this->getButtonSpecialStyle() === 'btn-icon-anim btn-square')) {
            $labelLine = '';
        }

        if ($this->getTextOnly() === TRUE) {
            $iconLine = '';
        }

        if ($this->waiter) {
            $onClick_event = ' onclick="waitOverlay(\'' . $this->getAction() . '\', \'' . '_self' . '\')"';
        }

        if ($this->type == "save") {
            $button_id = ' id="SaveButton"';

            $this->page->setExtraHeaders('
                <script type="text/javascript">
                jQuery(function ($) {

                  var $saveBtn = $(\'#SaveButton\');
                  var $saving  = $(\'#bxSavingNav\');

                  $saving.hide();

                  // Show as soon as user initiates save
                  $saveBtn.on(\'mousedown keydown\', function (e) {
                    if (e.type === \'mousedown\' || e.key === \'Enter\' || e.key === \' \') {
                      $saving.show();
                    }
                  });

                  // If validation blocks submit, hide again
                  $saveBtn.on(\'click\', function () {
                    setTimeout(function () {
                      if (!$saveBtn.prop(\'disabled\')) {
                        $saving.hide();
                      }
                    }, 300);
                  });

                  // If submit happens, lock UI (page will navigate anyway)
                  $saveBtn.closest(\'form\').on(\'submit\', function () {
                    $saving.show();
                    $saveBtn.prop(\'disabled\', true).addClass(\'disabled\');
                  });

                });
                </script>');
        }

        if ($this->modal != null) {
            $onClick_event = '';
        }

        $out_button = '<!-- LB: ' . $this->type . ' -->' . "\n";
        $out_button .= "\n" . '                                        <button ' . $buttonType . $button_id . ' class="' . $this->getButtonClasses() . ' ma-5" ' . $this->tooltip . ' ' . $btn_disabled . $method . $data_link . $targetLine . $onClick_event . '>
                                            ' . $iconLine . $labelLine . '
                                        </button>' . "\n";
        $out_button .= '<!-- /LB: ' . $this->type . ' -->' . "\n";
        return $out_button;

    }
}

//    // Example usage:
//    $regularButton = new Button('Regular Button');
//    $regularButton->setAction('https://www.example.com');
//    echo $regularButton->render();
//    
//    $submitButton = new Button('Submit Button', 'btn btn-primary', 'submit');
//    $submitButton->setAction('/submit-form');
//    echo $submitButton->render();
//    
//    $linkButton = new Button('Visit Google');
//    $linkButton->setLinkButton('external');
//    $linkButton->setAction('https://www.google.com');
//    echo $linkButton->render();
//    
//    $internalLinkButton = new Button('Internal Link');
//    $internalLinkButton->setLinkButton('internal');
//    $internalLinkButton->setAction('/internal-page');
//    echo $internalLinkButton->render();
//    
//    $newTabButton = new Button('New Tab Link');
//    $newTabButton->setLinkButton('new_tab');
//    $newTabButton->setAction('https://www.example.com');
//    echo $newTabButton->render();
// 

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
