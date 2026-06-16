<!DOCTYPE html>
<html lang="<?php echo $localization; ?>" dir="ltr">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
        <title><?php echo $page_title;?></title>
        <meta name="description" content="BlueOnyx: Open Source Web Hosting Solution - Powerful and Easy to Use" />
        <meta name="keywords" content="admin, admin dashboard, admin interface, blueonyx, linux, responsive admin, sass, panel, software, ui, visualization, web app, application" />
        <meta name="author" content="BlueOnyx.it"/>
        
        <!-- Favicon -->
        <link rel="shortcut icon" href="/favicon.ico">
        <link rel="icon" href="/favicon.ico" type="image/x-icon">
        
        <!-- vector map CSS -->
        <link href="/.elm/vendors/bower_components/jasny-bootstrap/dist/css/jasny-bootstrap.min.css" rel="stylesheet" type="text/css"/>
        
        <!-- GUI CSS -->
        <link href="/.elm/dist/css/style_dark.css?sxxxx" rel="stylesheet" type="text/css">

        <!-- Start: Stylesheet for custom modifications by server owner -->
            <link rel="stylesheet" href="/.elm/dist/css/customer/customer.css" >
        <!-- Stop: Stylesheet for custom modifications by server owner -->

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

        <script type="text/javascript">
            var BASEURL = "<?=base_url();?>";
            var LANG = "<?=service('request')->getLocale();?>"
        </script>

    </head>
    <body>
        <!--Preloader-->
        <div class="preloader-it">
            <div class="la-anim-1"></div>
        </div>
        <!--/Preloader-->
        
        <div class="wrapper  pa-0">
            
            <!-- Main Content -->
            <div class="page-wrapper pa-0 ma-0 auth-page">
                <div class="container-fluid">
                    <!-- Row -->
                    <div class="table-struct full-width full-height">
                        <div class="table-cell vertical-align-middle auth-form-wrap">
                            <div class="auth-form  ml-auto mr-auto no-float">
                                <div class="row">
                                    <div class="col-sm-12 col-xs-12">
                                        <div class="mb-0">
                                            <div class="panel panel-black card-view">
                                                <div class="panel-heading">
                                                    <div class="logo-pos-wrap">
                                                        <div class="logo-wrap">
                                                            <a href="/login">
                                                                <div style="transform: scale(0.68);">
                                                                    <svg width="369.6666666666667" height="67.29790815162993" viewBox="-5 -5 380 77" class="css-1j8o68f"><linearGradient id="SvgjsLinearGradient5264"><stop id="SvgjsStop5265" stop-color="#2d388a" offset="0"></stop><stop id="SvgjsStop5266" stop-color="#00aeef" offset="1"></stop></linearGradient></defs><g id="SvgjsG5260" featurekey="v37d4h-0" transform="matrix(0.8614243268966675,0,0,0.8614243268966675,-1.4041216515737334,-12.006532055971025)" fill="url(#SvgjsLinearGradient5264)"><polygon xmlns="http://www.w3.org/2000/svg" points="42.021,89.823 98.369,53.589 82.566,61.219 82.574,61.292 82.527,61.238 82.264,61.365 82.281,61.389 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="79.395,61.174 38.895,57.391 36.811,91.25 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="3.71,38.712 34.918,92.062 37.066,57.146 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="95.209,45.172 80.775,27.29 79.232,32.516 95.928,46.175 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="98.135,50.979 79.758,35.645 82.281,58.632 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="61.512,33.498 79.758,54.504 77.424,33.356 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="59.756,34.836 39.467,54.979 80.432,58.808 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="58.445,33.191 36.674,27.365 38.974,52.971 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="37.133,54.393 34.822,28.537 6.776,37.639 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="8.923,35.031 34.527,26.292 25.659,19.867 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="1.63,26.184 3.25,37.485 20.957,21.022 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="28.355,14.604 7.837,21.987 26.101,17.109 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="36.751,24.86 60.131,31.196 77.148,30.907 78.672,25.749 67.951,19.853 48.173,14.486 32.256,13.938 28.073,18.586"></polygon></g><g id="SvgjsG5261" featurekey="UxBHKT-0" transform="matrix(2.2200021743774414,0,0,2.2200021743774414,99.58119568547545,6.07758915796075)" fill="#ffffff"><path d="M11.87 11.22 q-0.51 0.8 -1.33 1.14 q1 0.34 1.72 1.3 t0.72 2.1 q0 2.26 -1.44 3.25 t-3.68 0.99 l-6.32 0 l0 -14.62 l6.08 0 q4.76 0 4.76 4.06 q0 0.98 -0.51 1.78 z M7.68 11.18 q0.78 0 1.15 -0.48 t0.37 -1.14 q0 -0.58 -0.44 -1.01 t-1.06 -0.43 l-2.98 0 l0 3.06 l2.96 0 z M9.3 16.78 q0.52 -0.48 0.52 -1.18 t-0.52 -1.18 t-1.5 -0.48 l-3.08 0 l0 3.32 l3.14 0 q0.92 0 1.44 -0.48 z M26.44 20 l-10.84 0 l0 -14.62 l3.18 0 l0 11.88 l7.66 0 l0 2.74 z M31.48 19.5 q-1.48 -0.76 -2.29 -2.16 t-0.81 -3.26 l0 -8.68 l3.16 0 l0 8.68 q0 1.28 0.55 2.05 t1.32 1.09 t1.53 0.32 q0.74 0 1.51 -0.32 t1.31 -1.09 t0.54 -2.05 l0 -8.68 l3.16 0 l0 8.68 q0 1.86 -0.8 3.26 t-2.28 2.16 t-3.44 0.76 q-1.98 0 -3.46 -0.76 z M47.92 11.38 l7 0 l0 2.76 l-7 0 l0 3.12 l7.92 0 l0 2.74 l-11.08 0 l0 -14.62 l11.08 0 l0 2.74 l-7.92 0 l0 3.26 z M61.96 19.26 q-1.8 -1.02 -2.86 -2.77 t-1.06 -3.81 q0 -2.04 1.06 -3.79 t2.86 -2.77 t3.94 -1.02 t3.96 1.02 t2.88 2.77 t1.06 3.79 q0 2.06 -1.06 3.81 t-2.88 2.77 t-3.96 1.02 t-3.94 -1.02 z M63.54 8.63 q-1.08 0.65 -1.72 1.73 t-0.64 2.32 t0.64 2.33 t1.72 1.75 t2.36 0.66 t2.37 -0.66 t1.72 -1.75 t0.63 -2.33 t-0.63 -2.32 t-1.72 -1.73 t-2.37 -0.65 t-2.36 0.65 z M79.52 20 l-3.14 0 l0 -15.08 l10.08 8.92 l0 -8.44 l3.16 0 l0 15.06 l-10.1 -8.78 l0 8.32 z M100.25999999999999 14.46 l0 5.54 l-3.16 0 l0 -5.54 l-5.76 -9.06 l3.7 0 l3.64 5.9 l3.68 -5.9 l3.7 0 z M106.18 5.4 l3.98 0 l3.78 5.04 l3.78 -5.04 l3.94 0 l-5.74 7.36 l5.64 7.24 l-3.96 0 l-3.66 -4.86 l-3.66 4.86 l-3.98 0 l5.66 -7.24 z"></path></g></svg>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="panel-heading">
                                            <div class="" align="center">
                                                <button class="btn btn-primary btn-outline"><?php echo $WelcomeMsg; ?></button>
                                            </div>
                                            </div>
<?php 
if ($loginMessage == $loginFailed) {
    echo '                                            <div class="panel-body">' . "\n";
    echo '                                                <div class="alert alert-danger alert-dismissable">' . "\n";
    echo '                                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>' . $loginFailed . '' . "\n";
    echo '                                                </div>' . "\n";
    echo '                                            </div>' . "\n";
}
elseif ($loginMessage == $twoFAfailed) {
    echo '                                            <div class="panel-body">' . "\n";
    echo '                                                <div class="alert alert-danger alert-dismissable">' . "\n";
    echo '                                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>' . $twoFAfailed . '' . "\n";
    echo '                                                </div>' . "\n";
    echo '                                            </div>' . "\n";
}
else {
    if ($stage === '2FA') {
        // Show 2FA help text:
        $loginMessage = $twoFAtext_help;
    }
    // No errors present: Show the dismissible login info text:
    echo '                                            <div class="panel-body">' . "\n";
    echo '                                                <div class="alert alert-info alert-dismissable">' . "\n";
    echo '                                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>' . $loginMessage . '' . "\n";
    echo '                                                </div>' . "\n";
    echo '                                            </div>' . "\n";
}
?>
                                        </div>  
                                        <div class="form-wrap">
                                            <form action="/login<?php echo $URLaddParams ?>" method="post" accept-charset="utf-8" id="login_form">
<?php 

if ($stage === 'AUTH') {
    echo '                                                <div class="form-group">' . "\n";
    echo '                                                    <label class="control-label mb-10" for="username_field">' . $Username . '</label>' . "\n";
    echo '                                                    <div class="input-group">' . "\n";
    echo '                                                        <div class="input-group-addon" data-toggle="tooltip" data-placement="top" title="" data-original-title="' . $loginPageUnameNotPW . '"><i class="icon-user"></i></div>' . "\n";
    echo '                                                        <input type="text" class="form-control" id="username_field" name="username_field" placeholder="' . $Username . '" onKeyPress="if(document.layers && event.which == 13 && document.form.onsubmit()) document.form.submit()" value="">' . "\n";
    echo '                                                    </div>' . "\n";
    echo '                                                </div>' . "\n";
    echo '                                                <!-- Password -->' . "\n";
    echo '                                                <div class="form-group">' . "\n";
    echo '                                                            <label class="control-label mt-10 mb-10" for="password_field">' . $Password . '</label>' . "\n";
    echo '                                                            <div class="input-group">' . "\n";
    echo '                                                                <div class="input-group-addon"><i class="icon-lock" onclick="password_fieldFunction()" data-toggle="tooltip" data-placement="bottom" title="" data-original-title="' . $loginPageRevelPWDtxt . '"></i></div>' . "\n";
    echo '                                                                <input type="password" class="form-control" id="password_field" name="password_field" placeholder="' . $Password . '" onKeyPress="if(getKey(event)==13 && document.form.onsubmit()) document.form.submit()" value="">' . "\n";
    echo '                                                            </div>' . "\n";
    echo '                                                </div>' . "\n";
}

if ($stage === '2FA') {
    echo '                                                <!-- 2FA -->' . "\n";
    echo '                                                <div class="form-group">' . "\n";
    echo '                                                            <label class="control-label mt-10 mb-10" for="token_field">' . $twoFAtext . '</label>' . "\n";
    echo '                                                    <div class="input-group">' . "\n";
    echo '                                                        <div class="input-group-addon" data-toggle="tooltip" data-placement="top" title="" data-original-title="' . $twoFAtext_help . '"><i class="glyphicon glyphicon-qrcode"></i></div>' . "\n";
    echo '                                                            <input type="text" class="form-control" id="token_field" name="token_field" placeholder="' . $twoFAtext . '" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" onKeyPress="if(document.layers && event.which == 13 && document.form.onsubmit()) document.form.submit()" value="">' . "\n";
    echo '                                                        </div>' . "\n";
    echo '                                                        <input type="hidden" id="username_field" name="username_field" value="' . $username_field . '">' . "\n";
    echo '                                                        <input type="hidden" id="sessionId_field" name="sessionId_field" value="' . $sessionId_field . '">' . "\n";
    echo '                                                </div>' . "\n";
}

?>


                                                <?php echo $ssl_toggle; ?>
                                                <input type="hidden" id="redirect_target" name="redirect_target" value="<?php echo $redirect_target;?>">
                                                <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" />
                                                <div class="form-group text-center pt-20">
                                                    <button type="submit" class="btn btn-primary btn-anim"><i class="fa fa-sign-in"></i><span class="btn-text">Login</span></button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>  
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- /Row -->   
                </div>

            <!-- Footer -->
            <footer class="footer container-fluid pl-30 pr-30">
                <div class="row">
                    <div class="col-sm-12">
                        <p align="center">&copy; 2008-<?php echo date("Y"); ?> <a href="https://www.blueonyx.it" target="_blank">BlueOnyx</a></p>
                    </div>
                </div>
            </footer>
            <!-- /Footer -->
                
            </div>
            <!-- /Main Content -->
        
        </div>
        <!-- /#wrapper -->
        
        <!-- JavaScript -->
        
        <!-- jQuery -->
        <script src="/.elm/vendors/bower_components/jquery/dist/jquery.min.js"></script>
    <script>if (window.jQuery) { jQuery.migrateMute = true; jQuery.migrateTrace = false; }</script>
    <script src="/.elm/vendors/jquery-migrate.js"></script>
    <script>
        if (window.jQuery) {
            jQuery.uniqueSort = jQuery.uniqueSort || jQuery.unique;
            if (jQuery.event && !jQuery.event.addProp) {
                jQuery.event.addProp = function(name, hook) {
                    Object.defineProperty(jQuery.Event.prototype, name, {
                        enumerable: true,
                        configurable: true,
                        get: hook ? function() {
                            return this.originalEvent ? hook(this.originalEvent) : undefined;
                        } : function() {
                            return this.originalEvent ? this.originalEvent[name] : undefined;
                        },
                        set: function(value) {
                            Object.defineProperty(this, name, {
                                enumerable: true,
                                configurable: true,
                                writable: true,
                                value: value
                            });
                        }
                    });
                };
            }
            if (jQuery.migrateDisablePatches) {
                jQuery.migrateDisablePatches("event-old-patch", "unique");
            }
            jQuery.migrateTrace = false;
        }
    </script>
        
        <!-- Bootstrap Core JavaScript -->
        <script src="/.elm/vendors/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
        <script src="/.elm/vendors/bower_components/jasny-bootstrap/dist/js/jasny-bootstrap.min.js"></script>

        <!-- Slimscroll JavaScript -->
        <script src="/.elm/dist/js/jquery.slimscroll.js"></script>
        
        <!-- Init JavaScript -->
        <script src="/.elm/dist/js/init.js"></script>

        <!-- Password reveal -->
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

    </body>
</html>
