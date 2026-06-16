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
    <?php echo $layout; ?>

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

<!-- Extra headers: End -->

</head>

<?php echo $body_open_tag; ?>

<!-- Start: Wait overlay -->
<?php echo $overlay; ?>
<!-- End: Wait overlay -->

    <!-- Preloader -->
    <div class="preloader-it">
        <div class="la-anim-1"></div>
    </div>
    <!-- /Preloader -->
    <div class="wrapper <?php echo $elmer_active_theme; ?> <?php echo $elmer_primary_color; ?>">
        <!-- Top Menu Items -->
        <!-- /Top Menu Items -->
        
        <!-- Left Sidebar Menu -->
        <!-- /Left Sidebar Menu -->
        
        <!-- Right Sidebar Menu -->
<?php echo $vsite_and_user_quicksearch_html; ?>
        <!-- /Right Sidebar Menu -->

        <!-- Main Content -->
        <div class="page-wrapper" style="margin-left: 0px;">
            <div class="container-fluid">

                <!-- Title -->
                <div class="row heading-bg">
                    <div class="col-lg-3 col-md-4 col-sm-4 col-xs-12">
                        <h5 class="txt-dark"><?php echo $active_page_category; ?></h5>
                    </div>
                    <!-- Breadcrumb -->
                    <!-- /Breadcrumb -->
                </div>
                <!-- /Title -->

                <!-- End: header_view.php --> 
<?php echo $debug; ?>

                <!-- GUI Content -->
                <div class="row">
<?php echo $page_body; ?>
                </div><!-- Closes '<div class="row">' -->
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

    <!-- Logout Modal -->
    <script>
    $( document ).ready(function() {
        "use strict";
        
        if( $('#logout_modal').length > 0 ){
            $('#logout_modal').on('show.bs.modal', function (event) {
              var button = $(event.relatedTarget) // Button that triggered the modal
            });
        }
    });
    </script>

    <script>
    $(document).ready(function() {
        // Select all buttons with a data-link attribute
        $('.link_button[data-link]').click(function() {
            var link = $(this).data('link');
            if (link) {
                window.location.href = link; // Redirect to the URL specified in data-link
            }
        });
    });
    </script>

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

    <div class="modal fade" id="logout_modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Cancel"><span aria-hidden="true">&times;</span></button>
                    <h5 class="modal-title" id="modal-title"><?php echo $logout_text ?></h5>
                </div>
                <div class="modal-body">
                    <h5 class="mb-10"><?php echo $page_title ?></h5>
                    <p class="text-muted"><?php echo $logoutConfirm ?></p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success btn-anim link_button" data-link="/logout/true"><i class="fa fa-sign-out"></i><span class="btn-text"><?php echo $logout_text ?></span></button>
                    <button class="btn btn-danger btn-anim" data-dismiss="modal"><i class="fa fa-times"></i><span class="btn-text"><?php echo $cancel_text ?></span></button>
                </div>
            </div>
        </div>
    </div>
    <!-- /Logout Modal -->

<!-- Extra footers: Start -->
<?php echo $extra_footers; ?>

<!-- Extra footers: End -->

</body>
</html>