<?php 
namespace User\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class PersonalAccount extends BaseController {
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

        $CI =& get_instance();

        // Most basic ACL:
        if (!$CI->getAllowed('validUser')) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //--- Get CODB-Objects of interest: 
        //

        $BX_SESSION = $CI->getBX_SESSION();
        $System = $CI->getSystem();

        if (empty($BX_SESSION['loginUser'])) {
            // Nice people say goodbye, or CCEd waits forever:
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        //
        //-- Prepare Page:
        //

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-user", "/user/personalAccount");
        $BxPage = $factory->getPage();
        $i18n = new I18n("base-user", $CI->getBX_Locale());
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));

        //
        //-- Re-Use $errors array from Session data:
        //

        $errors = $BxPage->getErrors();

        //
        //-- Prepare data:
        //

        $user = $BX_SESSION['loginUser'];

        // Make the users fullName safe for all charsets:
        $user['fullName'] = Utf8Encode($user['fullName']);

        //
        //-- Start: Chorizo's Style handling:
        //

        // Read the Chorizo's Style from User's CODB object:
        $usersChorizoStyleObject = json_decode(urldecode($user['ChorizoStyle']));

        // Turn Style Object into an Array:
        $usersChorizoStyle = (array) $usersChorizoStyleObject;

        // Default Style:
        $ChorizoDefaultStyle =  array(
                'theme_switcher_php-style'   => 'theme_blue.css',
                'layout_switcher_php-style'  => 'layout_fixed.css',
                'nav_switcher_php-style'     => 'switcher.css',
                'skin_switcher_php-style'    => 'skin_light.css',
                'bg_switcher_php-style'      => 'switcher.css'
            );

        // Get currently used Style from Browser Cookie:
        $ChorizoCurrentStyle = $ChorizoDefaultStyle;
        if (isset($_COOKIE['theme_switcher_php-style'])) {
            $ChorizoCurrentStyle['theme_switcher_php-style'] = $_COOKIE['theme_switcher_php-style'];
        }
        if (isset($_COOKIE['layout_switcher_php-style'])) {
            $ChorizoCurrentStyle['layout_switcher_php-style'] = $_COOKIE['layout_switcher_php-style'];
        }
        if (isset($_COOKIE['nav_switcher_php-style'])) {
            $ChorizoCurrentStyle['nav_switcher_php-style'] = $_COOKIE['nav_switcher_php-style'];
        }
        if (isset($_COOKIE['skin_switcher_php-style'])) {
            $ChorizoCurrentStyle['skin_switcher_php-style'] = $_COOKIE['skin_switcher_php-style'];
        }
        if (isset($_COOKIE['skin_switcher_php-style'])) {
            $ChorizoCurrentStyle['bg_switcher_php-style'] = $_COOKIE['bg_switcher_php-style'];
        }

        // Clone default Style:
        $ChorizoNewStyle = $ChorizoDefaultStyle;

        // Walk through the differences between default and current style and update the new Style-Array:
        foreach ($ChorizoDefaultStyle as $key => $value) {
            if (($ChorizoCurrentStyle[$key] != $key) && ($ChorizoCurrentStyle[$key] != "")) {
                $ChorizoNewStyle[$key] = $ChorizoCurrentStyle[$key];
            }
            else {
                $ChorizoNewStyle[$key] = $value;
            }
        }

        // Save ChorizoStyle in session data:
        $data['ChorizoStyle'] = $ChorizoNewStyle;
        session()->set($data);

        // Push out cookies for the new Style:
        foreach ($ChorizoNewStyle as $key => $value) {
            setcookie($key, $value, time() + 31572500, "/");
        }

        // If this is NOT a Demo, then store the updated Style in CODB, too:
        if (!is_file('/etc/DEMO')) {
            $CI->cceClient->set($user['OID'], "", array('ChorizoStyle' => urlencode(json_encode($ChorizoNewStyle))));
        }

        //
        //--- Elmer Theme prehandling:
        //

        $ElmerStyle_Default_Array = array('header_color' => 'theme-6-active', 'primaryColor' => 'pimary-color-blue', 'css' => 'style.css');

        $major_theme_choice = array('elmer' => 'Elmer');

        $major_theme_choice_flipped = array_flip($major_theme_choice);

        $elmer_style_choice = array('style.css' => $i18n->get('[[palette.light]]'), 'style_dark.css' => $i18n->get('[[palette.dark]]'));
        $elmer_style_choice_flipped = array_flip($elmer_style_choice);

        $elmer_theme_choice = array(
                                    'theme-6-active' => $i18n->get('[[palette.default]]'),
                                    'theme-1-active' => $i18n->get('[[palette.Oceania/FJ]]'),
                                    'theme-2-active' => $i18n->get('[[palette.Europe/Monaco]]'),
                                    'theme-3-active' => $i18n->get('[[palette.Europe/Paris]]'),
                                    'theme-4-active' => $i18n->get('[[palette.Europe/Oslo]]'),
                                    'theme-5-active' => $i18n->get('[[palette.Europe/Rome]]'),
                                );
        $elmer_theme_choice_flipped = array_flip($elmer_theme_choice);

        $elmer_color_choice = array(
                                    'pimary-color-blue' => $i18n->get('[[palette.blue]]'),
                                    'pimary-color-red' => $i18n->get('[[palette.red]]'),
                                    'pimary-color-green' => $i18n->get('[[palette.green]]'),
                                    'pimary-color-yellow' => $i18n->get('[[palette.yellow]]'),
                                    'pimary-color-pink' => $i18n->get('[[palette.pink]]'),
                                    'pimary-color-orange' => $i18n->get('[[palette.orange]]'),
                                    'pimary-color-gold' => $i18n->get('[[palette.gold]]'),
                                    'pimary-color-silver' => $i18n->get('[[palette.silver]]')
                                );
        $elmer_color_choice_flipped = array_flip($elmer_color_choice);

        //
        //-- End: Chorizo's Style handling
        //

        // -- Actual page logic start:

        // find all possible locales
        $possibleLocales = array();
        $possibleLocales = stringToArray($System["locales"]);
        /*
         * don't show browser option for admin, because then it becomes unclear
         * what the system locale is.
         */
        if ($BX_SESSION['loginName'] != "admin") {
            $possibleLocales = array_merge(array("browser"), $possibleLocales);
        }

        //
        //--- Handle form validation:
        //

        // Shove submitted input into $form_data after passing it through the XSS filter:
        $form_data = $BxPage->getGETPOST('POST');

        // Form fields that are required to have input:
        $required_keys = array('fullNameField', 'languageField');

        // Set up rules for form validation. These validations happen before we submit to CCE and further checks based on the schemas are done:

        // Empty array for key => values we want to submit to CCE:
        $attributes = array();

        // Items we do NOT want to submit to CCE:
        $ignore_attributes = array("BlueOnyx_Info_Text", "_password_repeat");

        if ((is_array($form_data)) && ($this->request->getPost(NULL, NULL, TRUE))) {
            // Function GetFormAttributes() walks through the $form_data and returns us the $parameters we want to
            // submit to CCE. It intelligently handles checkboxes, which only have "on" set when they are ticked.
            // In that case it pulls the unticked status from the hidden checkboxes and addes them to $parameters.
            // It also transformes the value of the ticked checkboxes from "on" to "1". 
            //
            // Additionally it generates the form_validation rules for CodeIgniter.
            //
            // params: $i18n                i18n Object of the error messages
            // params: $form_data           array with form_data array from CI
            // params: $required_keys       array with keys that must have data in it. Needed for CodeIgniter's error checks
            // params: $ignore_attributes   array with items we want to ignore. Such as Labels.
            // params: $BxPage              our already declared $BxPage Object (for storing validation Errors)
            // return:                      array with keys and values ready to submit to CCE.
            $attributes = GetFormAttributes($i18n, $form_data, $required_keys, $ignore_attributes, $BxPage);

            // Get potential errors that GetFormAttributes() ran into from $BxPage:
            $errors = array_merge($errors, $BxPage->getErrors());
        }

        //
        //--- Own error checks:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {
            if ((isset($form_data['newPasswordField'])) && (isset($form_data['_newPasswordField_repeat']))) {
                if (($form_data['newPasswordField'] != '') && ($form_data['_newPasswordField_repeat'] != '')) {
                    // Check Password match:
                    $passwd = "";
                    if (isset($form_data['newPasswordField'])) {
                        $passwd = $form_data['newPasswordField'];
                    }
                    $passwd_repeat = "";
                    if (isset($form_data['_newPasswordField_repeat'])) {
                        $passwd_repeat = $form_data['_newPasswordField_repeat'];
                    }
                    if (bx_pw_check($i18n, $passwd, $passwd_repeat) != "") {
                        $errors[] = bx_pw_check($i18n, $passwd, $passwd_repeat);
                    }
                }
            }
        }

        //
        //--- Theme juggling:
        //

        if ($this->request->getPost(NULL, NULL, TRUE)) {

            // Sense check:
            if (count($BX_SESSION['elmer_theme']) === 0) {
                $BX_SESSION['elmer_theme'] = $ElmerStyle_Default_Array;
            }

            $gui_theme = 'elmer';
            $appearance = $BX_SESSION['elmer_theme']['header_color'];
            $style = $BX_SESSION['elmer_theme']['css'];
            $color = $BX_SESSION['elmer_theme']['primaryColor'];

            if (isset($attributes['gui_theme'])) {
                $gui_theme = $major_theme_choice_flipped[$attributes['gui_theme']];
                unset($attributes['gui_theme']);
            }
            if (isset($attributes['appearance'])) {
                $appearance = $elmer_theme_choice_flipped[$attributes['appearance']];
                unset($attributes['appearance']);
            }
            if (isset($attributes['style'])) {
                $style = $elmer_style_choice_flipped[$attributes['style']];
                unset($attributes['style']);
            }
            if (isset($attributes['color'])) {
                $color = $elmer_color_choice_flipped[$attributes['color']];
                unset($attributes['color']);
            }

            if ((isset($appearance)) && (isset($style)) && (isset($appearance)) && (isset($appearance))) {
                $ElmerStyle_Array = array('header_color' => $appearance, 'primaryColor' => $color, 'css' => $style);
            }
            else {
                // Default:
                $ElmerStyle_Array = $ElmerStyle_Default_Array;
            }

            // Save ElmerStyle in session data:
            $data['ElmerStyle'] = $ElmerStyle_Array;
            $data['gui_theme'] = $gui_theme;
            session()->set($data);

            // Push out cookies for the new Style:
            foreach ($ElmerStyle_Array as $key => $value) {
                setcookie($key, $value, time() + 31572500, "/");
            }
            setcookie("gui_theme", $gui_theme, "0", "/");
            $CI->setBX_SESSION_GuiTheme($gui_theme);
            $BX_SESSION = $CI->getBX_SESSION();

            // If this is NOT a Demo, then store the updated Style in CODB, too:
            if (!is_file('/etc/DEMO')) {
                $CI->cceClient->set($user['OID'], "", array('ElmerStyle' => urlencode(json_encode($ElmerStyle_Array)), 'gui_theme' => $gui_theme, 'gui_theme_timer' => time()));
            }
        }

        //
        //--- At this point all checks are done. If we have no errors, we can submit the data to CODB:
        //

        // If we have no errors and have POST data, we submit to CODB:
        if ((count($errors) === 0) && ($this->request->getPost(NULL, NULL, TRUE))) {
            // No errors, submit to CODB:

            // Assemble the data we want to submit:
            if ($user['localePreference'] == $form_data['languageField']) {
                $attributes = array("fullName" => $form_data["fullNameField"], "localePreference" => $form_data['languageField']);
            }
            else {
                $attributes = array("localePreference" => $form_data['languageField']);
            }
            if (($form_data['newPasswordField']) && (!is_file("/etc/DEMO"))) {
                $attributes["password"] = $form_data['newPasswordField'];
            }

            // Actual submit to CODB:
            if (isset($form_data['SID'])) {
                if ($form_data['SID'] == $BX_SESSION['sessionId']) {
                    $CI->cceClient->setObject("User", $attributes, "", array("name" => $BX_SESSION['loginName']));
                }
            }

            // CCE errors that might have happened during submit to CODB:
            $CCEerrors = $CI->cceClient->errors();
            foreach ($CCEerrors as $object => $objData) {
                // When we fetch the CCE errors it tells us which field it bitched on. And gives us an error message, which we can return:
                $errors[] = ErrorMessage($i18n->get($objData->message, true, array('key' => $objData->key)) . '<br>&nbsp;');
            }

            // Somewhat special: If we have no CCE errors after setting this stuff and the new locale
            // preference is different from the old one, then we redirect to /user/personalAccount to
            // reload the entire page. Otherwise we end up with the body being in the wrong locale:
            if (count($errors) === 0) {

                // Set new locale to cookie, too:
                setcookie('locale', $form_data['languageField'], time() + 31572500, "/");

                // Reinitialize Languages:
                $new_locale = initialize_languages(FALSE, $form_data['languageField']);
                $CI->setBX_Locale($new_locale['locale'], $new_locale['localization'], $new_locale['localecharset']);

                // Fetch User Object and User Shell again and force $BX_SESSION to be updated with the new data:
                $user_obj_new = $CI->cceClient->get($BX_SESSION['loginUser']['OID']);
                $userShell_new = $this->cceClient->get($BX_SESSION['loginUser']['OID'], 'Shell');
                $CI->setBX_SESSION($BX_SESSION['loginName'], $BX_SESSION['sessionId'], $user_obj_new, $userShell_new['enabled']);
                $CI->setUserLogged($BX_SESSION['sessionId']);
                $BX_SESSION = $CI->getBX_SESSION();

                // Return to this page and display errors - if there are any.
                // Syntax: $BxPage->ReturnToThisPage($errors = [array, required], $returnURL = [string, optional]);
                $BxPage->ReturnToThisPage($errors);
            }

        }

        //-- Generate page - Either with data out of CODB (no POST action) or with form submitted data (on POST action):

        // Prepare Page:
        $BxPage->setErrors($errors);

        // Set Menu items:
        $BxPage->setVerticalMenu('base_controlpanel');
        $page_module = 'base_personalProfile';

        // Make the users fullName safe for all charsets:
        $user['fullName'] = bx_charsetsafe($user['fullName']);

        $defaultPage = "basicSettingsTab";

        $settings = $factory->getPagedBlock("accountSettings", array($defaultPage));
        $settings->setCurrentLabel($factory->getLabel('accountSettings', false, array('userName' => $BX_SESSION['loginName'])));        
        $settings->setToggle("#");
        $settings->setSideTabs(FALSE);

        // Full Name:
        $enter_fullName = $factory->getFullName("fullNameField", $user['fullName']);
        $enter_fullName->setOptional(FALSE);
        $settings->addFormField(
                $enter_fullName,
                $factory->getLabel("fullNameField"),
                $defaultPage
                );

        // Locale selector:
        $locale = $factory->getLocale("languageField", $user['localePreference']);
        $locale->setPossibleLocales($possibleLocales);
        $settings->addFormField(
          $locale,
          $factory->getLabel("languageField"), $defaultPage
        );

        // Password:
        $mypw = $factory->getPassword("newPasswordField", "", "rw");
        $mypw->setConfirm(TRUE);
        $mypw->setOptional(TRUE);
        $mypw->setCheckPass(TRUE);
        $settings->addFormField(
          $mypw,
          $factory->getLabel("newPasswordField"), $defaultPage
        );

        // SID:
        $SID = $factory->getTextField("SID", $BX_SESSION['sessionId'], "");
        $settings->addFormField(
          $SID,
          $factory->getLabel("SID"), $defaultPage
        );

        //
        //--- Major theme switcher:
        //

        // Add divider:
        $xxx = $factory->addBXDivider("gui_theme", "");
        $settings->addFormField(
                $xxx,
                $factory->getLabel($i18n->get("[[palette.gui_theme]]"), false),
                $defaultPage
                );

        $theme_select = $factory->getMultiChoice("gui_theme", array_values($major_theme_choice));
        $theme_select->setSelected($major_theme_choice['elmer'], true);
        $settings->addFormField(
            $theme_select, 
            $factory->getLabel("gui_theme"),
            $defaultPage
        );

        // Label and Description are from palette, set the separately:
        $BxPage->setLabel('gui_theme', $i18n->get("[[palette.gui_theme]]"), $i18n->get("[[palette.gui_theme_help]]"));

        //
        //-- Theme fine tuning:
        //

        //
        //--- Show Elmer Style Switcher:
        //

        if (count($BX_SESSION['elmer_theme']) === 0) {
            $BX_SESSION['elmer_theme'] = $ElmerStyle_Default_Array;
        }

        // Variant:
        $elmer_theme_select = $factory->getMultiChoice("appearance", array_values($elmer_theme_choice));
        $elmer_theme_select->setSelected($elmer_theme_choice[$BX_SESSION['elmer_theme']['header_color']], true);
        $settings->addFormField(
            $elmer_theme_select, 
            $factory->getLabel($i18n->get("[[palette.appearance]]")),
            $defaultPage
        );
        // Label and Description are from palette, set the separately:
        $BxPage->setLabel('appearance', $i18n->get("[[palette.appearance]]"), $i18n->get("[[palette.appearance_help]]"));

        // Style:
        $elmer_style_select = $factory->getMultiChoice("style", array_values($elmer_style_choice));
        $elmer_style_select->setSelected($elmer_style_choice[$BX_SESSION['elmer_theme']['css']], true);
        $settings->addFormField(
            $elmer_style_select, 
            $factory->getLabel($i18n->get("[[palette.style]]")),
            $defaultPage
        );
        // Label and Description are from palette, set the separately:
        $BxPage->setLabel('style', $i18n->get("[[palette.style]]"), $i18n->get("[[palette.style_help]]"));

        // Color:
        $elmer_color_select = $factory->getMultiChoice("color", array_values($elmer_color_choice));
        $elmer_color_select->setSelected($elmer_color_choice[$BX_SESSION['elmer_theme']['primaryColor']], true);
        $settings->addFormField(
            $elmer_color_select, 
            $factory->getLabel($i18n->get("[[palette.color]]")),
            $defaultPage
        );
        // Label and Description are from palette, set the separately:
        $BxPage->setLabel('color', $i18n->get("[[palette.color]]"), $i18n->get("[[palette.color_help]]"));

        // Add the buttons
        $settings->addButton($factory->getSaveButton($BxPage->getSubmitAction(), "DEMO-OVERRIDE"));
        $settings->addButton($factory->getCancelButton("/user/personalAccount"));

        $page_body[] = $settings->toHtml();

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
