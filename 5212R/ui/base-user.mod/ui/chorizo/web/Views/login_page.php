<!DOCTYPE html>
<html lang="<?php echo $localization; ?>" dir="ltr">
    <head>
        <meta charset="utf-8">
        <title><?php echo $page_title;?></title>

<!-- iPhone, iPad and Android specific settings -->

    <meta name="viewport" content="width=device-width; initial-scale=1; maximum-scale=1;">
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <link href="/.adm/images/interface/iOS_icon.png" rel="apple-touch-icon">
    <link rel="stylesheet" type="text/css" href="/.adm/styles/adminica/combined-common-mini.css">

<!-- Style Switcher -->

    <link rel="stylesheet" href="/.adm/styles/themes/layout_switcher.php?default=layout_fixed.css" >
    <link rel="stylesheet" href="/.adm/styles/themes/nav_switcher.php?default=switcher.css" >
    <link rel="stylesheet" href="/.adm/styles/themes/skin_switcher.php?default=switcher.css" >
    <link rel="stylesheet" href="/.adm/styles/themes/theme_switcher.php?default=theme_blue.css" >
    <link rel="stylesheet" href="/.adm/styles/themes/bg_switcher.php?default=bg_silver.css" >

    <link rel="stylesheet" href="/.adm/styles/adminica/colours.css"> <!-- this file overrides the theme's default colour scheme, allowing more colour combinations (see layout example page) -->
    <script src="/.adm/scripts/plugins-min.js"></script>
    <script src="/.adm/scripts/adminica/adminica_all-min.js"></script>

<!-- Start: Stylesheet for custom modifications by server owner -->
    <link rel="stylesheet" href="/.adm/styles/customer/customer.css" >
<!-- Stop: Stylesheet for custom modifications by server owner -->

    <link rel="stylesheet" href="/.adm/fa/css/all.min.css">

    <SCRIPT language="JavaScript" type="text/javascript"><!--
        //<![CDATA[
        if (navigator.cookieEnabled)
            var tzc = (Math.round((new Date()).getTime() / 1000));
            var tzs = <?php echo time()?>;
            var tzoff = tzc - tzs;
            document.cookie = "tzoff="+tzoff+"; expires=0; path=/";
        //]]>
        // -->
   </SCRIPT>

    <SCRIPT language="JavaScript" type="text/javascript"><!--
    //<![CDATA[
    function focuslogin() {
    document.form.username_field.focus();
    }
    function getKey(e) {    // WaveWeb 2012
        var key;    // return code of key pressed; e.g.:
        // onKeyPress="if(getKey(event)==13) ...
        if(window.event) key = window.event.keyCode;    // IE
        else key = e.which;    // Firefox and others
        //alert("e='"+e+"' key='"+key+"'");
    }
    //]]>
    // -->
    </SCRIPT> 

    <!-- Extra headers: Start -->
    <!-- Extra headers: End -->

        <script type="text/javascript">
            var BASEURL = "<?=base_url();?>";
            var LANG = "<?=service('request')->getLocale();?>"
        </script>
            
    </head>
    <body>

<!-- End: framework_head.php -->
        <div id="pjax">
                <div id="wrapper">
                        <div class="isolate">
                                <div class="center narrow">
                                        <div class="main_container full_size container_16 clearfix">
                                                <div class="box">

                            <img src="/.adm/images/bx/images/BlueOnyxLoginImage-<?php echo $primaryColor; ?>.gif">

<h2 class="box_head"><center><strong><?php echo $WelcomeMsg; ?></strong></center></h2>

                                                        <div class="block">
                                                                <div class="section">
<?php 
if ($loginMessage == $loginFailed) {
    echo '<div class="alert dismissible alert_red">';
    echo '<img width="24" height="24" src="/.adm/images/icons/small/white/alarm_bell.png">';
    echo "<strong>$loginFailed </strong>"; 
    echo '</div>';
}
elseif ($loginMessage == $twoFAfailed) {
    echo '<div class="alert dismissible alert_red">';
    echo '<img width="24" height="24" src="/.adm/images/icons/small/white/alarm_bell.png">';
    echo "<strong>$twoFAfailed </strong>"; 
    echo '</div>';
}
else {
    if ($stage === '2FA') {
        // Show 2FA help text:
        $loginMessage = $twoFAtext_help;
    }
    // No errors present: Show the dismissible login info text:
    echo '<div class="alert dismissible alert_light">';
    echo '<img width="24" height="24" src="/.adm/images/icons/small/grey/locked.png">';
    echo "<strong>$loginMessage</strong> ";
    echo '</div>';
}
?>
                        
                                            <noscript>
                                                <div class="alert dismissible alert_light">
                                                <img width="24" height="24" src="/.adm/images/icons/small/grey/locked.png">
                                                <strong><?php echo $noJS ?></strong> 
                                            </noscript>
                                        </div>
                                        <form action="/login<?php echo $URLaddParams ?>" method="post" accept-charset="utf-8" id="login_form">

<?php 

if ($stage === 'AUTH') {

    print <<<HTML

                                        <fieldset class="label_side top">
                                                <label for="username_field">$Username</label>
                                                <div>
                                                        <input type="text" id="username_field" name="username_field" placeholder="$Username" class="required" onKeyPress="if(document.layers && event.which == 13 && document.form.onsubmit()) document.form.submit()" value="">
                                                </div>
                                        </fieldset>
                                        <fieldset class="label_side bottom">
                                                <label for="password_field">$Password</label>
                                                <div>
                                                    <input type="password" id="password_field" name="password_field" placeholder="$Password" class="required" onKeyPress="if(getKey(event)==13 && document.form.onsubmit()) document.form.submit()" value="">
                                                    <span toggle="#password_field" class="fa fa-fw fa-eye field-icon toggle-password" style="margin-left: -245px; cursor: pointer;" onclick="password_fieldFunction()"></span>
                                                </div>
                        
                                                <script>
                                                    function password_fieldFunction() {
                                                        var x = document.getElementById("password_field");
                                                        if (x.type === "password") {
                                                            x.type = "text";
                                                        }
                                                        else {
                                                            x.type = "password";
                                                        }
                                                    }
                                                </script>
                        
                                        </fieldset>
    HTML;
}

if ($stage === '2FA') {

    print <<<HTML
                                        <fieldset class="label_side top">
                                                <label for="token_field" class="tooltip right uniform" title="$twoFAtext_help">$twoFAtext</label>
                                                <div>
                                                    <input type="text" class="form-control" id="token_field" name="token_field" placeholder="$twoFAtext" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" onKeyPress="if(document.layers && event.which == 13 && document.form.onsubmit()) document.form.submit()" value="">
                                                    <input type="hidden" id="username_field" name="username_field" value="$username_field">
                                                    <input type="hidden" id="sessionId_field" name="sessionId_field" value="$sessionId_field">
                                                </div>
                                        </fieldset>
    HTML;

}

?>


<?php echo $ssl_toggle; ?>

                                        <div class="button_bar clearfix">
                                                <button class="wide" type="submit">
                                                        <img src="/.adm/images/icons/small/white/key_2.png">
                                                        <span><?php echo $login_text ?></span>
                                                </button>
                                        </div>
                                                    <input type="hidden" id="redirect_target" name="redirect_target" value="<?php echo $redirect_target;?>">
                            <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" />
                                        </form>
                                </div>
                        </div>
                </div>
                <a href="/login" id="login_logo"><span>BlueOnyx</span></a>
        </div>
</div>
<!-- Start: Static footer -->
                <div id="loading_overlay">
                        <div class="loading_message round_bottom">
                                <img src="/.adm/images/interface/loading.gif" alt="loading" />
                        </div>
                </div>        

   </body>
</html>
