<?php
// Author: Kevin K.M. Chiu
// Author: Michael Stauber
// $Id: HtmlComponentFactory.php
// description:
// This class represents a factory that manufactures HtmlComponent objects. It
// is designed to simplify the task of instantiating and initializing these
// objects. This factory handles the most common cases of how the objects are
// instantiated and initialized. This factory may not be able to handle rare
// cases. In these cases, users can instantiate the objects using constructors
// instead or fine tune the manufactured objects with accessor methods.
//
// applicability:
// Most pages that use UIFC can use this factory to manufacture objects. It is
// a handy tool, but it is not required.
//
// usage:
// Simply use the getHtmlComponentFactory() method of the ServerScriptHelper
// class to get a factory object. Alternatively, you can use the constructor to
// instantiate one. The form fields manufactured use <formFieldId>_invalid as
// i18n message ID for the message that says the form field is invalid and
// <formFieldId>_empty as the ID for the message that says the form field is
// empty. If these messages are not defined, default strings are used. These
// strings use <formFieldId> as message ID for the form field name and
// <formFieldId>_rule as message ID for the message that tells the validation
// rule of the form field. Labels manufactured use <labelId> as i18n message ID for
// the label text and <labelId>_help as ID for the description text.

global $isHtmlComponentFactoryDefined;
if ($isHtmlComponentFactoryDefined) return;
$isHtmlComponentFactoryDefined = true;

class HtmlComponentFactory {
    //
    // private variables
    //
    public $i18n;
    public $page;
    public $BxPage;
    public $SS;
    public $errors;
    public $LabelType;
    public $columnWidths;
    public $label;

    //
    // public methods
    //
    // description: constructor
    // param: i18n: an I18n object
    // param: formAction: the action for a form in string
    // param: SS: ServerScriptHelper object
    //function HtmlComponentFactory($stylist, $i18n, $formAction, $SS) {
    public function __construct($stylist, $i18n, $formAction, $SS) {

        $this->i18n = $i18n;
        $this->SS = $SS;

        /* start off with no errors */
        $this->errors = array();

        $SShelper = $this->SS->getBxPage();

        // We only want to instantiate BxPage once. Otherwise we loose
        // whatever information we already stored into it.
        if (!isset($SShelper)) {
            // Not yet there? Instantiate BxPage:
            include_once ("BxPage.php");
            $this->BxPage = new BxPage($stylist, $i18n, $formAction);
            $this->SS->BxPageTemp = $this->BxPage;
        }
        else {
            // There we go. BxPage is already instantiated. Re-use it:
            $this->BxPage = $this->SS->BxPageTemp;
        }

    }

    function getI18n() {
        return $this->i18n;
    }

    // description: manufacture a Page object
    // returns: a Page object
    public function getPage() {
        return $this->BxPage;
    }

    // Same as above: Returns a page object
    public function getBxPage() {
        return $this->BxPage;
    }

    // description: defines where the labels are placed on formfields:
    function setLabelType($type) {
        $this->LabelType = $type;
    }

    // Returns where the labels are placed on formfields:
    function getLabelType() {
        if (!isset($this->LabelType)) {
            $this->LabelType = "label_side top";
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
        if (!isset($this->columnWidths)) {
            $this->columnWidths = "";
        }
        return $this->columnWidths;
    }

    //
    //-- UIFC functions:
    //

    // description: manufacture a ScrollList object
    // param: id: the identifier in string
    // param: labelIds: an array of label IDs in string
    // param: sortables: an array of indexes of the sortable components. Optional
    // returns: a ScrollList object
    function getScrollList($id, $entryLabels = array() , $entries = array() , $sortables = array()) {
        include_once ("uifc2/ScrollList.php");
        $scrolllist = new ScrollList($this->BxPage, $id, $entryLabels, $entries, $sortables, $this->getI18n());
        return $scrolllist;
    }

    // description: manufacture a read-only Table object
    // param: id: the identifier in string
    // param: headers: optional array of header labels
    // param: rows: optional array of row arrays
    // returns: a Table object
    function getTable($id, $headers = array(), $rows = array()) {
        include_once ("uifc2/Table.php");
        $table = new Table($this->BxPage, $id, $headers, $rows, $this->getI18n());
        return $table;
    }

    // description: manufacture a PagedBlock object
    // param: id: id of the object
    // param: pageIds: an array of page IDs. optional
    // returns: a PagedBlock object
    function getPagedBlock($id, $pageIds = array(
        ""
    )) {
        include_once ("uifc2/PagedBlock.php");
        if (isset($pageIds[0])) {
            $block = new PagedBlock($this->BxPage, $id, "", $pageIds[0]);
            for ($i = 0;$i < count($pageIds);$i++) {
                $pageId = $pageIds[$i];
                $block->addPage($pageId, $this->getLabel($pageId));
            }
        }
        else {
            $block = new PagedBlock($this->BxPage, $id, "", $pageIds);
        }
        return $block;
    }

    // description: manufacture a SaveButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a SaveButton object
    function getSaveButton($action, $demo_override = false) {
        include_once ("uifc2/SaveButton.php");
        $save_button = new SaveButton($this->BxPage, $action, $demo_override);
        return $save_button;
    }

    // description: manufacture a SearchButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a SaveButton object
    function getSearchButton($action, $demo_override = false) {
        include_once ("uifc2/SearchButton.php");
        $search_button = new SearchButton($this->BxPage, $action, $demo_override);
        return $search_button;
    }

    // description: manufacture a FreeSaveButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a FreeSaveButton object. Which is the same as a "SaveButton"
    // object, but allows us to specify an alternate Label:
    function getFreeSaveButton($action, $name = "", $demo_override = false) {
        include_once ("uifc2/FreeSaveButton.php");
        return new FreeSaveButton($this->BxPage, $action, $name, $demo_override);
    }

    // description: manufacture a AddButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a AddButton object
    function getAddButton($action, $help = false, $demo_override = false) {
        include_once ("uifc2/AddButton.php");
        return new AddButton($this->BxPage, $action, $help, $demo_override);
    }

    // description: manufacture a BackButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a BackButton object
    function getBackButton($action, $demo_override = false) {
        include_once ("uifc2/BackButton.php");
        return new BackButton($this->BxPage, $action, $demo_override);
    }

    // description: manufacture a Button object
    // param: action: the string used within HREF attribute of the A tag
    // param: labelId: a message ID for i18n for the label
    // returns: a Button object
    function getButton($action, $labelId, $demo_override = false) {
        include_once ("uifc2/Button.php");
        include_once ("uifc2/Label.php");

        // Slightly complicated routine to determine the helptext of a Button:
        $pattern = '/\[\[[a-zA-Z0-9\-\_\.]{1,99}\]\]/';
        if (preg_match($pattern, $labelId, $matches)) {
            // Submitted Button-Label is fully qualified (i.e.: [[palette.done]]).
            // Which means we need to look up the helptext for it. For that we need
            // to extract the label identifier from between the double square brackets:
            $patterns[0] = '/\[\[/';
            $patterns[1] = '/\]\]/';
            $LabelValue = preg_replace($patterns, "", $labelId);
            $sanity = preg_split('/\./', $LabelValue);
            // $LabelValue now contains the raw identifier. Assume the corresponding
            // help text to be "identifier_help" and see what we get:
            $labelIdHelptext = $this->i18n->getWrapped("[[" . $LabelValue . "_help]]");
            if (isset($sanity[1])) {
                if ($sanity[1] . "_help<br>" == $labelIdHelptext) {
                    // If we get here, the Button Label had no dedicated helptext.
                    // In that case we set the helptext to the Label ID instead:
                    $labelIdHelptext = $this->i18n->getWrapped($labelId);
                }
            }
        }
        else {
            // Label is not fully qualified. In that case we have it easy:
            $labelIdHelptext = $this->i18n->getWrapped($labelId . "_help");
        }

        $NBLabel = new Label($this->BxPage, $this->i18n->get($labelId) , $labelIdHelptext);
        $theNewButton = new Button($this->BxPage, $action, $this->getLabel($labelId) , $NBLabel);
        if ($demo_override == "DEMO-OVERRIDE") {
            $theNewButton->setDemo(false);
        }
        return $theNewButton;
    }

    // description: manufacture a CancelButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a CancelButton object
    function getCancelButton($action) {
        include_once ("uifc2/CancelButton.php");
        return new CancelButton($this->BxPage, $action);
    }

    // description: manufacture a DetailButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a DetailButton object
    function getDetailButton($action) {
        include_once ("uifc2/DetailButton.php");
        return new DetailButton($this->BxPage, $action);
    }

    // description: manufacture a UrlButton object to open a link in a new window
    // param: action: the string used within HREF attribute of the A tag
    // returns: a UrlButton object
    function getUrlButton($action) {
        include_once ("uifc2/UrlButton.php");
        return new UrlButton($this->BxPage, $action);
    }

    // description: manufacture a LinkButton object to open a link in the same window
    // param: action: the string used within HREF attribute of the A tag
    // returns: a LinkButton object
    function getLinkButton($action) {
        include_once ("uifc2/LinkButton.php");
        return new LinkButton($this->BxPage, $action);
    }

    // description: manufacture an ImageButton object
    // param: action: the string used within HREF attribute of the A tag
    // param: image: image for the button
    // param: label: label for alt-text
    // param: description: string for help message
    function getImageButton($action, $image, $label = "", $descr = "") {
        include_once ("uifc2/ImageButton.php");
        return new ImageButton($this->BxPage, $action, $image, $label, $descr);
    }

    // description: manufacture an IpAddress object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an IpAddress object
    function getIpAddress($id, $value = "", $access = "rw") {
        include_once ("uifc2/IpAddress.php");
        $ipAddress = new IpAddress($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $ipAddress->setAccess($access);
        return $ipAddress;
    }

    // description: manufacture a ModifyButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a ModifyButton object
    function getModifyButton($action) {
        include_once ("uifc2/ModifyButton.php");
        return new ModifyButton($this->BxPage, $action);
    }

    // description: manufacture a RemoveButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a RemoveButton object
    function getRemoveButton($action, $demo_override = false) {
        include_once ("uifc2/RemoveButton.php");
        return new RemoveButton($this->BxPage, $action, $demo_override);
    }

    // description: manufacture a UninstallButton object
    // param: action: the string used within HREF attribute of the A tag
    // returns: a UninstallButton object
    function getUninstallButton($action) {
        include_once ("uifc2/UninstallButton.php");
        return new UninstallButton($this->BxPage, $action);
    }

    // description: manufacture a TextField object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a TextField object
    function getTextField($id, $value = "", $access = "rw") {
        include_once ("uifc2/TextField.php");
        $textField = new TextField($this->BxPage, $id, $value, $this->i18n, "alphanum_plus", $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $textField->setAccess($access);
        return $textField;
    }

    // description: manufacture an Integer object
    // param: id: id of the object
    // param: value: value of the object
    // param: min: the minimum acceptable value. Optional
    // param: max: the maximum acceptable value. Optional
    // param: access: access of the object. Optional
    // returns: an Integer object
    function getInteger($id, $value = "", $min = "", $max = "", $access = "rw") {
        include_once ("uifc2/Integer.php");
        $integer = new Integer($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        if (!($min === "")) {
            $integer->setMin($min);
        }
        if (!($max === "")) {
            $integer->setMax($max);
        }
        $integer->setAccess($access);
        return $integer;
    }

    // description: manufacture an DomainNameList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an DomainNameList object
    function getDomainNameList($id, $value = "", $access = "rw") {
        include_once ("uifc2/DomainNameList.php");
        $domainNameList = new DomainNameList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $domainNameList->setAccess($access);
        return $domainNameList;
    }

    // description: manufacture a Boolean object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a Boolean object
    function getBoolean($id, $value = false, $access = "rw") {
        include_once ("uifc2/Boolean.php");
        $boolean = new Boolean($this->BxPage, $id, $value, $this->i18n, $this->getLabel($id, true));
        //$this->BxPage->ID = array(
        //    "id" => $id
        //);
        $boolean->setAccess($access);
        return $boolean;
    }

    // description: manufacture a Radio select object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a Radio select object
    function getRadio($id, $value = false, $access = "rw") {
        include_once ("uifc2/Radio.php");
        $radio = new Radio($this->BxPage, $id, $value, $this->i18n, $this->getLabel($id, false));
        $radio->setAccess($access);
        return $radio;
    }

    // description: manufacture an EmailAddress object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an EmailAddress object
    function getEmailAddress($id, $value = "", $access = "rw") {
        include_once ("uifc2/EmailAddress.php");
        $emailAddress = new EmailAddress($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $emailAddress->setAccess($access);
        return $emailAddress;
    }

    // description: manufacture an Number object
    // param: id: id of the object
    // param: value: value of the object
    // param: min: the minimum acceptable value. Optional
    // param: max: the maximum acceptable value. Optional
    // param: access: access of the object. Optional
    // returns: an Number object
    function getNumber($id, $value = "", $min = "", $max = "", $access = "rw") {
        include_once ("uifc2/Number.php");
        $number = new Number($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));

        if (!($min === "")) $number->setMin($min);

        if (!($max === "")) $number->setMax($max);

        $number->setAccess($access);
        return $number;
    }

    // description: manufacture a MultiChoice object
    // param: id: id of the object
    // param: optionValues: an array of values of option objects
    // param: selectedValues: an array of selected values
    // param: access: access of the object. Optional
    // returns: a MultiChoice object
    function getMultiChoice($id, $optionValues = array() , $selectedValues = array() , $access = "rw") {
        include_once ("uifc2/MultiChoice.php");
        $multiChoice = new MultiChoice($this->BxPage, $id, $this->i18n);
        for ($i = 0;$i < count($optionValues);$i++) {
            $res_in_array = in_array($optionValues[$i], $selectedValues);
            $fts = $this->getOption($optionValues[$i], $res_in_array);
            $multiChoice->addOption($fts);
        }
        $multiChoice->setAccess($access);
        return $multiChoice;
    }

    // description: manufacture a VerticalCompositeFormField object
    // param: formFields: an array of FormField objects. Optional
    // param: delimiter: a string to delimit the form fields. Optional
    // param: access: access of the object. Optional
    // returns: a VerticalCompositeFormField object
    function getVerticalCompositeFormField($formFields = array() , $delimiter = null, $access = "rw") {
        include_once ("uifc2/VerticalCompositeFormField.php");
        $vertCompositeFormField = new VerticalCompositeFormField();

        for ($i = 0;$i < count($formFields);$i++) {
            $vertCompositeFormField->addFormField($formFields[$i]);
        }

        if ($delimiter != null) {
            $vertCompositeFormField->setDelimiter($delimiter);
        }

        $vertCompositeFormField->setAccess($access);
        return $vertCompositeFormField;
    }

    // description: manufacture a DomainName object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a DomainName object
    function getDomainName($id, $value = "", $access = "rw") {
        include_once ("uifc2/DomainName.php");
        $domainName = new DomainName($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $domainName->setAccess($access);
        return $domainName;
    }

    // description: manufacture a Label object
    // param: name: name of the label. It is also the message ID for I18n
    // param: isDescribed: true if description exists, false otherwise
    //     optional. true by default
    // param: nameI18nVars: a hash of variables for name I18n. optional
    // param: descriptionI18nVars: a hash of variables for description I18n. optional
    // returns: a Label object
    function getLabel($name, $isDescribed = true, $nameI18nVars = array() , $descriptionI18nVars = array()) {
        $description = "";
        if ($isDescribed == true) {
            $description = $this->i18n->getWrapped($name . "_help", "", $descriptionI18nVars);
        }
        else {
            $description = "";
        }
        include_once ("uifc2/Label.php");
        $label = new Label($this->BxPage, $this->i18n->get($name, "", $nameI18nVars) , $description, $this);
        $this->label = $label;
        return $label;
    }

    // description: manufacture a CompositeFormField object
    // param: formFields: an array of FormField objects. Optional
    // param: delimiter: a string to delimit the form fields. Optional
    // param: access: access of the object. Optional
    // returns: a CompositeFormField object
    function getCompositeFormField($formFields = array() , $delimiter = null, $access = "rw") {
        include_once ("uifc2/CompositeFormField.php");
        $compositeFormField = new CompositeFormField();

        for ($i = 0;$i < count($formFields);$i++) {
            $compositeFormField->addFormField($formFields[$i]);
        }

        if ($delimiter != null) {
            $compositeFormField->setDelimiter($delimiter);
        }

        if (is_array($this->getColumnWidths())) {
            $compositeFormField->setColumnWidths($this->getColumnWidths());
        }

        $compositeFormField->setAccess($access);
        return $compositeFormField;
    }

    // This is actually a new UIFC class that was not previously there. For various UIFC elements we have the ability
    // to use addDivider() to create a horizontal divider with text in it. However, these are not FormFields, but
    // separate Label objects that only work within their specified parent UIFC objects such as getCompositeFormField().
    // Even there it works only due to some messy stuff. Therefore this BxDivider() creates an universal FormField that
    // acts as a drop in replacement for addDivider().
    // description: manufacture a FormField objct that acts as Divider.
    // param: id: id of the object
    // returns: a Divider object
    function addBXDivider($id, $label = "") {
        include_once ("uifc2/BXDivider.php");
        $BXDivider = new BxDivider($this->BxPage, $id, $label, $this->i18n, "", "");
        return $BXDivider;
    }

    // description: manufacture a Bar object
    // param: id: id of the object
    // param: value: percentage
    // param: label: a label in string. Optional
    // returns: a Bar object
    function getBar($id, $value, $label = "") {
        include_once ("uifc2/Bar.php");
        $bar = new Bar($this->BxPage, $id, $value, $this->i18n);
        $bar->setLabel($label);
        $bar->setBarText($value . "%");
        return $bar;
    }

    // description: manufacture an CountryName object
    // param: id: id of the object
    // param: value: selected value of the object
    // param: access: access of the object. Optional
    // returns: an CountryName object
    function getCountryName($id, $value, $access = "rw") {
        include_once ("uifc2/CountryName.php");
        $country = new CountryName($this->BxPage, $id, $value, $this->i18n);
        $country->setAccess($access);
        return $country;
    }

    // description: manufacture an GeoIP object
    // param: id: id of the object
    // param: value: selected values of the object
    // param: access: access of the object. Optional
    // returns: an GeoIP object
    function getGeoIP($id, $value, $access = "rw") {
        include_once ("uifc2/GeoIP.php");
        $geoip = new GeoIP($this->BxPage, $id, $value, $this->i18n);
        $geoip->setAccess($access);
        return $geoip;
    }

    // description: manufacture an EmailAddressList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an EmailAddressList object
    function getEmailAddressList($id, $value = "", $access = "rw") {
        include_once ("uifc2/EmailAddressList.php");
        $emailAddressList = new EmailAddressList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $emailAddressList->setAccess($access);
        return $emailAddressList;
    }

    // description: manufacture an EmailAliasList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an EmailAliasList object
    function getEmailAliasList($id, $value = "", $access = "rw") {
        include_once ("uifc2/EmailAliasList.php");
        $emailAliasList = new EmailAliasList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $emailAliasList->setAccess($access);
        return $emailAliasList;
    }

    // description: manufacture a FullName object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a FullName object
    function getFullName($id, $value = "", $access = "rw") {
        include_once ("uifc2/FullName.php");
        $fullName = new FullName($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $fullName->setAccess($access);
        return $fullName;
    }

    // description: manufacture an UserName object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an UserName object
    function getUserName($id, $value = "", $access = "rw") {
        include_once ("uifc2/UserName.php");
        $userName = new UserName($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $userName->setAccess($access);
        return $userName;
    }

    // description: manufacture an Locale object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an Locale object
    function getLocale($id, $value = "", $access = "rw") {
        include_once ("uifc2/BXLocale.php");
        $ipAddress = new BXLocale($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $ipAddress->setAccess($access);
        return $ipAddress;
    }

    // description: manufacture a Password object
    // param: id: id of the object
    // param: value: value of the object
    // param: isConfirm: true to confirm password, false otherwise
    //     Optional and true by default
    // param: access: access of the object. Optional
    // returns: a Password object
    function getPassword($id, $value = "", $isConfirm = true, $access = "rw", $checkpass = true) {
        include_once ("uifc2/Password.php");
        $password = new Password($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $password->setConfirm($isConfirm);
        $password->setCheckPass($checkpass);
        $password->setAccess($access);
        return $password;
    }

    // This is for the laaaaaazy way to get native HTML code into your pages.
    // It simply returns whatever "values" (your HTML code) you stuffed into it.
    // This is done via uifc2/RawHTML.php and by adding a FormField via something
    // like $factory->getRawHTML("applet", $applet)
    function getRawHTML($id, $value = "") {
        include_once ("uifc2/RawHTML.php");
        $rawhtml = new RawHTML($this->BxPage, $id, $value, $this->i18n);
        return $rawhtml;
    }

    // description: manufacture an Option object
    // param: value: value of the object
    // param: isSelected: true if selected, false otherwise. Optional
    // returns: an Option object
    function getOption($value, $isSelected = false, $access = "rw") {
        include_once ("uifc2/Option.php");
        $newLabel = $this->getLabel($value, false);
        $new_option = new Option($newLabel, $value, $isSelected, $this->i18n);
        $new_option->setAccess($access);
        return $new_option;
    }

    // description: manufacture a NetAddress object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a NetAddress object
    function getNetAddress($id, $value, $access = "rw") {
        include_once ("uifc2/NetAddress.php");
        $netAddress = new NetAddress($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $netAddress->setAccess($access);
        $netAddress->setMaxLength("25");
        $netAddress->setWidth("25");
        return $netAddress;
    }

    // description: manufacture a NetAddressList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a NetAddressList object
    function getNetAddressList($id, $value, $access = "rw") {
        include_once ("uifc2/NetAddressList.php");
        $netAddressList = new NetAddressList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $netAddressList->setAccess($access);
        return $netAddressList;
    }

    // description: manufacture an area with a button. When clicked it expands a list of hidden other FormFields below it.
    // param: id: id of the object
    // param: button: button-object
    // param: access: access of the object. Optional
    // returns: a list of objects
    function getButtonContainer($id, $button, $access = "rw") {
        include_once ("uifc2/ButtonContainer.php");
        $buttonContainer = new ButtonContainer($this->BxPage, $id, $button, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $buttonContainer->setAccess($access);
        return $buttonContainer;
    }

    // description: manufacture an ImageLabel object
    // param: name: name of the label. It is also the message ID for I18n
    // param: image: an URL of an image
    // param: isDescribed: true if description exists, false otherwise
    //     optional. true by default
    // param: nameI18nVars: a hash of variables for name I18n. optional
    // param: descriptionI18nVars: a hash of variables for description I18n. optional
    // returns: an ImageLabel object
    function getImageLabel($name, $image, $isDescribed = true, $nameI18nVars = array() , $descriptionI18nVars = array()) {
        $description = "";
        if ($isDescribed) {
            $description = $this->i18n->get($name . "_help", "", $descriptionI18nVars);
        }
        include_once ("uifc2/ImageLabel.php");
        return new ImageLabel($this->BxPage, $image, $this->i18n->get($name, "", $nameI18nVars) , $description);
    }

    // description: manufacture an UrlButton object using FancyBox
    // param: action: the string used within HREF attribute of the A tag
    // returns: a FancyButton object
    function getFancyButton($action, $name = "", $demo_override = false) {
        include_once ("uifc2/FancyButton.php");
        return new FancyButton($this->BxPage, $action, $name, $demo_override);
    }

    // description: manufacture an UrlButton object using FancyBox
    // param: action: the string used within HREF attribute of the A tag
    // returns: a FancyButton object
    function getFancyTextButton($action, $name = "", $demo_override = false) {
        include_once ("uifc2/FancyTextButton.php");
        return new FancyTextButton($this->BxPage, $action, $name, $demo_override);
    }

    // description: manufacture a InetAddress object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a InetAddressList object
    function getInetAddress($id, $value, $access = "rw") {
        include_once ("uifc2/InetAddress.php");
        $inetAddress = new InetAddress($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $inetAddress->setAccess($access);
        return $inetAddress;
    }

    // description: manufacture a InetAddressList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a InetAddressList object
    function getInetAddressList($id, $value, $access = "rw") {
        include_once ("uifc2/InetAddressList.php");
        $inetAddressList = new InetAddressList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $inetAddressList->setAccess($access);
        return $inetAddressList;
    }

    // description: manufacture an IpAddressList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an IpAddressList object
    function getIpAddressList($id, $value, $access = "rw") {
        include_once ("uifc2/IpAddressList.php");
        $ipAddressList = new IpAddressList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $ipAddressList->setAccess($access);
        return $ipAddressList;
    }

    // description: manufacture an TextList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a TextList object
    function getTextList($id, $value = "", $access = "rw") {
        include_once ("uifc2/TextList.php");
        $textList = new TextList($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $textList->setAccess($access);
        return $textList;
    }

    // description: manufacture a SnmpCommunity object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a SnmpCommunity object
    function getSnmpCommunity($id, $value, $access = "rw") {
        include_once ("uifc2/SnmpCommunity.php");
        $snmpCommunity = new SnmpCommunity($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $snmpCommunity->setAccess($access);
        return $snmpCommunity;
    }

    // description: manufacture a TextBlock object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a TextBlock object
    function getTextBlock($id, $value = "", $access = "rw") {
        include_once ("uifc2/TextBlock.php");
        $textBlock = new TextBlock($this->BxPage, $id, $value, $this->i18n, $this->_getEmptyMessage($id));
        $textBlock->setAccess($access);
        return $textBlock;
    }

    // description: manufacture a StatusSignal object
    // param: status: the status string
    // param: url: the url to which to link. Optional
    // returns: a Label object
    function getStatusSignal($status, $url = "") {
        include_once ("uifc2/StatusSignal.php");
        return new StatusSignal($this->BxPage, $status, $url, $this->i18n);
    }

    // description: manufacture a TimeStamp object
    // param: id: id of the object
    // param: value: value of the object
    // param: format: can be "date","time" or "datetime". Optional
    // param: access: access of the object. Optional
    // returns: a TimeStamp object
    function getTimeStamp($id, $value, $format = "", $access = "rw") {
        include_once ("uifc2/TimeStamp.php");
        $timeStamp = new TimeStamp($this->BxPage, $id, $value, $this->i18n);
        if ($format != "") {
            $timeStamp->setFormat($format);
            $timeStamp->setAccess($access);
        }
        return $timeStamp;
    }

    // description: manufacture a TimeZone object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a TimeZone object
    function getTimeZone($id, $value, $access = "rw") {
        include_once ("uifc2/TimeZone.php");
        $timeZone = new TimeZone($this->BxPage, $id, $value, $this->i18n);
        $timeZone->setAccess($access);
        return $timeZone;
    }

    // description: manufacture a FileUpload object
    // param: id: id of the object
    // param: value: the path
    // param: maxFileSize: the maximum file size allowed to upload in bytes.
    //     Optional
    // param: access: access of the object. Optional
    // returns: a FileUpload object
    function getFileUpload($id, $value = "", $maxFileSize = "", $access = "rw") {
        include_once ("uifc2/FileUpload.php");
        $fileUpload = new FileUpload($this->BxPage, $id, $value, $this->i18n, $maxFileSize, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $fileUpload->setAccess($access);
        return $fileUpload;
    }

    // description: manufacture a SetSelector object
    // param: id: id of the object
    // param: value: an ampersand "&" separated list for the value set
    // param: entries: an ampersand "&" separated list for the entry set
    // param: valueLabelId: a label ID for the value field. Optional
    // param: entriesLabelId: a label ID for the entries field. Optional
    // param: access: access of the object. Optional
    // returns: a SetSelector object
    function getSetSelector($id, $value, $entries, $valueLabelId = "", $entriesLabelId = "", $access = "rw", $valueVals = "", $entriesVals = "", $rows = "") {
        include_once ("uifc2/SetSelector.php");
        $setSelector = new SetSelector($this->BxPage, $id, $value, $this->i18n, $entries, $this->_getEmptyMessage($id) , $valueVals, $entriesVals);

        if ($valueLabelId != "") {
            $setSelector->setValueLabel($this->getLabel($valueLabelId, false));
        }
        if ($entriesLabelId != "") {
            $setSelector->setEntriesLabel($this->getLabel($entriesLabelId, false));
        }
        if ($rows != "") {
            $setSelector->rows = $rows;
        }

        $setSelector->setAccess($access);
        return $setSelector;
    }

    // description: manufacture a HtmlField object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a TextField object
    function getHtmlField($id, $value = "", $access = "rw") {
        include_once ("uifc2/HtmlField.php");
        $htmlField = new HtmlField($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $htmlField->setAccess($access);
        return $htmlField;
    }

    // description: manufacture a MacAddress object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a MacAddress object
    function getMacAddress($id, $value = "", $access = "rw") {
        include_once ("uifc2/MacAddress.php");
        $macAddress = new MacAddress($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $macAddress->setAccess($access);
        return $macAddress;
    }

    // description: manufacture a BarGraph object
    // param: id: id of the object
    // param: value: value of the object
    // returns: a BarGraph object
    function getBarGraph($id, $value = "", $xlabels = "") {
        include_once ("uifc2/BarGraph.php");
        $BarGraph = new BarGraph($this->BxPage, $id, $value, $xlabels);
        return $BarGraph;
    }

    // description: manufacture a PieChart object
    // param: id: id of the object
    // param: value: value of the object
    // returns: a PieChart object
    function getPieChart($id, $value = "") {
        include_once ("uifc2/PieChart.php");
        $PieChart = new PieChart($this->BxPage, $id, $value);
        return $PieChart;
    }

    // description: manufacture an Url object
    // param: page: the Page object this form field lives in
    // param: id: the identifier of this object
    // param: value: the URL
    // param: label: label of the object. Optional
    //     If not supplied, i18n is used fetch it using id as message ID
    // param: target: target attribute of the A tag. Optional
    // param: access: access of the object. Optional
    // returns: an Url object
    function getUrl($id, $value = "", $label = "", $target = "", $access = "rw") {
        include_once ("uifc2/Url.php");
        $label = ($label == "") ? $this->i18n->get($id) : $label;
        $url = new Url($this->BxPage, $id, $value, $this->i18n, $label, $target, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $url->setAccess($access);
        return $url;
    }

    // description: manufacture an UrlList object
    // param: id: id of the object
    // param: value: value of the object
    // param: labels: an array of label strings. Optional
    // param: targets: an array of TARGET attribute strings for A tags. Optional
    // param: access: access of the object. Optional
    // returns: an UrlList object
    function getUrlList($id, $value, $labels = array() , $targets = array() , $access = "rw") {
        include_once ("uifc2/UrlList.php");
        $urlList = new UrlList($this->BxPage, $id, $value, $this->i18n, $labels, $targets, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $urlList->setAccess($access);
        return $urlList;
    }

    // description: manufacture an MailListName object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an MailListName object
    function getMailListName($id, $value, $access = "rw") {
        include_once ("uifc2/MailListName.php");
        $mailListName = new MailListName($this->BxPage, $id, $value, $this->i18n, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $mailListName->setAccess($access);
        return $mailListName;
    }

    // description: manufacture a SimpleBlock object
    // param: labelId: a message ID for i18n for the label
    // returns: a SimpleBlock object
    function getSimpleBlock($labelId, $i18n = "") {
        include_once ("uifc2/SimpleBlock.php");
        return new SimpleBlock($this->BxPage, $this->getLabel($labelId, false) , $i18n);
    }

    // description: manufacture a MultiButton object
    // param: textId: i18n ID of the text of the object
    // param: actions: an array of actions
    // param: actionTextIds: an array of i18n ID of the text for the actions
    // returns: a MultiButton object
    function getMultiButton($textId, $actions = array() , $actionTextIds = array()) {
        include_once ("uifc2/MultiButton.php");
        $multiButton = new MultiButton($this->BxPage, $this->i18n->get($textId));
        for ($i = 0;$i < count($actions);$i++) $multiButton->addAction($actions[$i], $this->i18n->get($actionTextIds[$i]));
        return $multiButton;
    }

    /**
     * Added for Elmer:
     */

    // description: manufacture an UrlButton object using FancyBox
    // param: action: the string used within HREF attribute of the A tag
    // returns: a FancyButton object
    function getFeatureButton($action='javascript:void(0);', $name = "", $demo_override = false) {
        include_once ("uifc2/FeatureButton.php");
        return new FeatureButton($this->BxPage, $action, $name, $demo_override);
    }

    /**
     *
     * @param
     * @param <---> NOTE: Below this line is old ballast that has not yet been modified for BlueOnyx 520XR:
     * @param
     *
     */

    // description: manufacture a FormFieldList object
    // param: id: id of the object
    // param: value: an ampersand "&" separated list of default values
    // param: formFieldClass: the name of the form field class that form the list
    // param: access: access of the object. Optional
    // returns: a FormFieldList object
    function getFormFieldList($id, $value, $formFieldClass, $access = "rw") {
        include_once ("uifc2/FormFieldList.php");
        include_once ("uifc2/$formFieldClass.php");
        $formFieldList = new FormFieldList($this->BxPage, $id, $value, $formFieldClass, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $formFieldList->setAccess($access);
        return $formFieldList;
    }

    //
    //--- Added for Elmer:
    //

    // description: manufacture a FormFieldList object
    // param: id: id of the object
    // param: value: an ampersand "&" separated list of default values
    // param: formFieldClass: the name of the form field class that form the list
    // param: access: access of the object. Optional
    // returns: a FormFieldList object
    function getDatePicker($id, $value, $access = "rw") {
        include_once ("uifc2/DatePicker.php");
        $datePicker = new DatePicker($this->BxPage, $id, $value, $this->i18n);
        $datePicker->setAccess($access);
        $datePicker->setCurrentLabel($id);
        return $datePicker;
    }

    //
    // --> DEPRECATED and not working anymore: DO NOT USE THESE!
    //
    // description: manufacture a PlainBlock object
    // param: labelId: a message ID for i18n for the label
    // returns: a PlainBlock object
    function getPlainBlock($labelId) {
        include_once ("uifc2/deprecated/PlainBlock.php");
        return new PlainBlock($this->BxPage, $this->getLabel($labelId, false));
    }

    function getIntRange($id, $value = "", $access = "rw") {
        include_once ("uifc2/deprecated/IntRange.php");
        $pr = new IntRange($this->BxPage, $id, $value, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $pr->setAccess($access);
        return $pr;
    }

    // description: manufacture a  SimpleText object (well, duh!)
    // param: text: the text you want to output
    // param: style: a style object
    // returns: a SimpleText object
    function getSimpleText($text, $style = "") {
        include_once ("uifc2/deprecated/SimpleText.php");
        return new SimpleText($text, $style);
    }

    // description: manufacture an UserNameList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an UserNameList object
    function getUserNameList($id, $value, $access = "rw") {
        include_once ("uifc2/deprecated/UserNameList.php");
        $userNameList = new UserNameList($this->BxPage, $id, $value, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $userNameList->setAccess($access);
        return $userNameList;
    }

    // description: manufacture an MacAddressList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an MacAddressList object
    function getMacAddressList($id, $value, $access = "rw") {
        include_once ("uifc2/deprecated/MacAddressList.php");
        $MacAddressList = new MacAddressList($this->BxPage, $id, $value, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $MacAddressList->setAccess($access);
        return $MacAddressList;
    }

    // description: manufacture a GroupName object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: a GroupName object
    function getGroupName($id, $value = "", $access = "rw") {
        include_once ("uifc2/deprecated/GroupName.php");
        $groupName = new GroupName($this->BxPage, $id, $value, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $groupName->setAccess($access);
        return $groupName;
    }

    // description: manufacture a MultiFileUpload object
    // param: id: id of the object
    // param: value: the path
    // param: maxFileSize: the maximum file size allowed to upload in bytes.
    //     Optional
    // param: access: access of the object. Optional
    // returns: a MultiFileUpload object
    function getMultiFileUpload($id, $value = "", $maxFileSize = "", $access = "rw") {
        include_once ("uifc2/deprecated/MultiFileUpload.php");
        $multiFileUpload = new MultiFileUpload($this->BxPage, $id, $value, $maxFileSize, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $multiFileUpload->setAccess($access);
        return $multiFileUpload;
    }

    // description: manufacture a Vertical Bar object
    // param: id: id of the object
    // param: value: percentage
    // param: label: a label in string. Optional
    // returns: a Bar object
    //
    // --> Note by mstauber: Deprecated, because it's not use anywhere.
    //
    function getVerticalBar($id, $value, $label = "") {
        include_once ("uifc2/deprecated/Bar.php");
        $bar = new Bar($this->BxPage, $id, $value);
        $bar->setVertical();
        $bar->setLabel($label);
        return $bar;
    }

    // description: manufacture an BlanketDomainList object
    // param: id: id of the object
    // param: value: value of the object
    // param: access: access of the object. Optional
    // returns: an BlanketDomainList object
    // just like DomainNameList but allows prefixed period chcaracters per-domain
    //
    // --> Note by mstauber: Deprecated, because it's not use anywhere.
    //
    function getBlanketDomainList($id, $value, $access = "rw") {
        include_once ("uifc2/deprecated/BlanketDomainList.php");
        $blanketDomainList = new BlanketDomainList($this->BxPage, $id, $value, $this->_getInvalidMessage($id) , $this->_getEmptyMessage($id));
        $blanketDomainList->setAccess($access);
        return $blanketDomainList;
    }

    /**
     *
     * @param
     * @param <---> private methods:
     * @param
     *
     */

    function _getEmptyMessage($id) {
        $tag = $id . "_empty";
        $message = $this->i18n->get($tag);

        // message not found?
        if ($message == $tag) {
            // find rule
            $ruleTag = $id . "_rule";
            $rule = $this->i18n->get($ruleTag);
            if ($rule == $ruleTag) {
                $rule = "";
            }

            $message = $this->i18n->get("defaultEmptyMessage", "palette", array(
                "name" => $this->i18n->get($id) ,
                "rule" => $rule
            ));
        }

        return $message;
    }

    function _getInvalidMessage($id) {
        $tag = $id . "_invalid";
        $message = $this->i18n->getJs($tag);

        // message not found?
        if ($message == $tag) {
            // find rule
            $ruleTag = $id . "_rule";
            $rule = $this->i18n->get($ruleTag);
            if ($rule == $ruleTag) {
                $rule = "";
            }

            $message = $this->i18n->get("defaultInvalidMessage", "palette", array(
                "name" => $this->i18n->get($id) ,
                "rule" => $rule
            ));
        }

        return $message;
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
