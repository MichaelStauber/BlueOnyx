<?php
// Author: Kevin K.M. Chiu
// $Id: PagedBlock.php
// description:
// PagedBlock represents a block that have multiple pages with each of them
// having their own form fields. The states of form fields on different pages
// are automagically maintained.
//
// applicability:
// Use this class to separate functionally cohesive, but context distant
// information. For example, use it to group "basic" information into one page
// and "advanced" information in another. Do not use this class simply for
// navigation purposes, use the navigation system instead.
//
// usage:
// To use this class for just one page, simply create a PagedBlock object and
// add form fields without specifying any page IDs. To support multiple pages,
// after constructing an object, add pages to it. Afterwards, add form fields
// to the pages. The page to display can be selected by using setSelectedId(),
// but this is optional. The page to display is maintained automagically based
// on user interaction. Changed form field values are passed back to the pages
// as $formFieldId. After submission, $pageId for visited pages are set to
// true. Use getStartMark() and getEndMark() to put HTML code outside the
// scope of PHP into the context of pages.
//
// Notes by Michael Stauber:
//
// This class has been ported to BlueOnyx 5200R and now uses jQuery and CSS for
// styling instead of JavaScript and plain HTML. This is one of the more complex
// classes for GUI elements and is used extensively throughout the GUI. The
// complexity and the nature of the changes actually would warrant a complete
// rewrite from scratch, as there are now many dormant routines, variables and
// arrays in this mess. In fact in porting this to the "new" BlueOnyx I
// deliberately broke some of the original functionality. I hope to clean this up
// in the longer run, but if there are things in here which don't work or don't
// make sense to you, then that's my fault.
global $isPagedBlockDefined;
if ($isPagedBlockDefined) return;
$isPagedBlockDefined = true;

include_once ("uifc2/FormFieldBuilder.php");
include_once ("uifc2/Block.php");

class PagedBlock extends Block {
    //
    // private variables
    //
    var $BxPage;
    var $dividers;
    var $dividerIndexes;
    var $dividerPageIds;
    var $formFields;
    var $formFieldLabels;
    var $formFieldPageIds;
    var $formFieldErrors;
    var $generalErrors;
    var $pageIds;
    var $pageLabels;
    var $selectedId;
    var $defaultPage;
    var $columnWidths;
    var $hideEmptyPages;
    var $Label;
    var $noErrorDisplay;
    var $FormDisabled;
    var $DivHeight;
    var $BlueOnyxHeader;
    var $elements;
    var $id;
    var $sideTabs;

    var $request;

    //
    // public methods
    //

    public function processRequestData($var = '') {
        // Access specific variable from both GET and POST as well as cookies
        if ($var === '') {
            // Return all
            $requestData = $this->request->getVar();
        }
        else {
            // Return requested $var:
            $requestData = $this->request->getVar($var);
        }
        return $requestData;
    }

    // description: constructor
    // param: page: the Page object this block is in
    // param: id: an unique ID of the block in string
    // param: label: a Label object for the block title. Optional
    //public function PagedBlock($page, $id, $label = "", $selectedId = "") {
    public function __construct($page, $id, $label = "", $selectedId = "") {
        // superclass constructor
        //$this->Block($page, $label);
        parent::__construct($page, $label);

        $this->BxPage = $page;

        $this->setId($id);

        $this->setDefaultPage();

        $this->sideTabs = false;

        $this->Label = $label;

        // Access the global instance of IncomingRequest
        $this->request = service('request');

        // PagedBlock has a built in area where errror messages are shown.
        // We inform BxPage of this, so it won't show the errors separately:
        if ($this->getDisplayErrors() == true) {
            $this->BxPage->HaveErrorMsgDisplayArea(true);
        }

        // set selected ID from internal variable
        $variableName = "_PagedBlock_selectedId_$id";
        $this->setSelectedId($selectedId);

        $this->dividers = array();
        $this->dividerIndexes = array();
        $this->dividerPageIds = array();
        $this->formFields = array();
        $this->formFieldLabels = array();
        $this->formFieldPageIds = array();
        $this->formFieldErrors = array();
        $this->generalErrors = array();
        $this->pageIds = array();
        $this->pageLabels = array();
        $this->hideEmptyPages = array();
        $this->setColumnWidths();

        $this->BlueOnyxHeader = FALSE;
        $this->FormDisabled = FALSE;

        // Toggle elements:
        $this->elements = array();

    }

    // Disables inline display of error messages:
    function setDisplayErrors($errorOnOff) {
        $this->noErrorDisplay = $errorOnOff;
    }

    // Returns status of inline display of error messages:
    function getDisplayErrors() {
        if (!isset($this->noErrorDisplay)) {
            $this->noErrorDisplay = true;
        }
        return $this->noErrorDisplay;
    }

    // Sets the current label
    function setCurrentLabel($label) {
        $this->Label = $label;
    }

    // Returns the current label
    function getCurrentLabel() {
        if (!isset($this->Label)) {
            $this->Label = $id;
        }
        return $this->Label;
    }

    // The next couple of functions define if a grabber, toggle or "open in new window" icon are visible in the header:
    public function setGrabber($grabber) {
        $this->elements["grabber"] = $grabber;
    }

    public function setToggle($toggle) {
        $this->elements["toggle"] = $toggle;
    }

    public function setSelf($toggle) {
        $this->elements["self"] = $toggle;
    }

    public function setWindow($window) {
        $this->elements["window"] = $window;
    }

    public function setMaximize($window) {
        $this->elements["maximize"] = $window;
    }

    public function setFormDisabled($formDis) {
        $this->FormDisabled = $formDis;
    }

    public function getFormDisabled() {
        return $this->FormDisabled;
    }

    public function setBlueOnyxHeader($enabled = FALSE) {
        if (($enabled === TRUE) || ($enabled === FALSE)) {
            $this->BlueOnyxHeader = $enabled;
        }
    }

    public function setShowAllTabs($show_all_tabs) {
        $this->elements["show_all_tabs"] = $show_all_tabs;
        // Also set this so that we can re-use existing GUI page setShowAllTabs()
        // (defunct in Elmer!) to allow to blow up this element:
        $this->elements["maximize"] = $show_all_tabs;
    }

    // sideTabs (if set to TRUE) shows the tabbing of a PagedBlock on the lefthand side instead of on top.
    // However: Due to stylistic reasons it is only good for displaying info. NOT FOR FORM DATA!
    // It removes both the heading line with the grabber, toggle, setShowAllTabs and "open in new window"
    // AND will remove any buttons that you might have added. So use this with caution and only on pages
    // where you display informational data without the need to submit something back!
    //
    // But as a neat side effect you can also use misuse it: Try to use a single tab with a single
    // formField and setSideTabs(TRUE). It will show a stand alone FormField nicely formatted. I promise
    // that was purely by accident! ;-)
    public function setSideTabs($tabs) {
        $this->sideTabs = $tabs;
    }

    // Sets the current DivHeight:
    function setDivHeight($height) {
        $this->DivHeight = $height;
    }

    // Returns the DivHeight:
    function getDivHeight() {
        if (!isset($this->DivHeight)) {
            $this->DivHeight = '0';
        }
        return $this->DivHeight;
    }

    // description: get the mark for marking the end of a HTML section
    //     specifically for a page. This is useful for adding page specific HTML
    // param: pageId: the ID of the page in string
    // returns: the mark in string
    // see: getStartMark()
    public function getEndMark($pageId) {
        $selectedId = $this->getSelectedId();
        if ($pageId == $selectedId) return "";
        else return " -->";
    }

    // description: get all the form fields of the block
    // returns: an array of FormField objects
    // see: addFormField()
    public function &getFormFields() {
        return $this->formFields;
    }

    // description: add a form field to this block
    // param: formField: a FormField object
    // param: label: a label object. Optional
    //     hidden form fields are not shown and therefore do not need labels
    // param: pageId: the ID of the page the form field is in
    //     Optional if there is only one page
    // see: getFormFields()
    public function addFormField(&$formField, $label = "", $pageId = "", $errormsg = false) {
        $this->formFields[] = $formField;
        $this->formFieldLabels[$formField->getId() ] = $label;

        // Pass the information from the LabelObject's labels to the class BxPage() so that it can store them for us
        // until we need to pull that information:
        if (isset($label->page->Label)) {
            if (isset($label->page->BXLabel[$formField->getId() ])) {
                $lbl = array_keys($label->page->BXLabel[$formField->getId() ]);
                $dsc = array_values($label->page->BXLabel[$formField->getId() ]);
                $this->BxPage->setLabel($formField->getId() , $lbl[0], $dsc[0]);
            }
            else {
                $this->BxPage->setLabel($formField->getId() , $label->page->Label['label'], $label->page->Label['description']);
            }
        }
        else {
            $this->BxPage->setLabel($formField->getId() , "", "");
        }

        if ($pageId == "") {
            $pageId = $this->defaultPage;
        }
        $this->formFieldPageIds[$formField->getId() ] = $pageId;
        if ($errormsg) {
            $this->formFieldErrors[$formField->getId() ] = new Error($errormsg);
        }
    }

    public function setDefaultPage($id = "") {
        // There are cases where we want to go directly to a specific tab.
        // We can do so and can use this override called the setDefaultPage() function. 
        //
        // Otherwise this function checks the form POST and session data first to determine which
        // tab someone hit Submit on. And we then set this tab to active again.
        //
        // If we do not like that, then we can override this via GET variables like this:
        // /swupdate/news?DetailedTab=tabs-2 - which would make the tab with the ID 'tabs-2'
        // active instead.
        //
        // OR: We can call setDefaultPage('My Third Tab') for example (give it the NAME, not the ID
        // of the tab!) to specifically set the desired tab instead. That overrides Session, POST and
        // GET data and allows us to hardcode this in the GUI page.
        //

        // Get POST request:
        $this->request = service('request');
        $form_post = $this->request->getPost();
        $form_get = $this->request->getGet();

        // Do we have the PagedBlock info in session data?
        if (session()->get('PagedBlock')) {
            $session_PagedBlock = session()->get('PagedBlock');
            if ((isset($session_PagedBlock['active_tab'])) && (isset($form_post['SelectedTab']))) {
                // Set $this->defaultPage to the tab that we hit Submit on:
                $this->defaultPage = $form_post['SelectedTab'];
            }
        }

        if ((isset($form_get['DetailedTab'])) && (isset($session_PagedBlock['pageIds'][$form_get['DetailedTab']]))) {
            $this->defaultPage = $session_PagedBlock['pageIds'][$form_get['DetailedTab']];
        }

        if ((!isset($this->defaultPage)) && ($id != '')) {
            $this->defaultPage = $id;
        }
    }

    public function getDefaultPage() {

        // Get POST request:
        $this->request = service('request');
        $form_get = $this->request->getGet();
        $pageIds = $this->getPageIds();

        $known_PagedBlocks = $this->BxPage->getPagedBlock();
        $num_PagedBlocks = count($known_PagedBlocks);

        if (isset($form_get['DetailedTab'])) {
            $pnum = '1';
            $pids = [];
            foreach ($pageIds as $key => $id) {
                if ($num_PagedBlocks === 1) {
                    $newkey = 'tabs-' . $pnum;
                }
                else {
                    $newkey = 'tabs-' . $id . '-' . $pnum;
                }
                $pids[$newkey] = $id;
                $pnum++;
            }

            if (isset($pids[$form_get['DetailedTab']])) {
                // Set $this->defaultPage to the tab from our URL params:
                $this->defaultPage = $pids[$form_get['DetailedTab']];
            }
        }
        return $this->defaultPage;
    }

    public function setFormFieldError($id, &$error) {
        $this->formFieldErrors[$id] = $error;
    }

    // for backward compatibility, plus I don't feel like
    // finding every call to process_errors tonight
    public function process_errors(&$errors, $mapping = array()) {
        $this->processErrors($errors, $mapping);
    }

    // given an array of error objects, sorts them out for later display.
    public function processErrors($errors, $mapping = array()) {
        /* reset the general errors ! */
        $this->generalErrors = array();
        for ($i = 0;$i < count($errors);$i++) {
            $error = $errors[$i];
            if (!$error) {
                continue;
            }
            if (method_exists($error, 'getKey')) {
                $key = $error->getKey();
            }
            // remap schema attribute name to localized field name:
            if ($key && $mapping[$key]) {
                $key = $mapping[$key];
            }
            if (false && $key) {
                // if ( $error->getKey() && !preg_match("/^\[\[/", $error->vars["key"]))
                $this->setFormFieldError($key, $error);
            }
            else {
                $this->generalErrors[] = $error;
            }
        }
    }

    // description: get all dividers added to the block
    // returns: an array of Label objects
    // see: addDivider()
    public function getDividers() {
        return $this->dividers;
    }

    // description: add a divider
    // param: label: a label object. Optional
    // param: pageId: the ID of the page the form field is in
    //     Optional if there is only one page
    // see: getDividers()
    public function addDivider($label = "", $pageId = "") {

        $this->dividers[] = $label;
        $this->dividerPageIds[] = $pageId;

        // find the number of form fields before the divider on the page
        $formFieldsBefore = 0;
        $formFields = $this->getFormFields();
        for ($i = 0;$i < count($formFields);$i++) {
            $formFieldPageId = $this->getFormFieldPageId($formFields[$i]);
            if ($formFieldPageId == $pageId) $formFieldsBefore++;
        }

        $this->dividerIndexes[] = $formFieldsBefore;
    }

    // description: get the label for a form field
    // param: formField: a FormField object
    // returns: a Label object
    // see: addFormField()
    public function getFormFieldLabel($formField) {
        return $this->formFieldLabels[$formField->getId() ];
    }

    // getFormFieldError: get the error message (if any) associated
    // with a form field.
    public function getFormFieldError($formField) {
        $page = $this->getPage();
        $i18n = $page->getI18n();
        if (isset($this->formFieldErrors[$formField->getId() ])) {
            $tmperr = $this->formFieldErrors[$formField->getId() ];
        }
        else {
            $nix = "";
            return $nix;
        }
        return $i18n->interpolate($tmperr->getMessage() , $tmperr->getVars());
    }

    // description: get the page ID of a form field
    // param: formField: a FormField object
    // returns: page ID in string
    public function getFormFieldPageId($formField) {
        return $this->formFieldPageIds[$formField->getId() ];
    }

    // description: get the widths of label and form field
    // returns: an array of widths in integer (pixel) or string (e.g. "60%"). The
    //     first element is for label and the second element is for form field.
    // see: setColumnWidths()
    public function getColumnWidths() {
        return $this->columnWidths;
    }

    // description: set the widths of label and form field
    // param: widths: an array of widths in integer (pixel) or string (e.g.
    //     "60%"). The first element is for label and the second element is for
    //     form field.
    // see: getColumnWidths()
    public function setColumnWidths($widths = array(
        "165",
        "385"
    )) {
        $this->columnWidths = $widths;
    }

    // description: get the ID of the block
    // returns: a string
    // see: setId()
    public function getId() {
        return $this->id;
    }

    // description: set the ID of the block
    // param: Id: a string
    // see: getId()
    public function setId($id) {
        $this->id = $id;
    }

    // description: get all the page IDs
    // returns: an array of IDs in string
    // see: addPage()
    public function getPageIds() {
        return $this->pageIds;
    }

    // description: get the label of a page
    // param: pageId: the ID of the page
    // returns: a Label object
    // see: addPage()
    public function getPageLabel($pageId) {
        return $this->pageLabels[$pageId];
    }

    // description: add a page into the paged block
    // param: pageId: the ID of the page in string
    // param: label: a Label object for the page
    // see: getPageId(), getPageLabel()
    public function addPage($pageId, $label) {
        $this->pageIds[] = $pageId;
        $this->pageLabels[$pageId] = $label;

        // set selected ID to default
        if ($this->getSelectedId() == "") {
            $this->setSelectedId($pageId);
        }
    }

    // description: get the ID of the selected page
    // returns: a string
    // see: setSelectedId()
    public function getSelectedId() {
        return $this->selectedId;
    }

    // description: set the ID of the selected page
    // param: selectedId: a string
    // see: getSelectedId()
    public function setSelectedId($selectedId) {
        $this->selectedId = $selectedId;
        return $this->getSelectedId();
    }

    // description: get the mark for marking the start of a HTML section
    //     specifically for a page. This is useful for page specific HTML
    // param: pageId: the ID of the page in string
    // returns: the mark in string
    // see: getEndMark()
    public function getStartMark($pageId) {
        $selectedId = $this->getSelectedId();
        if ($pageId == $selectedId) {
            return "";
        }
        else {
            return "<!-- ";
        }
    }

    // creates javascript to report non-field-specific errors.
    public function reportErrors() {
        global $REQUEST_METHOD;
        if ($REQUEST_METHOD == "GET") {
            return "";
        }
        $page = $this->getPage();
        $i18n = $page->getI18n();
        $result = "";
        if (count($this->generalErrors) > 0) {
            $errorInfo = "";
            for ($i = 0;$i < count($this->generalErrors);$i++) {
                $error = $this->generalErrors[$i];
                $errMsg = "";
                if (get_class($error) == "CceError" && ($tag = $error->getKey())) {
                    $tag .= "_invalid";
                    $errMsg = $i18n->getJs($tag, "", $error->getVars());
                    if ($errMsg === $tag) {
                        $errMsg = "";
                    }
                }
                if ($errMsg === "") {
                    $errMsg = $i18n->interpolateJs($error->getMessage() , $error->getVars());
                }
                $errorInfo .= $errMsg . "<BR>";
            }
            //        $result = "<script language=\"javascript\">\n"
            //                . "var errorInfo = '$errorInfo';\ntop.code.info_show(errorInfo, \"error\");"
            //                . "</script>\n";
            
        }
        else {
            //        $result = "<script language=\"javascript\">\n"
            //                . "top.code.info_show(\"\", null);\n" . "</script>\n";
            
        }
        return $result;
    }

    // call this with an array of pageIds to hide if no form
    // fields will be shown for that tab
    // so if you have two tabs 'foo' and 'bar' and you want them to
    // not show if nothing is under them pass in array('foo', 'bar')
    public function setHideEmptyPages($pages) {
        $this->hideEmptyPages = $pages;
    }

    public function toHtml($style = "") {
        $CI = get_instance();
        $page = $this->getPage();
        $i18n = $page->getI18n();
        $id = $this->getId();

        $form = $page->getForm();
        $formId = $form->getId();

        $this->BxPage->setPagedBlock($id);

        if ($this->getSelectedId() === 'errors') {
            // search through pages for errors, switch to the first page with errors on it.
            $this->setSelectedId($this->pageIds[0]); // the default
            for ($i = 0;$i < count($this->formFields);$i++) {
                $field_id = $this->formFields[$i]->getId();
                if ($this->formFieldErrors[$field_id]) {
                    $this->setSelectedId($this->formFieldPageIds[$field_id]);
                    break;
                }
            }
        }
        $selectedId = $this->getSelectedId();
        $pageIds = $this->getPageIds();

        $titleLabelHtml = false;
        $titleLabel = $this->getLabel();

        $ms_FormFields = array();
        $ms_FormFields['PIDS'] = array();

        // Form validation errors.
        //
        // To keep it simple for now we don't show errors next to each FormFieldObject (yet).
        // Instead we show them all in one block.
        //
        // First of all we ask BxPage for the errors that got generated:
        //
        $my_BXErrors = $page->getErrors();

        // separate all the form fields on the selected page and not
        $formFieldsInPage = array();
        $formFieldIdsInPage = array();
        $formFieldsOutPage = array();
        $formFieldIdsOutPage = array();
        $formFields = $this->getFormFields();
        $pageIdsWithFormFields = array();

        for ($i = 0;$i < count($formFields);$i++) {
            $formField = $formFields[$i];
            $formFieldId = $formField->getId();

            $formFieldPageId = $this->getFormFieldPageId($formField);

            // keep track of tabs with no formfields
            if (!isset($pageIdsWithFormFields[$formFieldPageId])) {
                $pageIdsWithFormFields[$formFieldPageId] = true;
            }

            if ($formFieldPageId == $selectedId) {
                // form fields on the selected page
                // this should be a reference assignment, but php sucks
                // $formFieldsInPage[] = $formField; <-- Baaad idea!
                $formFieldsInPage[] = $formField;
                $formFieldIdsInPage[] = $formFieldId;
            }
            else {
                // form fields not on the selected page
                //$formFieldsOutPage[] = $formField; <-- Another baaad idea!
                $formFieldsOutPage[] = $formField;
                $formFieldIdsOutPage[] = $formFieldId;
            }

            // This is pure bullshit and a prime example why it is sometimes better to rewrite a Class
            // from scratch instead of trying to modify it to what you want/need it to do instead.
            //
            // The original Cobalt code built arrays that contained only the active form elements on
            // the current tab. The other elements of other tabs were not shown as form fields, but
            // as hidden fields. Thx to jQuery we don't need to reload the page and juggle active
            // and hidden fields around. But that also means that we can't use the arrays that the
            // Cobalt guys set up. So I create my own here that has all active form elements neatly
            // set up.
            //
            // 'PIDS' contains the page IDs.
            // 'FFID' contains arrays with the 'formFieldId' => 'formFieldPageId'
            // 'FF'   contains the full objects of the FormFields needed to render them
            //
            // This makes all data easily accessible whenever we need them for output generation.
            //
            if (!in_array($formFieldPageId, $ms_FormFields['PIDS'])) {
                // We have not seen a PageID with this name yet, so we add it to our array 'PIDS':
                $ms_FormFields['PIDS'][] = $formFieldPageId;
            }
            // Add a 'FFID' with the current 'formFieldId' and 'formFieldPageId':
            $ms_FormFields['FFID'][$i] = array(
                $formFieldId => $formFieldPageId
            );
            // Add a 'FF' with the full object of the actual 'formField':
            $ms_FormFields['FF'][$i] = $formField;

        }

        // find all dividers in page
        $dividers = $this->getDividers();
        $dividersInPage = array();
        $dividerIndexesInPage = array();
        for ($i = 0;$i < count($dividers);$i++) {
            // divider not on this page?
            if ($this->dividerPageIds[$i] != $selectedId) {
                continue;
            }
            $dividersInPage[] = $dividers[$i];
            $dividerIndexesInPage[] = $this->dividerIndexes[$i];
        }

        // make form field for selected ID
        $builder = new FormFieldBuilder();
        $selectedIdField = $builder->makeHiddenField("_PagedBlock_selectedId_$id", $selectedId);

        // mark visited pages
        if ($selectedId) {
            $visitedPages = $builder->makeHiddenField($selectedId, "true");
        }
        for ($i = 0;$i < count($pageIds);$i++) {
            $pageId = $pageIds[$i];

            // marked already
            if ($pageId == $selectedId) {
                continue;
            }

            // variable $<pageId> is true if it was visited
            global $$pageId;
            if ($$pageId) {
                //$visitedPages .= $builder->makeHiddenField($pageId, "true");
                
            }
        }

        // maintain all form fields outside this page as hidden values
        $hiddenFormFields = "";
        for ($i = 0;$i < count($formFieldsOutPage);$i++) {
            $formField = $formFieldsOutPage[$i];
            $formFieldId = $formFieldIdsOutPage[$i];
            $formFieldPageId = $this->getFormFieldPageId($formField);

            // find the value of the form field
            $value = "";

            // use value set to the form field, since the form field knows
            // how to preserve data

            if ((!get_class($formField) == "BarGraph") && (!get_class($formField) == "RawHTML"))  {
                $value = $formField->getValue();
                $hiddenFormFields .= $builder->makeHiddenField($formFieldId, $value);
            }

            // we need some special treatment for MultiChoice objects because they
            // can contain Options with FormFields
            // PHP is case-insensitive, so it returns "multichoice" <- Lies! It's not!
            // FIXME:  this is a nasty hack, if we want to do this there
            //         should be a more general way like checking if the
            //         getFormFields method exists for the given object
            if ((get_class($formField) == "multichoice") || (get_class($formField) == "MultiChoice")) {
                $options = $formField->getOptions();
                for ($j = 0;$j < count($options);$j++) {
                    $optionFields = $options[$j]->getFormFields();

                    for ($k = 0;$k < count($optionFields);$k++) {

                        $optionField = $optionFields[$k];
                        $optionFieldId = $optionField->getId();

                        // Don't we just hate it? We have to set the labels of MultiChoice objects manually to BxPage!
                        // Somehow this magically works all by itself for getTextFields, but not for getIntegers.
                        // So for getTextfields this may be a bit redundant (but doesn't hurt), while it will also
                        // fix it for getIntegers and wherever else it may be broken:
                        $this->BxPage->setLabel($optionFieldId, $options[$j]->formFieldLabels[$optionFieldId]->label, $options[$j]->formFieldLabels[$optionFieldId]->description);

                        // use value set to the form field
                        $optionValue = $optionField->getValue();
                        $hiddenFormFields .= $builder->makeHiddenField($optionFieldId, $optionValue);
                    }
                }
            }
        }

        // Start: Tab integration
        // make tabs
        $shown_pages = 0;
        $we_have_tabs = "0";
        $PagedBlock_Tabs = "\n";

        // Start with empty output strings:
        $result_not_used = "";
        $result_hidden = "";
        $result_head = "                    <!-- PagedBlock $id -->" . "\n";
        $result_tabs = "";
        $result_formfield = "";
        $result_foot = "";
        $result_errors = "";

        $seenTabs = array();
        $session_stored_pageIds = array();

        for ($i = 0;$i < count($pageIds);$i++) {
            $pageId = $pageIds[$i];
            $seenTabs[] = $pageId;

            // Drop hidden tabs:
            if (($pageId == "hidden") || ($pageId == "") || (!$pageId)) {
                $shown_pages--;
                continue;
            }
            elseif (!isset($pageIdsWithFormFields[$pageId]) && in_array($pageId, $this->hideEmptyPages)) {
                // drop tabs with no formfields that are in the
                // hideEmptyPages array
                $shown_pages--;
                continue;
            }
            else {
                $shown_pages++;
            }

            $label = $this->getPageLabel($pageId);
            $labelLabel = $label->getLabel();
            $description = $label->getDescription();

            // find the right action
            if ($pageId == $selectedId) {
                $action = "javascript: void 0;";
            }
            else {
                global $SCRIPT_NAME;
                $action = "javascript: document.$formId._PagedBlock_selectedId_$id.value = '$pageId'; if(document.$formId.onsubmit()) { document.$formId.action = '$SCRIPT_NAME'; document.$formId.submit(); } else void 0;";
            }

            // If no 'defaultPage' is set manually, then the first tab becomes the 'defaultPage' and therefore the actively selected tab:
            if (!$this->getDefaultPage()) {
                if (!isset($HaveDefaultTab)) {
                    $HaveDefaultTab = $pageId;
                }
            }
            else {
                if (!isset($HaveDefaultTab)) {
                    $HaveDefaultTab = $this->getDefaultPage();
                }
            }

            if ($HaveDefaultTab == $pageId) {
                $elmer_tab_is_expanded = 'class="active" ';
                $elmer_tab_is_active = 'true';
            }
            else {
                $click_this_tab_to_activate_it = '';
                $elmer_tab_is_expanded = '';
                $elmer_tab_is_active = 'false';
            }

            $out_text_pageId = $i18n->getClean($pageId);

            $known_PagedBlocks = $this->BxPage->getPagedBlock();
            $num_PagedBlocks = count($known_PagedBlocks);
            if ($num_PagedBlocks === 1) {
                $shower = "tabs-$shown_pages";
            }
            else {
                $shower = 'tabs-' . $id . '-' . $shown_pages;
            }
            $session_stored_pageIds[$shower] = $out_text_pageId;

            // Get the tabs set up:
            if ($description == "") {
                $PagedBlock_Tabs .= '                                            <li ' . $elmer_tab_is_expanded . ' role="presentation"><a aria-expanded="' . $elmer_tab_is_active . '" data-toggle="tab" role="tab" id="tab_id_' . $shown_pages . '" href="#' . $shower . '"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="" data-container="body">' . $out_text_pageId . '</span></a></li>' . "\n";
                $tabID[$pageId] = $shown_pages;
            }
            else {
                $PagedBlock_Tabs .= '                                            <li ' . $elmer_tab_is_expanded . ' role="presentation"><a aria-expanded="' . $elmer_tab_is_active . '" data-toggle="tab" role="tab" id="tab_id_' . $shown_pages . '" href="#' . $shower . '"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="' . $i18n->getClean($description) . '" data-container="body">' . $out_text_pageId . '</span></a></li>' . "\n";
                $tabID[$pageId] = $shown_pages;
            }
        }

        $is_tabbed_class = "";
        if ($shown_pages > "1") {
            $we_have_tabs = "1";
            // We have more than one tab to show!
            if ($this->sideTabs == false) {
                $is_tabbed_class = "panel-tabs";
                $tab_header_class = 'nav nav-tabs';
            }
            else {
                $is_tabbed_class = "";
                $tab_header_class = '';
            }
        }
        else {
            // We have only one tab, so we hide the fact that there are tabs:
            $PagedBlock_Tabs = '';
        }
        // End: Tab integration
        // make title row

        // Store available 'PagedBlock' pageIds and currently active tab into session data:
        $session_stored_pageIds = array_map("unserialize", array_unique(array_map("serialize", $session_stored_pageIds)));
        $data['PagedBlock']['pageIds'] = $session_stored_pageIds;
        $data['PagedBlock']['active_tab'] = $HaveDefaultTab;
        session()->set($data);

        if (isset($this->label->label)) {
            $head_label = $this->label->label;
        }
        elseif (isset($this->Label)) {
            if ($this->Label != "") {
                if (is_object($this->Label)) {
                    $head_label = $this->Label->label;
                }
                else {
                    $head_label = $this->Label;
                }
            }
            else {
                $head_label = $i18n->getHtml($id);
            }
        }
        else {
            $head_label = $i18n->getHtml($id);
        }

        //-- Start: Errors
        // This modification shows the errors on all tabs (once) instead of showing
        // it just on the tab where it happened. Which was confusing, because the
        // error might have been on an inactive tab. So this is better. Also works
        // correctly if the tabs are unified into one page and then shows the error
        // once.
        //
        // Show BXErrors:
        $error_output = '';
        if ($this->getDisplayErrors() == true) {
            if (is_array($my_BXErrors)) {
                if (count($my_BXErrors) > 0) {
                    foreach ($my_BXErrors as $key => $value) {
                        if (!is_object($value)) {
                            if (is_array($value)) {
                                // Grrr .... got another array inside the array? Deal with it:
                                foreach ($value as $newkey => $newvalue) {
                                    $error_output .= $newvalue;
                                }
                            }
                            else {
                                // No separate array insite the error array? Out with it:
                                $error_output .= $value;
                            }
                        }
                        else {
                            // Error is an object? Nice. Deal with that, too:
                            if (isset($value->vars)) {
                                if (is_array($value->vars)) {
                                    $error_output .= ErrorMessage($i18n->get($value->message)) . "<br>";
                                }
                                else {
                                    $error_output .= ErrorMessage($i18n->get($value->message)) . "<br>";
                                }
                            }
                            else {
                                $error_output .= ErrorMessage($i18n->get($value->message)) . "<br>";
                            }
                        }
                    }
                }
            }
        }

        //
        //--- Handle button for maximizing selected UI element:
        //

        $window_icon = '';

        //
        //--- Handle button for opening content URL:
        //

        // Type 'window': (opens on same page)
        $window_icon_helptext = $i18n->getWrapped("[[palette.icon_window]]");
        if (isset($this->elements['window'])) {
            // Set the required JavaScript in the page header that we need to open this in a new Window:
            $this->BxPage->setExtraHeaders('<script>');
            $this->BxPage->setExtraHeaders('function open_win()');
            $this->BxPage->setExtraHeaders('{');
            $this->BxPage->setExtraHeaders('window.open("' . $this->elements['window'] . '","_blank","toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800");');
            $this->BxPage->setExtraHeaders('}');
            $this->BxPage->setExtraHeaders('</script>');
            $window_icon .=<<<HTML

                                                        <!-- Open in new Window -->
                                                        <a href="#" class="pull-left inline-block mr-15 show_window hover" onclick="open_win()">
                                                            <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$window_icon_helptext" data-container="body"><i class="fa fa-external-link"></i></span>
                                                        </a>
                                                        <!-- /Open in new Window -->
            HTML;
        }
        // Type 'self': (opens in new window)
        if (isset($this->elements['self'])) {
            // Set the required JavaScript in the page header that we need to open this in a new Window:
            $this->BxPage->setExtraHeaders('<script>');
            $this->BxPage->setExtraHeaders('function open_win()');
            $this->BxPage->setExtraHeaders('{');
            $this->BxPage->setExtraHeaders('window.open("' . $this->elements['self'] . '","_top","toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=yes, width=1024, height=800");');
            $this->BxPage->setExtraHeaders('}');
            $this->BxPage->setExtraHeaders('</script>');
            $window_icon .=<<<HTML

                                                        <!-- Open in new Window -->
                                                        <a href="#" class="pull-left inline-block mr-15 show_window hover" onclick="open_win()">
                                                            <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$window_icon_helptext" data-container="body"><i class="fa fa-external-link"></i></span>
                                                        </a>
                                                        <!-- /Open in new Window -->
            HTML;
        }

        // Type 'maximize': (turns element into fullscreen)
        $maximize_icon_helptext = $i18n->getWrapped("[[palette.icon_maximize]]");
        if (isset($this->elements['maximize'])) {
            $window_icon .=<<<HTML

                                                        <!-- Maximize Element -->
                                                        <a href="#" class="pull-left inline-block full-screen mr-15">
                                                            <span data-toggle="tooltip" data-placement="bottom" title="" data-original-title="$maximize_icon_helptext" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                                        </a>                                                        
                                                        <!-- /Maximize Element -->
            HTML;
        }

        //
        //--- PagedBlock with Header-Tabs
        //

        // If we have tabs, then we show the tabbings as well. We won't show it if there is only a single tab, though:
        $tabs_in_pagedBlock = '';
        if (count($this->pageIds) > "1") {
            if ($this->sideTabs == true) {
                // $result_head .= '       <div class="side_holder">' . "\n";
            }
            $tabs_in_pagedBlock .= $PagedBlock_Tabs;
            if ($this->sideTabs == true) {
                //$result_head .= '       </div>' . "\n";
            }
        }

        $toggle_icon = '';
        if (isset($this->elements['toggle'])) {
            $toggle_helptext = $i18n->getWrapped("[[palette.icon_toggle]]");
            $toggle_icon =<<<HTML

                                                        <!-- Minimize Panel Body -->
                                                        <a class="pull-left inline-block mr-15" data-toggle="collapse" href="#collapse_1" aria-expanded="true">
                                                            <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$toggle_helptext" data-container="body"><i class="zmdi zmdi-chevron-down"></i></span>
                                                            <span data-toggle="tooltip" data-placement="top" title="" data-original-title="$toggle_helptext" data-container="body"><i class="zmdi zmdi-chevron-up"></i></span>
                                                        </a>
                                                        <!-- /Minimize Panel Body -->
            HTML;
        }

        //
        //--- Set up Form & CSRF:
        //

        // Set up CSRF:
        $CI = & get_instance();
        $BX_SESSION = $CI->getBX_SESSION();
        $csrf = array(
            'name' => $BX_SESSION['csrf_token_name'],
            'hash' => $BX_SESSION['csrf_cookie_name']
        );

        $csrf_cookie_name = '';
        if (isset($_COOKIE['BlueOnyx_CSRF_cookie'])) {
            $csrf_cookie_name = $_COOKIE['BlueOnyx_CSRF_cookie'];
        }

        // It's possible to set a 'form_action', which is an URL that form data is posted to. Let us see if it is set:
        $form = get_object_vars($page->getForm());

        if (!$this->getFormDisabled()) {

            if ($form['action']) {
                $result_head .= '                    <form class="validate_form" method="post" action="' . $form['action'] . '" ENCTYPE="multipart/form-data" id="waiting_overlay" data-toggle="validator" role="form">' . "\n";
            }
            else {
                $result_head .= '                    <form class="validate_form" method="post" ENCTYPE="multipart/form-data" id="waiting_overlay" data-toggle="validator" role="form">' . "\n";
            }
            $result_head .= "                    <input type=\"hidden\" name=\"" . 'BlueOnyx_CSRF_token' . "\" value=\"" . $csrf_cookie_name . "\" />\n";
            $result_head .= "                    <input id=\"SelectedTab\" type=\"hidden\" name=\"" . 'SelectedTab' . "\" value=\"" . $HaveDefaultTab . "\" />\n";

            $this->BxPage->setExtraHeaders('
                <script>
                $(document).ready(function() {
                    // Add an event listener to the tab shown event
                    $(\'a[data-toggle="tab"]\').on(\'shown.bs.tab\', function (e) {
                        // Get the text of the active tab
                        var activeTabText = $(e.target).find("span").text().trim();

                        // Update the value of the SelectedTab input
                        $("#SelectedTab").val(activeTabText);
                    });
                });
                </script>
            ');

        }

        if ($this->sideTabs == true) {

            $result_head .=<<<HTML
                                <div class="col-md-12">
                                    <div class="panel panel-default card-view">
                                        <div class="panel-heading">
                                            <div class="pull-left">
                                                <h6 class="panel-title txt-dark">$head_label</h6>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="panel-wrapper collapse in">
                                            <div  class="panel-body">
                                                <!-- Error Messages -->
                                                $error_output
                                                <!-- /Error Messages -->
                                                <div  class="tab-struct vertical-tab custom-tab-1 mt-10">
                                                    <ul role="tablist" class="nav nav-tabs ver-nav-tab" id="$id">
                                                    $PagedBlock_Tabs
                                                    </ul>
                                                    <div class="tab-content" id="$id">

            HTML;
        }
        else {

            // Check if we insert the BlueOnyx Header on top of a PageBlock() element:
            $header_insert = '';
            if ($this->BlueOnyxHeader === TRUE) {
                $header_insert =<<<HTML
                                        <!-- Start -->
                                        <div class="panel-heading mb-20 mt-0" style="background: #000000; height: 75px;">
                                            <div class="pull-left">
                                                <a href="https://www.blueonyx.it" target="_blank">
                                                    <span>
                                                        <svg viewBox="0 0 90 90" height="65.83812268091626" width="344.2851548328011" style="width: 344.285px; height: 65.8381px; position: absolute; top: 39px; left: 30px; z-index: 0; cursor: pointer; overflow: visible; transform: translate(-50%, -50%) scale(0.60415);"><defs id="SvgjsDefs2532"><linearGradient id="SvgjsLinearGradient2539"><stop id="SvgjsStop2540" stop-color="#2d388a" offset="0"></stop><stop id="SvgjsStop2541" stop-color="#00aeef" offset="1"></stop></linearGradient></defs><g id="SvgjsG2533" featurekey="v37d4h-0" transform="matrix(0.8427388072013855,0,0,0.8427388072013855,-1.373664294987499,-11.746093633286709)" fill="url(#SvgjsLinearGradient2539)"><polygon xmlns="http://www.w3.org/2000/svg" points="42.021,89.823 98.369,53.589 82.566,61.219 82.574,61.292 82.527,61.238 82.264,61.365 82.281,61.389 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="79.395,61.174 38.895,57.391 36.811,91.25 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="3.71,38.712 34.918,92.062 37.066,57.146 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="95.209,45.172 80.775,27.29 79.232,32.516 95.928,46.175 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="98.135,50.979 79.758,35.645 82.281,58.632 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="61.512,33.498 79.758,54.504 77.424,33.356 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="59.756,34.836 39.467,54.979 80.432,58.808 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="58.445,33.191 36.674,27.365 38.974,52.971 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="37.133,54.393 34.822,28.537 6.776,37.639 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="8.923,35.031 34.527,26.292 25.659,19.867 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="1.63,26.184 3.25,37.485 20.957,21.022 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="28.355,14.604 7.837,21.987 26.101,17.109 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="36.751,24.86 60.131,31.196 77.148,30.907 78.672,25.749 67.951,19.853 48.173,14.486 32.256,13.938 28.073,18.586   "></polygon></g>
                                                        </svg>
                                                    </span>

                                                    <span class="brand-text">
                                                        <svg viewBox="0 0 344.2851548328011 65.83812268091626" height="65.83812268091626" width="344.2851548328011" style="width: 344.285px; height: 65.8381px; position: absolute; top: 33px; left: 100px; z-index: 0; cursor: pointer; overflow: visible; transform: translate(-50%, -50%) scale(0.60415);"><defs id="SvgjsDefs2532"><linearGradient id="SvgjsLinearGradient2539"><stop id="SvgjsStop2540" stop-color="#2d388a" offset="0"></stop><stop id="SvgjsStop2541" stop-color="#00aeef" offset="1"></stop></linearGradient></defs><g id="SvgjsG2534" featurekey="nameLeftFeature-0" transform="matrix(2.3108277320861816,0,0,2.3108277320861816,97.39659960527688,2.964957363641652)" fill="#ffffff"><path d="M9.0332 12.685500000000001 c1.6113 0.42969 2.6953 1.5137 2.6953 3.4766 c0 2.2754 -1.4746 3.8379 -4.1602 3.8379 l-5.5762 0 l0 -13.926 l4.3652 0 c2.7539 0 4.3652 1.4746 4.3652 3.75 c0 1.2793 -0.57617 2.3242 -1.6895 2.8613 z M6.3672 8.047 l-2.1191 0 l0 3.916 l2.3535 0 c1.25 0 1.9531 -0.88867 1.9531 -1.9922 c0 -1.0938 -0.76172 -1.9238 -2.1875 -1.9238 z M7.1484 17.9687 c1.582 0 2.3145 -0.9668 2.3145 -2.0605 c0 -1.1426 -0.75195 -2.0996 -2.4023 -2.0996 l-2.8125 0 l0 4.1602 l2.9004 0 z M16.992215625 17.9102 l4.5996 0 l0 2.0898 l-6.9336 0 l0 -13.926 l2.334 0 l0 11.836 z M29.042978125 20.18555 c-2.959 0 -5.2637 -1.6504 -5.2637 -4.9316 l0 -9.1797 l2.3438 0 l0 8.8574 c0 2.2168 1.2793 3.1641 2.9199 3.1641 s2.9395 -0.95703 2.9395 -3.1641 l0 -8.8574 l2.334 0 l0 9.1797 c0 3.2813 -2.3047 4.9316 -5.2734 4.9316 z M45.781225 8.154 l-5.4102 0 l0 3.8574 l4.7852 0 l0 2.0605 l-4.7852 0 l0 3.8379 l5.4102 0 l0 2.0898 l-7.7734 0 l0 -13.926 l7.7734 0 l0 2.0801 z"></path></g><g id="SvgjsG2535" featurekey="nameRightFeature-0" transform="matrix(2.2486753463745117,0,0,2.2486753463745117,210.06748997199185,4.197591649017779)" fill="#ffffff"><path d="M8.0762 20.19531 c-4.1504 0 -7.2168 -2.832 -7.2168 -7.2559 c0 -4.4336 3.0664 -7.2461 7.2168 -7.2461 c4.1406 0 7.207 2.8125 7.207 7.2461 c0 4.4238 -3.0664 7.2559 -7.207 7.2559 z M8.0762 17.5098 c2.4316 0 4.2969 -1.709 4.2969 -4.5703 c0 -2.8516 -1.8652 -4.5508 -4.2969 -4.5508 s-4.2969 1.6992 -4.2969 4.5508 c0 2.8613 1.8652 4.5703 4.2969 4.5703 z M27.61734375 5.888999999999999 l2.9199 0 l0 14.111 l-3.3887 0 l-6.25 -10.088 l0 10.088 l-2.9199 0 l0 -14.111 l3.3496 0 l6.2891 10.029 l0 -10.029 z M44.77528125 5.888999999999999 l-4.6387 7.3535 l0 6.7578 l-2.9395 0 l0 -6.6895 l-4.668 -7.4219 l3.2422 0 l2.8809 4.8047 l2.8906 -4.8047 l3.2324 0 z M45.214840625 20 l5.498 -7.3633 l-5.3418 -6.748 l3.5645 0 l3.5156 4.6289 l3.5156 -4.6289 l3.5645 0 l-5.3418 6.748 l5.498 7.3633 l-3.7305 0 l-3.5059 -4.9902 l-3.5059 4.9902 l-3.7305 0 z"></path></g>
                                                        </svg>
                                                    </span>
                                                </a>
                                            </div>
                                        </div>
                                        <!-- End -->
                HTML;
            }

            $result_head .=<<<HTML
                                <div class="col-md-12">
                                    <div class="panel panel-default $is_tabbed_class card-view">
                                        $header_insert
                                        <div class="panel-heading">
                                            <div class="pull-left">
                                                <h6 class="panel-title txt-dark">$head_label</h6>
                                            </div>
                                            <div class="pull-right">
                                                <div class="tab-struct custom-tab-1">
                                                    <ul role="tablist" class="nav nav-tabs" id="$id">
                                                        $PagedBlock_Tabs
                                                        <!-- Header Buttons Right -->
                                                        <li>
                                                            $window_icon
                                                            $toggle_icon
                                                        </li>
                                                        <!-- /Header Buttons Right -->
                                                    </ul>
                                                </div>  
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div id="collapse_1" class="panel-wrapper collapse in"><!-- panel-wrapper -->
                                            <div class="panel-body"><!-- panel-body -->
                                                <!-- Error Messages -->
                                                $error_output
                                                <!-- /Error Messages -->
                                                <div class="tab-content" id="TabContent_$id"><!-- tab-content -->

            HTML;
        }

        //
        //--- Add the general (invisible) error that the form validation failed. This gets unhidden if it really fails:
        //
//        $result_head .= '<div id="error_formfields" class="error_formfields alert alert_red display_none"><img width="40" height="36" src="/.adm/images/icons/small/white/alarm_bell.png"><strong>' . $i18n->get("[[base-alpine.errorFormfields]]") . '<br>&nbsp;</strong></div>' . "\n";
//
//        if ($this->getDisplayErrors() == true) {
//            if (isset($errormsg)) {
//                if (is_array($errormsg)) {
//                    if (count($errormsg) > 0) {
//                        foreach ($errormsg as $key => $value) {
//                            $result_head .= $value;
//                        }
//                    }
//                }
//            }
//        }

        // Dump in the form fields that are vsible on this page or tab (tabbing is done later):
        // is this page visited before?
        global $$selectedId;
        $isVisited = $$selectedId ? true : false;

        $optionalStr = $i18n->get("optional", "palette");

        // The next var and for loop tally up how many items the user will see
        // so that I can figure out when not to draw a final divider.
        $userEditableItems = 0;

        for ($i = 0;$i < count($formFieldsInPage);$i++) {
            if (!is_object($formFieldsInPage[$i])) {
                continue;
            }
            $formFieldObj = $formFieldsInPage[$i];

            // get form field HTML
            $formField = $formFieldObj->toHtml();

            // hidden field is simple
            $access = "r";
            if (method_exists($formFieldObj, 'getAccess')) {
                $access = $formFieldObj->getAccess();
            }
            // Super! Now that we can add ButtonContainers as well, we need a special case for
            // Buttons, as they usually don't have an "access" field set:
            if (isset($formFieldObj->Button)) {
                $access = "rw";
            }
            if ($access != "") {
                $userEditableItems++;
            }
        }

        $userItem = 0;
        $result = "";
        for ($i = 0;$i < count($formFieldsInPage);$i++) {
            $subHeader = 0;

            if (!is_object($formFieldsInPage[$i])) {
                continue;
            }

            $formFieldObj = $formFieldsInPage[$i];

            // get form field HTML
            $formField = $formFieldObj->toHtml();

            // hidden field is simple
            $access = $formFieldObj->getAccess();
            if ($access == "") {
                $result_hidden .= $builder->makeHiddenField($formFieldObj->getId() , $formFieldObj->getValue());
                continue;
            }

            // get label HTML
            $formFieldLabelObj = $this->getFormFieldLabel($formFieldObj);
            $label = is_object($formFieldLabelObj) ? $formFieldLabelObj->toHtml() : "";

            $errormsg = $this->getFormFieldError($formFieldObj);
            if ($errormsg) {
                $errorflag = "<TD><a href=\"javascript: void 0\" 
                onMouseOver=\"return top.code.info_mouseOverError('" . $i18n->interpolate($errormsg) . "')\" 
                onMouseOut=\"return top.code.info_mouseOut();\"><img
                alt=\"[ERROR]\" border=\"0\" src=\"/libImage/infoError.gif\"></a></TD>";
            }
            else {
                $errorflag = "";
            }

            $optional = "";
            if ($formFieldObj->isOptional() && (strval($formFieldObj->isOptional()) != "silent")) {
                $optional = "<FONT STYLE=\"\">($optionalStr)</FONT>";
            }
            $result_formfield .= $formField;

            $userItem++;
        }

        if ($this->sideTabs == false) {
            //$result_foot .= '                            <div class="form-group mb-20"><!-- Side Tabs false/Start Button Container -->' . "\n";
        }

        // make buttons
        $buttons = $this->getButtons();
        $allButtons = "";

        $SaveButton = '';
        $CancelButton = '';
        $OtherButtons = array();

        if ($buttons) {
            // Check if we have at least a SaveButton and/or a CancelButton:
            $itemCnt = '0';
            $have_SaveButton = 0;
            foreach ($buttons as $item) {
                if ($item instanceof SaveButton) {
                    // The array contains a SaveButton object
                    $SaveButton = $item;
                    $have_SaveButton = 1;
                }
                elseif ($item instanceof CancelButton) {
                    // The array contains a SaveButton object
                    $CancelButton = $item;
                }
                else {
                    $OtherButtons[] = $item;
                }
                $itemCnt++;
            }

            $allButtons .=<<<HTML
                                        <!-- /Button Row -->
                                        <div class="form-group mb-20">
                                            <div class="row">
            

            HTML;

            // Save button is always on the left
            if (is_object($SaveButton)) {
                $allButtons .= '                                    <div class="col-xs-6 col-sm-4">' . "\n";
                $allButtons .= $SaveButton->toHtml();
                $allButtons .= "                                    </div>\n";
            }

            // We have no Save button, so we used the first $OtherButtons and show it on the left and then remove it from the array:
            if ((count($OtherButtons) > '0') && ($have_SaveButton === 0) && (isset($OtherButtons['0']))) {
                $allButtons .= '                                    <div class="col-xs-6 col-sm-4">' . "\n";
                $allButtons .= $OtherButtons[0]->toHtml();
                array_shift($OtherButtons);
                $allButtons .= "                                    </div>\n";
                $have_SaveButton = 1;
            }

            // We have neither a Save button nor an OtherButton for the middle, but we have a Cancel button. 
            // Add an empty block on the left so that the Cancel button is properly spaced:
            if ((count($OtherButtons) === 0) && ($have_SaveButton === 0) && (is_object($CancelButton))) {
                $allButtons .= '                                    <div class="col-xs-6 col-sm-4">' . "\n";
                $allButtons .= "                                        &nbsp;\n";
                $allButtons .= "                                    </div>\n";
            }

            // All (remaining) OtherButtons are shown in the middle:
            if (count($OtherButtons) > '0') {
                foreach ($OtherButtons as $key => $other_button) {
                    $allButtons .= '                                    <div class="col-xs-6 col-sm-4"><!-- Middle Buttons -->' . "\n";
                    $allButtons .= $other_button->toHtml();
                    $allButtons .= "                                    </div><!-- /Middle Buttons -->\n";
                }
            }
            else {
                $allButtons .= '                                    <div class="col-xs-6 col-sm-4"><!-- Middle Buttons -->' . "\n";
                $allButtons .= "                                    </div><!-- /Middle Buttons -->\n";
            }

            // Cancel Button is always on the right side:
            if (is_object($CancelButton)) {
                $allButtons .= '                                    <div class="col-xs-6 col-sm-4" align="right"><!-- /CancelButton-->' . "\n";
                $allButtons .= $CancelButton->toHtml();
                $allButtons .= "                                    </div><!-- /CancelButton-->\n";
                $allButtons .= "                                </div>\n";
            }

            $allButtons .=<<<HTML
                                        </div>
                                        <!-- /Button Row -->
            HTML;

            $allButtons .= "\n";

            if ($this->sideTabs == false) {
                $result_foot .= $allButtons;
            }
        }
        if ($this->sideTabs == false) {
            //$result_foot .= '                            </div><!-- /Side Tabs false/Start Button Container -->' . "\n";
            $result_foot .= '                        </div>' . "\n";
        }

        $result_foot .= '                    </div>' . "\n";
        $result_foot .= '                    </form>' . "\n";

        $result_errors = $this->reportErrors();

        // Render the output:
        $result .= $result_head;
        $currentFFnum = '0';
        $already_shown = array();
        $corrector = '0';

        $altDivHeight = $this->getDivHeight();
        if ($altDivHeight != "") {
            //$vertical_stretcher_open = '<div style="min-height: ' . $altDivHeight . 'px;">' . "\n";
            //$vertical_stretcher_close = '</div>' . "\n";

            $vertical_stretcher_open = '';
            $vertical_stretcher_close = '';
        }
        else {
            $vertical_stretcher_open = '';
            $vertical_stretcher_close = '';
        }

        //--
        // Old location of the Error display.
        //--
        //for($i = 0; $i < count($ms_FormFields['PIDS']); $i++) {
        for ($i = 0;$i < count($seenTabs);$i++) { // <-- Keeps better track of the order of tabs than $ms_FormFields['PIDS']!!!
            $currentTab = $seenTabs[$i];
            if (($currentTab == "hidden") || ($currentTab == "") || (!$currentTab)) {
                $corrector++;;
            }
            else {
                $z = $i - $corrector;
                $z++;
                $seenTab_is_active = '';
                if ($HaveDefaultTab == $currentTab) {
                    $seenTab_is_active = 'active in';
                }

                //
                //--- Count how many getPagedBlock() elemets we have:
                //

                $known_PagedBlocks = $this->BxPage->getPagedBlock();
                $num_PagedBlocks = count($known_PagedBlocks);

                if ($num_PagedBlocks === 1) {
                    $result .= '                                       <div id="tabs-' . $z . '" class="tab-pane fade ' . $seenTab_is_active . '" role="tabpanel">' . "\n";
                }
                else {
                    $result .= '                                       <div id="tabs-' . $id . '-' . $z . '" class="tab-pane fade ' . $seenTab_is_active . '" role="tabpanel">' . "\n";
                }

                $result .= $vertical_stretcher_open;

                // Check if the FormField belongs into this tab. If so, print it:
                foreach ($ms_FormFields['FFID'] as $IDnum => $FFvsTab) {
                    // Get the name of the FormField in question:
                    //$FFname = array_shift(array_keys($FFvsTab));
                    //$FFtab = array_shift(array_values($FFvsTab));
                    $FFname = array_keys($FFvsTab);
                    $FFname = array_shift($FFname);

                    $FFtab = array_values($FFvsTab);
                    $FFtab = array_shift($FFtab);

                    // Is the tab of this formfield in the tab that we're currently doing?
                    if ($FFtab == $currentTab) {
                        $formFieldObj = $ms_FormFields['FF'][$IDnum];

                        // Is this formfield optional?
                        $optional = "";
                        if ($formFieldObj->isOptional() && (strval($formFieldObj->isOptional()) != "silent")) {
                            $optional = "<FONT STYLE=\"\">($optionalStr)</FONT>";
                        }

                        // add dividers
                        //$my_dividers = array_shift(array_values($this->dividers));
                        $my_dividers = array_values($this->dividers);
                        $my_dividers = array_shift($my_dividers);

                        for ($j = 0;$j < count($this->dividers);$j++) {
                            // divider at the right position?
                            if (in_array($currentTab, $this->dividerPageIds)) {
                                if ($this->dividerPageIds[$j] == $FFtab) {

                                    if (($this->dividerIndexes[$j] <= $currentFFnum) && (!in_array($j, $already_shown))) {
                                        $labelObj = $this->dividers[$j];
                                        $label = is_object($labelObj) ? $labelObj->toHtml($labelObj) : "";
                                        $result .= '<div class="shade section"><b>' . $label . '</b></div>';
                                        $already_shown[] = $j;
                                    }
                                }
                            }
                        }

                        // Is the current tab in the array of $this->pageIds? If it is, we want to show the formFieldObj.
                        // If not, then we make this a hidden field instead.
                        if (!in_array($FFtab, $this->pageIds)) {
                            // formFieldObj is not on a visible tab, so make it a hidden field instead:
                            $access = $formFieldObj->getAccess();
                            $result_hidden .= $builder->makeHiddenField($formFieldObj->getId() , $formFieldObj->getValue());
                        }
                        else {
                            // formFieldObj is on a visible tab, so render it:
                            $currentFFnum++;

                            //
                            // -> ===============================================
                            // -> Assign the correct Labels to FormField Objects:
                            // -> ===============================================
                            //
                            // And here is the magic. Oh, the joys of object oriented PHP programming!
                            //
                            // We have the FormField Objects and we have the separate LabelObjects that
                            // say which label goes into which FormField. This is one of the places
                            // where we (at the latest!) need to merge them. But we can't do this somewhere
                            // in the other classes, because they have no access to the LabelObjects or
                            // only see the last LabelObject we just processed.
                            //
                            // So we take a little round about here:
                            //
                            // Around line 175 of this class we passed the LabelObject data to BxPage, so
                            // that it can keep track of it for us. Now we fetch that info back and stuff
                            // it manually back into the FormFiel Objects:
                            // Get the ID of the current FormFiel Object:
                            $formFieldObj_id = $formFieldObj->id;

                            // With $this->BxPage->getLabel($formFieldObj_id) we ask BXPage to pass us the
                            // label information of the corresponding label back to us. However, we need to
                            // make sure the info we get back from BxPage is an array. If not, this FF has
                            // no label:

                            if (is_array($this->BxPage->getLabel($formFieldObj_id))) {
                                foreach ($this->BxPage->getLabel($formFieldObj_id) as $label => $description) {
                                    // Stuff the label and the description into our FormField Object:
                                    if (!isset($formFieldObj->page)) {
                                        $formFieldObj->page = new stdClass();
                                    }
                                    $formFieldObj->page->Label = array(
                                        $label => $description
                                    );
                                    // Also manually set the current Object ID into that FormField Object, because
                                    // at this time that information might be incorrect:
                                    $formFieldObj->page->ID = array(
                                        "id" => $formFieldObj_id
                                    );
                                }
                            }
                            else {
                                // We have no label for this FormField:
                                $formFieldObj->page->Label = "";
                                // Also manually set the current Object ID into that FormField Object, because
                                // at this time that information might be incorrect:
                                $formFieldObj->page->ID = array(
                                    "id" => $formFieldObj_id
                                );
                            }

                            // Now there is one small catch, which is more of an imperfection: All FormField
                            // Objects now carry the field page->BXLabel() which contains an array with ALL
                            // labels that are on this page. But we will have to live with that.

                            $formField = $formFieldObj->toHtml();
                            $result .= $formField . "\n";

                        }
                    }
                }
                $result .= '                                       </div>' . "\n";
                $result .= $vertical_stretcher_close;
            }
        }

        $result .=<<<HTML

                                            </div><!-- /tab-content -->
                                        </div><!-- /panel-body -->
                                    </div><!-- /panel-wrapper -->
        HTML;

        $result .= $result_hidden;
        $result .= "\n" . '                            <!-- result_foot ' . $id . ' -->' . "\n";
        $result .= $result_foot;
        $result .= "\n" . '                            <!-- /result_foot ' . $id . ' -->' . "\n";
        $result .= $result_errors;
        $result .= "                    <!-- /PagedBlock $id -->" . "\n";

        return $result;
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
