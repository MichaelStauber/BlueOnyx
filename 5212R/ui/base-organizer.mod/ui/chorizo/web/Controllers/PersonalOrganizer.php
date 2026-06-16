<?php 
namespace Organizer\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PersonalOrganizer extends BaseController {

    /**
     * Constructor.
     *
     */
    public function __construct() {
        
    }
    
    /**
     * Index
     *
     * @return View
     */
    public function index() {

        $CI = get_instance();

        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get Ducks lined up: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();
        $user = $BX_SESSION['loginUser'];

        // get Radicale info from CCE:
        $radicale = $CI->cceClient->getObject("System", array(), "Radicale");

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-organizer", "/organizer/personalOrganizer");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        $possible_collection_tags = array('VCALENDAR', 'VADDRESSBOOK');

        $payload = '';
        $calendars = [];
        $addressbooks = [];

        $ret = $CI->serverScriptHelper->shell("/usr/sausalito/bin/radicale_list.pl " . $BX_SESSION['loginName'], $payload, 'root', $BX_SESSION['sessionId']);

        if ($ret == 0) {
            // Decode the returned JSON data into an associative array:
            $data = json_decode($payload, true);

            // Check if decoding was successful
            if ($data === null) {
                $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorDecodingCollection]]"));
            }

            if (isset($data['props'])) {
                // Get the "props" array from $data
                $props = $data['props'];

                // Loop through each key-value pair in "props"
                foreach ($props as $key => $value) {
                    // Decode the JSON value
                    $decodedValue = json_decode($value, true);

                    // Check if decoding was successful
                    if ($decodedValue === null) {
                        echo "Error decoding JSON value for key: $key\n";
                        continue;
                    }

                    // Update the value in the "props" array with the decoded value
                    $props[$key] = $decodedValue;
                }

                // Update the modified "props" array back in $data
                $data['props'] = $props;

                // Isolate Calendars and Addressbooks (if present):
                $props = $data['props'];
                foreach ($props as $key => $prop) {
                    if (isset($prop['tag']) && $prop['tag'] === 'VCALENDAR') {
                        $calendars[$key] = $prop;
                    } elseif (isset($prop['tag']) && $prop['tag'] === 'VADDRESSBOOK') {
                        $addressbooks[$key] = $prop;
                    }
                }
            }
            else {
                $errors[] = ErrorMessage($i18n->get("[[base-organizer.ErrorDecodingCollection]]"));
            }

        }
        else {
            // No Collection data yet!
            $data = array();
        }

        //
        //-- Generate page:
        //

        // Prepare Page:
        $BxPage->setFormUrl("/organizer/personalOrganizer");
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_personalProfile';

        $defaultPage = "basicSettingsTab";

        $block = $factory->getPagedBlock("radicale_server_long", array($defaultPage));
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage($defaultPage);


        if ($radicale['enabled'] == '0') {
            // Radicale is disabled. Show info text:

            // Show a text description why this is turned off and what it can do if turned on:
            $radicale_off_desc = $factory->getHtmlField("radicale_off_desc", "<br>" . $i18n->get("[[base-organizer.radicale_off_desc]]"), 'r');
            $radicale_off_desc->setLabelType("nolabel");
            $block->addFormField(
                    $radicale_off_desc,
                    $factory->getLabel("radicale_off_desc"),
                    $defaultPage
                    );
        }
        else {

            // Button: Add Collection:
            $addCollection = $factory->getAddButton("/organizer/addCollection?user=" . $BX_SESSION['loginName'], '[[base-organizer.create_collection]]');

            // Button: Backup All
            $BackupAll_button = $factory->getButton("/organizer/organizerradall?action=backup", 'BackupAll');
            $BackupAll_button->setIcon('fa fa-cloud-download');

            // Button: Restore All
            $RestoreAll_button = $factory->getButton("/organizer/organizerradall?action=restore", 'RestoreAll');
            $RestoreAll_button->setIcon('fa fa-repeat');

            // Button: Full GUI
            $FullGUI_button = $factory->getButton("/organizer/personalOrganizerExt", 'FullGUI');
            $FullGUI_button->setIcon('fa fa-external-link');

            $addCollection = $factory->getButtonContainer("", array($addCollection, $BackupAll_button, $RestoreAll_button, $FullGUI_button));
            $xxx = $factory->getRawHTML("CollectionAddBut", $addCollection->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("CollectionAddBut"),
                $defaultPage
            );

            // Build Addressbooklist:
            $AddyList = array();
            $num_Addys = '0';

            // Build Calendarlist:
            $CalList = array();
            $num_Cals = '0';

            // Localization of common buttons:
            $click_to_copy_to_clipboard = $i18n->get("[[base-organizer.click_to_copy_to_clipboard]]");
            $url_copied_to_clipboard = $i18n->get("[[base-organizer.url_copied_to_clipboard]]");
            $click_to_edit_entry = $i18n->get("[[base-organizer.click_to_edit_entry]]");
            $click_to_delete_entry = $i18n->get("[[base-organizer.click_to_delete_entry]]");

            // Set alert handler for 'copy_to_clipboard' buttons:
            $BxPage->setExtraHeaders('
                <script>
                function copyToClipboard(url) {
                  var dummyElement = document.createElement(\'textarea\');
                  document.body.appendChild(dummyElement);
                  dummyElement.value = url;
                  dummyElement.select();
                  document.execCommand(\'copy\');
                  document.body.removeChild(dummyElement);
                  alert(\'' . addslashes($url_copied_to_clipboard) . ' \' + url);
                }
                </script>');

            // Create URL-Prefix:
            if ($BX_SESSION['loginUser']['site'] == '') {
                // We are not member of a Vsite. Use Server FQDN for $url:
                $url_prefix = 'https://' . $_SERVER['SERVER_NAME'] . '/radicale/' . $BX_SESSION['loginName'] . '/';
            }
            else {
                // We are a Vsite member. Get data for the Vsite:
                $vsite = $CI->cceClient->getObject('Vsite', array('name' => $BX_SESSION['loginUser']['site']));
                $vsiteSSL = $CI->cceClient->get($vsite['OID'], 'SSL');

                // Does the Vsite have SSL enabled?
                if ($vsiteSSL['enabled'] == '1') {
                    $url_prefix = 'https://' . $vsite['fqdn'] . '/radicale/' . $BX_SESSION['loginName'] . '/';
                }
                else {
                    $url_prefix = 'http://' . $vsite['fqdn'] . '/radicale/' . $BX_SESSION['loginName'] . '/';
                }
            }

            //
            //--- Addressbooks:
            //

            if (is_array($addressbooks)) {

                foreach ($addressbooks as $dir => $addy_entry) {
                    if ((isset($addy_entry['D:displayname'])) && (isset($addy_entry['CR:addressbook-description']))) {
                        $label = $factory->getLabel($addy_entry['D:displayname']);
                        $label->setLabel($addy_entry['D:displayname']);
                        $label->setDescription($addy_entry['CR:addressbook-description']);
                        $label->setStyleTarget("labelLabel");
                        $AddyList[0][$num_Addys] = $label->toHtml();
                    }
                    elseif ((isset($addy_entry['D:displayname'])) && (!isset($addy_entry['CR:addressbook-description']))) {
                        $label = $factory->getLabel($addy_entry['D:displayname']);
                        $label->setLabel($addy_entry['D:displayname']);
                        $label->setDescription($addy_entry['D:displayname']);
                        $label->setStyleTarget("labelLabel");
                        $AddyList[0][$num_Addys] = $label->toHtml();
                    }
                    else {
                        $AddyList[0][$num_Addys] = 'n/a';
                    }
                    if (isset($addy_entry['{http://inf-it.com/ns/ab/}addressbook-color'])) {
                        $color = $addy_entry['{http://inf-it.com/ns/ab/}addressbook-color'];
                    }
                    else {
                        $color = '#1C5EA0';
                    }
                    $AddyList[1][$num_Addys] = '<div style="background-color: ' . $color . '; width: 45px; height: 15px;"></div>';;

                    // Date of last backup:
                    if (isset($data['backup'][$dir])) {
                        $AddyList[2][$num_Addys] = $data['backup'][$dir];
                    }
                    else {
                        $AddyList[2][$num_Addys] = 'n/a';
                    }

                    // Create URL:
                    $url = $url_prefix . $dir;

                    // Create Buttons:
                    $button_list = '';

                    $copy_to_clipboard_button = $factory->getModifyButton($url);
                    $copy_to_clipboard_button->setDescription($click_to_copy_to_clipboard);
                    $copy_to_clipboard_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $copy_to_clipboard_button->setButtonSize("xs");
                    }
                    $copy_to_clipboard_button->setButtonSpecialStyle('square_animated');
                    $copy_to_clipboard_button->setIcon('fa fa-copy');
                    $copy_to_clipboard_button->setImageOnly(TRUE);
                    $copy_to_clipboard_button->setTarget('copyToClipboard');
                    $button_list .= $copy_to_clipboard_button->toHtml();

                    $edit_button = $factory->getModifyButton("/organizer/personalOrganizerEdit?id=$dir");
                    $edit_button->setDescription($click_to_edit_entry);
                    $edit_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $edit_button->setButtonSize("xs");
                    }
                    $edit_button->setButtonSpecialStyle('square_animated');
                    $edit_button->setIcon('fa fa-edit');
                    $edit_button->setImageOnly(TRUE);
                    $edit_button->setTarget('_self');
                    $button_list .= $edit_button->toHtml();

                    if ($data['backup'][$dir] != 'n/a') {

                        $baLoad_button = $factory->getModifyButton("/organizer/babackup?file=$dir&action=load&db=$dir");
                        $baLoad_button->setDescription($i18n->getWrapped("baLoad"));
                        $baLoad_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $baLoad_button->setButtonSize("xs");
                        }
                        $baLoad_button->setButtonSpecialStyle('square_animated');
                        $baLoad_button->setIcon('fa fa-repeat');
                        $baLoad_button->setImageOnly(TRUE);
                        $baLoad_button->setTarget('_self');
                        $button_list .= $baLoad_button->toHtml();

                    }
                    else {

                        $baLoad_button = $factory->getModifyButton('javascript:void(0)');
                        $baLoad_button->setDescription($i18n->getWrapped("baLoad"));
                        $baLoad_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $baLoad_button->setButtonSize("xs");
                        }
                        $baLoad_button->setButtonSpecialStyle('square_animated');
                        $baLoad_button->setIcon('fa fa-repeat');
                        $baLoad_button->setImageOnly(TRUE);
                        $baLoad_button->setTarget('_self');
                        $baLoad_button->setButtonColor('default');
                        $baLoad_button->setButtonDisabled(TRUE);
                        $button_list .= $baLoad_button->toHtml();

                    }

                    $createBackup_button = $factory->getModifyButton("/organizer/babackup?file=$dir&action=back&db=$dir");
                    $createBackup_button->setDescription($i18n->getHtml("baBackup"));
                    $createBackup_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $createBackup_button->setButtonSize("xs");
                    }
                    $createBackup_button->setIcon('fa fa-cloud-download');
                    $createBackup_button->setButtonSpecialStyle('square_animated');
                    $createBackup_button->setImageOnly(TRUE);
                    $createBackup_button->setTarget('_self');
                    $button_list .= $createBackup_button->toHtml();

                    if ($data['backup'][$dir] != 'n/a') {

                        $download_button = $factory->getModifyButton("/organizer/badownload?file=$dir&action=down");
                        $download_button->setDescription($i18n->getHtml("baDownload"));
                        $download_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $download_button->setButtonSize("xs");
                        }
                        $download_button->setIcon('fa fa-download');
                        $download_button->setButtonSpecialStyle('square_animated');
                        $download_button->setImageOnly(TRUE);
                        $download_button->setTarget('_self');
                        $button_list .= $download_button->toHtml();

                    }
                    else {

                        $download_button = $factory->getModifyButton('javascript:void(0)');
                        $download_button->setDescription($i18n->getHtml("baDownload"));
                        $download_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $download_button->setButtonSize("xs");
                        }
                        $download_button->setIcon('fa fa-download');
                        $download_button->setButtonSpecialStyle('square_animated');
                        $download_button->setImageOnly(TRUE);
                        $download_button->setTarget('_self');
                        $download_button->setButtonColor('default');
                        $download_button->setButtonDisabled(TRUE);                        
                        $button_list .= $download_button->toHtml();

                    }

                    $delete_button = $factory->getRemoveButton("/organizer/delCollection?id=$dir");
                    $delete_button->setDescription($click_to_delete_entry);
                    $delete_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $delete_button->setButtonSize("xs");
                    }
                    $delete_button->setIcon('fa fa-trash-o');
                    $delete_button->setButtonSpecialStyle('square_animated');
                    $delete_button->setButtonColor('danger');
                    $delete_button->setTarget('_self');
                    $delete_button->setImageOnly(TRUE);
                    //$delete_button->addButtonClass('dialog_button');
                    //$delete_button->setModal('dialog', "/organizer/delCollection?id=$dir");
                    $button_list .= $delete_button->toHtml();

                    // Row up buttons:
                    $AddyList[3][$num_Addys] = $button_list;

                    $num_Addys++;
                }
            }

            // Assemble ScrollList for Addressbooks:
            $scrollList = $factory->getScrollList("AddressBookList", array("name", "col_colour", "backup_date", "action"), $AddyList); 
            $scrollList->setAlignments(array("left", "center", "center", "right"));
            $scrollList->setDefaultSortedIndex('0');
            $scrollList->setSortOrder('ascending');
            $scrollList->setSortDisabled(array('1'));
            $scrollList->setPaginateDisabled(FALSE);
            $scrollList->setSearchDisabled(FALSE);
            $scrollList->setSelectorDisabled(FALSE);
            $scrollList->enableAutoWidth(FALSE);
            $scrollList->setInfoDisabled(FALSE);
            $scrollList->setColumnWidths(array("40%", "50", "200", "155")); // Max: 739px

            //
            //--- Calendars:
            //

            if (is_array($calendars)) {

                foreach ($calendars as $dir => $cal_entry) {
                    if ((isset($cal_entry['D:displayname'])) && (isset($cal_entry['C:calendar-description']))) {
                        $label = $factory->getLabel($cal_entry['D:displayname']);
                        $label->setLabel($cal_entry['D:displayname']);
                        $label->setDescription($cal_entry['C:calendar-description']);
                        $label->setStyleTarget("labelLabel");
                        $CalList[0][$num_Cals] = $label->toHtml();
                    }
                    elseif ((isset($cal_entry['D:displayname'])) && (!isset($cal_entry['CR:calendar-description']))) {
                        $label = $factory->getLabel($cal_entry['D:displayname']);
                        $label->setLabel($cal_entry['D:displayname']);
                        $label->setDescription($cal_entry['D:displayname']);
                        $label->setStyleTarget("labelLabel");
                        $CalList[0][$num_Cals] = $label->toHtml();
                    }
                    else {
                        $CalList[0][$num_Cals] = 'n/a';
                    }
                    if (isset($cal_entry['ICAL:calendar-color'])) {
                        $color = $cal_entry['ICAL:calendar-color'];
                    }
                    else {
                        $color = '#1C5EA0';
                    }
                    $CalList[1][$num_Cals] = '<div style="background-color: ' . $color . '; width: 45px; height: 15px;"></div>';;

                    // Date of last backup:
                    if (isset($data['backup'][$dir])) {
                        $CalList[2][$num_Cals] = $data['backup'][$dir];
                    }
                    else {
                        $CalList[2][$num_Cals] = 'n/a';
                    }

                    // Create URL:
                    $url = $url_prefix . $dir;

                    // Create Buttons:
                    $button_list = '';

                    $copy_to_clipboard_button = $factory->getModifyButton($url);
                    $copy_to_clipboard_button->setDescription($click_to_copy_to_clipboard);
                    $copy_to_clipboard_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $copy_to_clipboard_button->setButtonSize("xs");
                    }
                    $copy_to_clipboard_button->setButtonSpecialStyle('square_animated');
                    $copy_to_clipboard_button->setIcon('fa fa-copy');
                    $copy_to_clipboard_button->setImageOnly(TRUE);
                    $copy_to_clipboard_button->setTarget('copyToClipboard');
                    $button_list .= $copy_to_clipboard_button->toHtml();

                    $edit_button = $factory->getModifyButton("/organizer/personalOrganizerEdit?id=$dir");
                    $edit_button->setDescription($click_to_edit_entry);
                    $edit_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $edit_button->setButtonSize("xs");
                    }
                    $edit_button->setButtonSpecialStyle('square_animated');
                    $edit_button->setIcon('fa fa-edit');
                    $edit_button->setImageOnly(TRUE);
                    $edit_button->setTarget('_self');
                    $button_list .= $edit_button->toHtml();


                    if ($data['backup'][$dir] != 'n/a') {

                        $baLoad_button = $factory->getModifyButton("/organizer/babackup?file=$dir&action=load&db=$dir");
                        $baLoad_button->setDescription($i18n->getWrapped("baLoad"));
                        $baLoad_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $baLoad_button->setButtonSize("xs");
                        }
                        $baLoad_button->setButtonSpecialStyle('square_animated');
                        $baLoad_button->setIcon('fa fa-repeat');
                        $baLoad_button->setImageOnly(TRUE);
                        $baLoad_button->setTarget('_self');
                        $button_list .= $baLoad_button->toHtml();

                    }
                    else {

                        $baLoad_button = $factory->getModifyButton('javascript:void(0)');
                        $baLoad_button->setDescription($i18n->getWrapped("baLoad"));
                        $baLoad_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $baLoad_button->setButtonSize("xs");
                        }
                        $baLoad_button->setButtonSpecialStyle('square_animated');
                        $baLoad_button->setIcon('fa fa-repeat');
                        $baLoad_button->setImageOnly(TRUE);
                        $baLoad_button->setTarget('_self');
                        $baLoad_button->setButtonColor('default');
                        $baLoad_button->setButtonDisabled(TRUE);
                        $button_list .= $baLoad_button->toHtml();

                    }

                    $createBackup_button = $factory->getModifyButton("/organizer/babackup?file=$dir&action=back&db=$dir");
                    $createBackup_button->setDescription($i18n->getHtml("baBackup"));
                    $createBackup_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $createBackup_button->setButtonSize("xs");
                    }
                    $createBackup_button->setIcon('fa fa-cloud-download');
                    $createBackup_button->setButtonSpecialStyle('square_animated');
                    $createBackup_button->setImageOnly(TRUE);
                    $createBackup_button->setTarget('_self');
                    $button_list .= $createBackup_button->toHtml();

                    if ($data['backup'][$dir] != 'n/a') {
                        $download_button = $factory->getModifyButton("/organizer/badownload?file=$dir&action=down");
                        $download_button->setDescription($i18n->getHtml("baDownload"));
                        $download_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $download_button->setButtonSize("xs");
                        }
                        $download_button->setIcon('fa fa-download');
                        $download_button->setButtonSpecialStyle('square_animated');
                        $download_button->setImageOnly(TRUE);
                        $download_button->setTarget('_self');
                        $button_list .= $download_button->toHtml();

                    }
                    else {

                        $download_button = $factory->getModifyButton('javascript:void(0)');
                        $download_button->setDescription($i18n->getHtml("baDownload"));
                        $download_button->setButtonSize("small");
                        if ($BX_SESSION['gui_theme'] === 'adminica') {
                            $download_button->setButtonSize("xs");
                        }
                        $download_button->setIcon('fa fa-download');
                        $download_button->setButtonSpecialStyle('square_animated');
                        $download_button->setImageOnly(TRUE);
                        $download_button->setTarget('_self');
                        $download_button->setButtonColor('default');
                        $download_button->setButtonDisabled(TRUE);
                        $button_list .= $download_button->toHtml();
                    }

                    $delete_button = $factory->getRemoveButton("/organizer/delCollection?id=$dir");
                    $delete_button->setDescription($click_to_delete_entry);
                    $delete_button->setButtonSize("small");
                    if ($BX_SESSION['gui_theme'] === 'adminica') {
                        $delete_button->setButtonSize("xs");
                    }
                    $delete_button->setIcon('fa fa-trash-o');
                    $delete_button->setButtonSpecialStyle('square_animated');
                    $delete_button->setButtonColor('danger');
                    $delete_button->setTarget('_self');
                    $delete_button->setImageOnly(TRUE);
                    //$delete_button->addButtonClass('dialog_button');
                    //$delete_button->setModal('dialog', "/organizer/delCollection?id=$dir");
                    $button_list .= $delete_button->toHtml();

                    // Row up buttons:
                    $CalList[3][$num_Cals] = $button_list;

                    $num_Cals++;
                }
            }

            // Assemble ScrollList for Addressbooks:
            $scrollListCal = $factory->getScrollList("AddressBookList", array("name", "col_colour", "backup_date", "action"), $CalList); 
            $scrollListCal->setAlignments(array("left", "center", "center", "right"));
            $scrollListCal->setDefaultSortedIndex('0');
            $scrollListCal->setSortOrder('ascending');
            $scrollListCal->setSortDisabled(array('1'));
            $scrollListCal->setPaginateDisabled(FALSE);
            $scrollListCal->setSearchDisabled(FALSE);
            $scrollListCal->setSelectorDisabled(FALSE);
            $scrollListCal->enableAutoWidth(FALSE);
            $scrollListCal->setInfoDisabled(FALSE);
            $scrollListCal->setColumnWidths(array("40%", "50", "200", "155")); // Max: 739px

            //
            //--- Build page output:
            //

            // Add divider:
            $xxx = $factory->addBXDivider("addressbooks", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("addressbooks", false),
                    $defaultPage
                    );

            // Push out the AddressBookList Scrollist:
            $xxx = $factory->getRawHTML("AddressBookList", $scrollList->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("AddressBookList"),
                $defaultPage
            );

            // Add divider:
            $xxx = $factory->addBXDivider("calendars", "");
            $block->addFormField(
                    $xxx,
                    $factory->getLabel("calendars", false),
                    $defaultPage
                    );

            // Push out the CalendarList Scrollist:
            $xxx = $factory->getRawHTML("CalendarList", $scrollListCal->toHtml());
            $block->addFormField(
                $xxx,
                $factory->getLabel("CalendarList"),
                $defaultPage
            );

        }

        $page_body[] = $block->toHtml();

        // Out with the page:
        return $BxPage->render($page_module, $page_body);
    }       
}
/*
Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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