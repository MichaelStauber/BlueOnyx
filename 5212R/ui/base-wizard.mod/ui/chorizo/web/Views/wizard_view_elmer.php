<!DOCTYPE html>
<html lang=<?php echo '"' . $localization . '"';?> dir="ltr" class="no-js">
<head>
    <meta http-equiv="content-type" content="text/html; charset=<?php echo $charset;?>">
    <meta charset="<?php echo $charset; ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title><?php echo $page_title;?></title>
    <meta name="description" content="BlueOnyx: Open Source Web Hosting Solution - Powerful and Easy to Use" />
    <meta name="keywords" content="admin, admin dashboard, admin interface, blueonyx, linux, responsive admin, sass, panel, software, ui, visualization, web app, application" />
    <meta name="author" content="BlueOnyx.it"/>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

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
    <script src="/.elm/vendors/bower_components/jquery/dist/jquery-browser.js"></script>
    <script src="/.elm/vendors/jquery-ui.min.js"></script>
    <script src="/.elm/extra/js-cookie-main/js.cookie.min.js"></script>

    <!-- Data table CSS -->
    <link href="/.elm/vendors/bower_components/datatables/media/css/jquery.dataTables.css" rel="stylesheet" type="text/css"/>
    <link href="/.elm/vendors/bower_components/jquery-toast-plugin/dist/jquery.toast.min.css" rel="stylesheet" type="text/css">

    <!-- Uniform for Datatables -->
    <!-- <script src="/.elm/adminica/uniform/uniform-min.js"></script> -->

    <!-- Theme CSS -->
    <link href="<?php echo $elmer_style_css; ?>" rel="stylesheet" type="text/css">

    <!-- Bootstrap Core JavaScript -->
    <script src="/.elm/vendors/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
    <script src="/.elm/vendors/bower_components/bootstrap-validator/dist/validator.js"></script>

    <!-- Fancybox -->
    <link rel="stylesheet" href="/.elm/adminica/fancybox/source/jquery.fancybox.css" type="text/css" media="screen" />
    <script type="text/javascript" src="/.elm/adminica/fancybox/source/jquery.fancybox.pack.js"></script>

    <!-- GUI supplied situational CSS -->
    
    <!-- Start: Input validation: -->
    <!-- <script src="/gui/validation.js?update"></script> -->
    <!-- End: Input validation: -->  

    <!-- Data table JavaScript -->
    <script src="/.elm/vendors/bower_components/datatables/media/js/jquery.dataTables.min.js"></script>

    <!-- Start: Stylesheet for custom modifications by server owner -->
        <link rel="stylesheet" href="/.elm/dist/css/customer/customer.css" >
    <!-- Stop: Stylesheet for custom modifications by server owner -->

    <script>
        function openUrl(url, target) {
            window.open(url, target);
        }
    </script>

    <script language="Javascript">
        function setTextareaHeight(textarea, minLines, lineHeightInPixels) {
            var numLines = Math.max(textarea.value.split('\n').length, minLines); // Use the greater of actual lines or minLines
            var totalHeight = numLines * lineHeightInPixels;
            totalHeight += 8; // Slight one time adjustment
            textarea.style.height = totalHeight + "px";
        }
    </script>

<!-- Extra headers: Start -->
<?php echo $extra_headers; ?>

<!-- Bootstrap Datetimepicker CSS -->
<link href="/.elm/vendors/bower_components/eonasdan-bootstrap-datetimepicker/build/css/bootstrap-datetimepicker.min.css" rel="stylesheet" type="text/css"/>

<!-- Extra headers: End -->

</head>

<body>
<!-- Start: Wait overlay -->
<!-- End: Wait overlay -->

    <!-- Preloader -->
    <div class="preloader-it">
        <div class="la-anim-1"></div>
    </div>
    <!-- /Preloader -->
    <div class="wrapper theme-6-active pimary-color-blue">
        <!-- Top Menu Items -->
        <!-- /Top Menu Items -->
        
        <!-- Left Sidebar Menu -->
        <!-- /Left Sidebar Menu -->
        
        <!-- Right Sidebar Menu -->
        <!-- /Right Sidebar Menu -->

        <!-- Main Content -->
        <div class="page-wrapper" style="margin: 0 auto;">
            <div class="container-fluid" style="margin: 0 auto; width: 75%;">
                <!-- End: header_view.php --> 

                <!-- GUI Content -->
                <form class="validate_form" method="post" action="/wizard?action=post" ENCTYPE="multipart/form-data" id="waiting_overlay" data-toggle="validator" role="form">
                    <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" />
                    <div class="row">
                        <br>
                        <div id="main_container" class="container_16">                    
                            <!-- PagedBlock Wizard -->
                            <input id="SelectedTab" type="hidden" name="SelectedTab" value="basicSettingsTab" />
                            <div class="col-md-12">

                                <div class="panel panel-default  card-view">
                                    <div class="panel-heading mb-20 mt-20" style="background: #000000; height: 75px;">
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
                                        <div class="pull-right mt-5">
                                                <h6 class="txt-light">
                                                    <?php echo $iso_wizard_title; ?>
                                                </h6>
                                        </div>
                                    </div>

                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <div class="tab-struct custom-tab-1">
                                                <ul role="tablist" class="nav nav-tabs" id="Wizard">
                                                    <!-- Header Buttons Right -->
                                                        <li class="active"  role="presentation"><a aria-expanded="true" data-toggle="tab" role="tab" id="tab_id_1" href="#tabs-1"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="" data-container="body">1. <?php echo $step_1_title; ?></span></a></li>
                                                        <li  role="presentation"><a aria-expanded="false" data-toggle="tab" role="tab" id="tab_id_2" href="#tabs-2"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="" data-container="body">2. <?php echo $step_2_title; ?></span></a></li>

                                                        <li  role="presentation"><a aria-expanded="false" data-toggle="tab" role="tab" id="tab_id_3" href="#tabs-3"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="" data-container="body">3. <?php echo $step_3_title; ?></span></a></li>

                                                        <li  role="presentation"><a aria-expanded="false" data-toggle="tab" role="tab" id="tab_id_4" href="#tabs-4"><span data-toggle="tooltip" data-placement="top" title="" data-original-title="" data-container="body">4. <?php echo $step_4_title; ?></span></a></li>
                                                    <!-- /Header Buttons Right -->
                                                </ul>
                                            </div>  
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div id="collapse_1" class="panel-wrapper collapse in"><!-- panel-wrapper -->
                                        <div class="panel-body"><!-- panel-body -->
                                            <!-- Error Messages -->
                                            <?php echo $errors; ?>
                                            <!-- /Error Messages -->
                                            <div class="tab-content" id="TabContent_Wizard"><!-- tab-content -->

                                                <!-- Start: Language -->
                                                <div id="tabs-1" class="tab-pane fade active in" role="tabpanel">
                                                    <?php echo $step_1; ?>
    <div class="button-row">
        <!-- Next Button for the first tab -->
        <button type="button" class="btn btn-primary pull-right next-btn" data-next="tabs-2">Next</button>
    </div>
                                                </div>
                                                <!-- End: Language -->

                                                <!-- Start: License -->
                                                <div id="tabs-2" class="tab-pane fade " role="tabpanel">
                                                    <?php echo $step_2; ?>
    <div class="button-row">
        <!-- Previous Button -->
        <button type="button" class="btn btn-primary pull-left prev-btn" data-prev="tabs-1">Previous</button>
        <!-- Next Button -->
        <button type="button" class="btn btn-primary pull-right next-btn" data-next="tabs-3">Next</button>
    </div>
                                                </div>
                                                <!-- End: License -->

                                                <!-- Start: System Settings -->
                                                <div id="tabs-3" class="tab-pane fade " role="tabpanel">
                                                    <?php echo $step_3; ?>
    <div class="button-row">
        <!-- Previous Button -->
        <button type="button" class="btn btn-primary pull-left prev-btn" data-prev="tabs-2">Previous</button>
        <!-- Next Button -->
        <button type="button" class="btn btn-primary pull-right next-btn" data-next="tabs-4">Next</button>
    </div>
                                                </div>
                                                <!-- End: System Settings -->

                                                <!-- Start: Finalize -->
                                                <div id="tabs-4" class="tab-pane fade " role="tabpanel">
                                                    <?php echo $step_4; ?>
    <div class="button-row">
        <!-- Previous Button -->
        <button type="button" class="btn btn-primary pull-left prev-btn" data-prev="tabs-3">Previous</button>
        <!-- Save Button for the last tab -->
        <button type="submit" id="SaveButton" class="btn btn-anim btn-success pull-right" data-placement="top" title="Click here to save any changes made to this page." data-original-title="Click here to save any changes made to this page." data-container="body"   onclick="openUrl('javascript: if(document.form.onsubmit()) { top.code.info_show(document._form_form_wait, \'wait\'); document.form._save.value = 1; document.form.submit(); }', '_self')">
            <i class="icon-rocket mr-10"></i><span class="btn-text">Save</span>
        </button>
    </div>
                                                </div>
                                                <!-- End: Finalize -->

                                            </div><!-- /tab-content -->
                                        </div><!-- /panel-body -->
                                    </div><!-- /panel-wrapper -->
                                </div>
                                <div class="row">
                                    <div class="col-md-6 ml-10">
                                        <a href="/gui">
                                            <span>
                                                <svg viewBox="0 0 90 90" height="65.83812268091626" width="344.2851548328011" style="width: 344.285px; height: 65.8381px; position: absolute; top: 9px; left: 30px; z-index: 0; cursor: pointer; overflow: visible; transform: translate(-50%, -50%) scale(0.60415);"><defs id="SvgjsDefs2532"><linearGradient id="SvgjsLinearGradient2539"><stop id="SvgjsStop2540" stop-color="#2d388a" offset="0"></stop><stop id="SvgjsStop2541" stop-color="#00aeef" offset="1"></stop></linearGradient></defs><g id="SvgjsG2533" featurekey="v37d4h-0" transform="matrix(0.8427388072013855,0,0,0.8427388072013855,-1.373664294987499,-11.746093633286709)" fill="url(#SvgjsLinearGradient2539)"><polygon xmlns="http://www.w3.org/2000/svg" points="42.021,89.823 98.369,53.589 82.566,61.219 82.574,61.292 82.527,61.238 82.264,61.365 82.281,61.389 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="79.395,61.174 38.895,57.391 36.811,91.25 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="3.71,38.712 34.918,92.062 37.066,57.146 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="95.209,45.172 80.775,27.29 79.232,32.516 95.928,46.175 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="98.135,50.979 79.758,35.645 82.281,58.632 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="61.512,33.498 79.758,54.504 77.424,33.356 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="59.756,34.836 39.467,54.979 80.432,58.808 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="58.445,33.191 36.674,27.365 38.974,52.971 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="37.133,54.393 34.822,28.537 6.776,37.639 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="8.923,35.031 34.527,26.292 25.659,19.867 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="1.63,26.184 3.25,37.485 20.957,21.022 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="28.355,14.604 7.837,21.987 26.101,17.109 "></polygon><polygon xmlns="http://www.w3.org/2000/svg" points="36.751,24.86 60.131,31.196 77.148,30.907 78.672,25.749 67.951,19.853 48.173,14.486 32.256,13.938 28.073,18.586   "></polygon></g>
                                                </svg>
                                            </span>

                                            <span class="brand-text">
                                                <svg viewBox="0 0 344.2851548328011 65.83812268091626" height="65.83812268091626" width="344.2851548328011" style="width: 344.285px; height: 65.8381px; position: absolute; top: 3px; left: 100px; z-index: 0; cursor: pointer; overflow: visible; transform: translate(-50%, -50%) scale(0.60415);"><defs id="SvgjsDefs2532"><linearGradient id="SvgjsLinearGradient2539"><stop id="SvgjsStop2540" stop-color="#2d388a" offset="0"></stop><stop id="SvgjsStop2541" stop-color="#00aeef" offset="1"></stop></linearGradient></defs><g id="SvgjsG2534" featurekey="nameLeftFeature-0" transform="matrix(2.3108277320861816,0,0,2.3108277320861816,97.39659960527688,2.964957363641652)" fill="#000000"><path d="M9.0332 12.685500000000001 c1.6113 0.42969 2.6953 1.5137 2.6953 3.4766 c0 2.2754 -1.4746 3.8379 -4.1602 3.8379 l-5.5762 0 l0 -13.926 l4.3652 0 c2.7539 0 4.3652 1.4746 4.3652 3.75 c0 1.2793 -0.57617 2.3242 -1.6895 2.8613 z M6.3672 8.047 l-2.1191 0 l0 3.916 l2.3535 0 c1.25 0 1.9531 -0.88867 1.9531 -1.9922 c0 -1.0938 -0.76172 -1.9238 -2.1875 -1.9238 z M7.1484 17.9687 c1.582 0 2.3145 -0.9668 2.3145 -2.0605 c0 -1.1426 -0.75195 -2.0996 -2.4023 -2.0996 l-2.8125 0 l0 4.1602 l2.9004 0 z M16.992215625 17.9102 l4.5996 0 l0 2.0898 l-6.9336 0 l0 -13.926 l2.334 0 l0 11.836 z M29.042978125 20.18555 c-2.959 0 -5.2637 -1.6504 -5.2637 -4.9316 l0 -9.1797 l2.3438 0 l0 8.8574 c0 2.2168 1.2793 3.1641 2.9199 3.1641 s2.9395 -0.95703 2.9395 -3.1641 l0 -8.8574 l2.334 0 l0 9.1797 c0 3.2813 -2.3047 4.9316 -5.2734 4.9316 z M45.781225 8.154 l-5.4102 0 l0 3.8574 l4.7852 0 l0 2.0605 l-4.7852 0 l0 3.8379 l5.4102 0 l0 2.0898 l-7.7734 0 l0 -13.926 l7.7734 0 l0 2.0801 z"></path></g><g id="SvgjsG2535" featurekey="nameRightFeature-0" transform="matrix(2.2486753463745117,0,0,2.2486753463745117,210.06748997199185,4.197591649017779)" fill="#000000"><path d="M8.0762 20.19531 c-4.1504 0 -7.2168 -2.832 -7.2168 -7.2559 c0 -4.4336 3.0664 -7.2461 7.2168 -7.2461 c4.1406 0 7.207 2.8125 7.207 7.2461 c0 4.4238 -3.0664 7.2559 -7.207 7.2559 z M8.0762 17.5098 c2.4316 0 4.2969 -1.709 4.2969 -4.5703 c0 -2.8516 -1.8652 -4.5508 -4.2969 -4.5508 s-4.2969 1.6992 -4.2969 4.5508 c0 2.8613 1.8652 4.5703 4.2969 4.5703 z M27.61734375 5.888999999999999 l2.9199 0 l0 14.111 l-3.3887 0 l-6.25 -10.088 l0 10.088 l-2.9199 0 l0 -14.111 l3.3496 0 l6.2891 10.029 l0 -10.029 z M44.77528125 5.888999999999999 l-4.6387 7.3535 l0 6.7578 l-2.9395 0 l0 -6.6895 l-4.668 -7.4219 l3.2422 0 l2.8809 4.8047 l2.8906 -4.8047 l3.2324 0 z M45.214840625 20 l5.498 -7.3633 l-5.3418 -6.748 l3.5645 0 l3.5156 4.6289 l3.5156 -4.6289 l3.5645 0 l-5.3418 6.748 l5.498 7.3633 l-3.7305 0 l-3.5059 -4.9902 l-3.5059 4.9902 l-3.7305 0 z"></path></g>
                                                </svg>
                                            </span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Closes '<div class="row">' -->
                </form>
                <!-- /GUI Content -->

                <!-- End: gui_view.php --> 
                <!-- Start: footer_view.php -->

            </div>
            <!-- Footer -->
            <!-- /Footer -->
        </div>
        <!-- /Main Content -->

    </div>
    <!-- /#wrapper -->

    <!-- Slimscroll JavaScript -->
    <script src="/.elm/dist/js/jquery.slimscroll.js"></script>

    <!-- simpleWeather JavaScript -->
    <script src="/.elm/vendors/bower_components/moment/min/moment.min.js"></script>
    <script src="/.elm/vendors/bower_components/simpleWeather/jquery.simpleWeather.min.js"></script>
    
    <!-- EChartJS JavaScript -->
    <script src="/.elm/vendors/bower_components/echarts/dist/echarts-en.min.js"></script>
    <script src="/.elm/vendors/echarts-liquidfill.min.js"></script>
    
    <!-- Progressbar Animation JavaScript -->
    <script src="/.elm/vendors/bower_components/waypoints/lib/jquery.waypoints.min.js"></script>
    <script src="/.elm/vendors/bower_components/jquery.counterup/jquery.counterup.min.js"></script>
    
    <!-- Fancy Dropdown JS -->
    <script src="/.elm/dist/js/dropdown-bootstrap-extended.js"></script>
    
    <!-- Sparkline JavaScript -->
    <script src="/.elm/vendors/jquery.sparkline/dist/jquery.sparkline.min.js"></script>
    
    <!-- Owl JavaScript -->
    <script src="/.elm/vendors/bower_components/owl.carousel/dist/owl.carousel.min.js"></script>
    
    <!-- Toast JavaScript -->
    <script src="/.elm/vendors/bower_components/jquery-toast-plugin/dist/jquery.toast.min.js"></script>
    
    <!-- Piety JavaScript -->
    <script src="/.elm/vendors/bower_components/peity/jquery.peity.min.js"></script>
    
    <!-- Switchery JavaScript -->
    <script src="/.elm/vendors/bower_components/switchery/dist/switchery.min.js"></script>

    <!-- Bootstrap Colorpicker JavaScript -->
    <script src="/.elm/vendors/bower_components/mjolnic-bootstrap-colorpicker/dist/js/bootstrap-colorpicker.min.js"></script>
    
    <!-- Select2 JavaScript -->
    <script src="/.elm/vendors/bower_components/select2/dist/js/select2.full.min.js"></script>
    
    <!-- Bootstrap Select JavaScript -->
    <script src="/.elm/vendors/bower_components/bootstrap-select/dist/js/bootstrap-select.min.js"></script>
    
    <!-- Bootstrap Tagsinput JavaScript -->
    <script src="/.elm/vendors/bower_components/bootstrap-tagsinput/dist/bootstrap-tagsinput.min.js"></script>
    
    <!-- Bootstrap Touchspin JavaScript -->
    <script src="/.elm/vendors/bower_components/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.js"></script>
    
    <!-- Multiselect JavaScript -->
    <script src="/.elm/vendors/bower_components/multiselect/js/jquery.multi-select.js"></script>
     
    <!-- Bootstrap Switch JavaScript -->
    <script src="/.elm/vendors/bower_components/bootstrap-switch/dist/js/bootstrap-switch.min.js"></script>

    <!-- Form Advance Init JavaScript -->
    <script src="/.elm/dist/js/form-advance-data.js"></script>

    <!-- JavaScript -->
    
    <!-- Init JavaScript -->
    <script src="/.elm/dist/js/init.js"></script>

    <!-- Radio Composite Extender -->
    <script>
    $(document).ready(function() {
        $('input[type="checkbox"]').each(function() {
            var checkbox = $(this);
            var checkboxId = checkbox.attr('id');
            var targetDivId = checkboxId + '_mcb_wrapper';
            var targetDiv = $('#' + targetDivId);

            // Store the initial required state of each input
            targetDiv.find(':input').each(function() {
                $(this).data('initial-required', $(this).prop('required'));
            });

            // Function to toggle visibility and restore required attribute
            function toggleVisibilityAndRestoreRequired() {
                if (checkbox.is(':checked')) {
                    targetDiv.show();
                    targetDiv.find(':input').each(function() {
                        // Restore required attribute based on initial state
                        $(this).prop('required', $(this).data('initial-required'));
                    });
                } else {
                    targetDiv.hide();
                    targetDiv.find(':input').removeAttr('required');
                }
            }

            // Set initial state
            toggleVisibilityAndRestoreRequired();

            // Add change event handler to toggle visibility and restore required attribute
            checkbox.change(toggleVisibilityAndRestoreRequired);
        });
    });
    </script>
    <!-- /Radio Composite Extender -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize the validator for your form
    $('.validate_form').validator();

    // Disable direct clicking on tabs
    document.querySelectorAll('#Wizard a[data-toggle="tab"]').forEach(function(tabLink) {
        tabLink.addEventListener('click', function(event) {
            event.preventDefault(); // Prevent the default tab switch
            event.stopPropagation(); // Stop the event from propagating to parent elements
        });
    });

    // Handle Next button click
    document.querySelectorAll('.next-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var currentTabId = document.querySelector('.tab-pane.active').getAttribute('id');
            if (validateCurrentTab(currentTabId)) {
                var nextTab = this.getAttribute('data-next');
                showTab(nextTab);
            }
        });
    });

    // Handle Previous button click
    document.querySelectorAll('.prev-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var prevTab = this.getAttribute('data-prev');
            showTab(prevTab);
        });
    });

    // Handle Save (Submit) button click
    document.querySelector('.submit-btn').addEventListener('click', function () {
        var currentTabId = document.querySelector('.tab-pane.active').getAttribute('id');
        if (validateCurrentTab(currentTabId)) {
            document.querySelector('.validate_form').submit();
        }
    });

    // Function to show a tab
    function showTab(tabId) {
        // Hide all tabs content and remove 'active' class from all tab headers
        document.querySelectorAll('.tab-pane').forEach(function (tab) {
            tab.classList.remove('active');
            tab.classList.remove('in');
        });
        document.querySelectorAll('#Wizard li').forEach(function (tabHeader) {
            tabHeader.classList.remove('active');
        });

        // Show the specified tab content
        var tab = document.getElementById(tabId);
        tab.classList.add('active');
        tab.classList.add('in');

        // Find the corresponding tab header and make it active
        var tabHeader = document.querySelector(`#Wizard li a[href="#${tabId}"]`).parentNode;
        tabHeader.classList.add('active');
    }

    // Function to validate the current tab
    function validateCurrentTab(tabId) {
        // Find the .tab-pane element
        var tab = document.getElementById(tabId);

        // Validate only the fields in the current tab
        $(tab).find('input,select,textarea').each(function () {
            $(this).trigger('input'); // Or 'change', if needed
        });

        // Check if the form is valid
        var isValid = !$('.validate_form').data('bs.validator').hasErrors();

        return isValid;
    }
});

</script>


<!-- Extra footers: Start -->
<?php echo $extra_footers; ?>

<!-- Extra footers: End -->

</body>
</html>