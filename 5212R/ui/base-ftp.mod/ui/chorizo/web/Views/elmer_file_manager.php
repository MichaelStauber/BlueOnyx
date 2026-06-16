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


    <!-- Section CSS -->
    <!-- jQuery UI (REQUIRED) -->
    <link rel="stylesheet" href="/.wftp/jquery/jquery-ui-1.13.2.css" type="text/css">

    <!-- elfinder css -->
    <link rel="stylesheet" href="/.wftp/css/commands.css"    type="text/css">
    <link rel="stylesheet" href="/.wftp/css/common.css"      type="text/css">
    <link rel="stylesheet" href="/.wftp/css/contextmenu.css" type="text/css">
    <link rel="stylesheet" href="/.wftp/css/cwd.css"         type="text/css">
    <link rel="stylesheet" href="/.wftp/css/dialog.css"      type="text/css">
    <link rel="stylesheet" href="/.wftp/css/fonts.css"       type="text/css">
    <link rel="stylesheet" href="/.wftp/css/navbar.css"      type="text/css">
    <link rel="stylesheet" href="/.wftp/css/places.css"      type="text/css">
    <link rel="stylesheet" href="/.wftp/css/quicklook.css"   type="text/css">
    <link rel="stylesheet" href="/.wftp/css/statusbar.css"   type="text/css">
    <link rel="stylesheet" href="/.wftp/css/theme.css"       type="text/css">
    <link rel="stylesheet" href="/.wftp/css/toast.css"       type="text/css">
    <link rel="stylesheet" href="/.wftp/css/toolbar.css"     type="text/css">

    <!-- Section JavaScript -->
    <!-- jQuery and jQuery UI (REQUIRED) -->
    <script src="/.wftp/jquery/jquery-3.7.1.js" type="text/javascript" charset="utf-8"></script>
    <script src="/.wftp/jquery/jquery-ui-1.13.2.js" type="text/javascript" charset="utf-8"></script>

    <!-- elfinder core -->
    <script src="/.wftp/js/elFinder.js"></script>
    <script src="/.wftp/js/elFinder.version.js"></script>
    <script src="/.wftp/js/jquery.elfinder.js"></script>
    <script src="/.wftp/js/elFinder.mimetypes.js"></script>
    <script src="/.wftp/js/elFinder.options.js"></script>
    <script src="/.wftp/js/elFinder.options.netmount.js"></script>
    <script src="/.wftp/js/elFinder.history.js"></script>
    <script src="/.wftp/js/elFinder.command.js"></script>
    <script src="/.wftp/js/elFinder.resources.js"></script>

    <!-- elfinder dialog -->
    <script src="/.wftp/js/jquery.dialogelfinder.js"></script>

    <!-- elfinder default lang -->
    <script src="/.wftp/js/i18n/elfinder.en.js"></script>

    <!-- elfinder ui -->
    <script src="/.wftp/js/ui/button.js"></script>
    <script src="/.wftp/js/ui/contextmenu.js"></script>
    <script src="/.wftp/js/ui/cwd.js"></script>
    <script src="/.wftp/js/ui/dialog.js"></script>
    <script src="/.wftp/js/ui/fullscreenbutton.js"></script>
    <script src="/.wftp/js/ui/navbar.js"></script>
    <script src="/.wftp/js/ui/navdock.js"></script>
    <script src="/.wftp/js/ui/overlay.js"></script>
    <script src="/.wftp/js/ui/panel.js"></script>
    <script src="/.wftp/js/ui/path.js"></script>
    <script src="/.wftp/js/ui/places.js"></script>
    <script src="/.wftp/js/ui/searchbutton.js"></script>
    <script src="/.wftp/js/ui/sortbutton.js"></script>
    <script src="/.wftp/js/ui/stat.js"></script>
    <script src="/.wftp/js/ui/toast.js"></script>
    <script src="/.wftp/js/ui/toolbar.js"></script>
    <script src="/.wftp/js/ui/tree.js"></script>
    <script src="/.wftp/js/ui/uploadButton.js"></script>
    <script src="/.wftp/js/ui/viewbutton.js"></script>
    <script src="/.wftp/js/ui/workzone.js"></script>

    <!-- elfinder commands -->
    <script src="/.wftp/js/commands/archive.js"></script>
    <script src="/.wftp/js/commands/back.js"></script>
    <script src="/.wftp/js/commands/chmod.js"></script>
    <script src="/.wftp/js/commands/colwidth.js"></script>
    <script src="/.wftp/js/commands/copy.js"></script>
    <script src="/.wftp/js/commands/cut.js"></script>
    <script src="/.wftp/js/commands/download.js"></script>
    <script src="/.wftp/js/commands/duplicate.js"></script>
    <script src="/.wftp/js/commands/edit.js"></script>
    <script src="/.wftp/js/commands/empty.js"></script>
    <script src="/.wftp/js/commands/extract.js"></script>
    <script src="/.wftp/js/commands/forward.js"></script>
    <script src="/.wftp/js/commands/fullscreen.js"></script>
    <script src="/.wftp/js/commands/getfile.js"></script>
    <script src="/.wftp/js/commands/help.js"></script>
    <script src="/.wftp/js/commands/hidden.js"></script>
    <script src="/.wftp/js/commands/hide.js"></script>
    <script src="/.wftp/js/commands/home.js"></script>
    <script src="/.wftp/js/commands/info.js"></script>
    <script src="/.wftp/js/commands/mkdir.js"></script>
    <script src="/.wftp/js/commands/mkfile.js"></script>
    <script src="/.wftp/js/commands/netmount.js"></script>
    <script src="/.wftp/js/commands/open.js"></script>
    <script src="/.wftp/js/commands/opendir.js"></script>
    <script src="/.wftp/js/commands/opennew.js"></script>
    <script src="/.wftp/js/commands/paste.js"></script>
    <script src="/.wftp/js/commands/places.js"></script>
    <script src="/.wftp/js/commands/preference.js"></script>
    <script src="/.wftp/js/commands/quicklook.js"></script>
    <script src="/.wftp/js/commands/quicklook.plugins.js"></script>
    <script src="/.wftp/js/commands/reload.js"></script>
    <script src="/.wftp/js/commands/rename.js"></script>
    <script src="/.wftp/js/commands/resize.js"></script>
    <script src="/.wftp/js/commands/restore.js"></script>
    <script src="/.wftp/js/commands/rm.js"></script>
    <script src="/.wftp/js/commands/search.js"></script>
    <script src="/.wftp/js/commands/selectall.js"></script>
    <script src="/.wftp/js/commands/selectinvert.js"></script>
    <script src="/.wftp/js/commands/selectnone.js"></script>
    <script src="/.wftp/js/commands/sort.js"></script>
    <script src="/.wftp/js/commands/undo.js"></script>
    <script src="/.wftp/js/commands/up.js"></script>
    <script src="/.wftp/js/commands/upload.js"></script>
    <script src="/.wftp/js/commands/view.js"></script>

    <!-- elfinder 1.x connector API support (OPTIONAL) -->
    <script src="/.wftp/js/proxy/elFinderSupportVer1.js"></script>

    <!-- Extra contents editors (OPTIONAL) -->
    <script src="/.wftp/js/extras/editors.default.js"></script>

    <!-- GoogleDocs Quicklook plugin for GoogleDrive Volume (OPTIONAL) -->
    <script src="/.wftp/js/extras/quicklook.googledocs.js"></script>

    <!-- elfinder initialization  -->
    <script>
        $(function() {
            $('#elfinder').css('height', '700px');
            $('#elfinder').elfinder(
                // 1st Arg - options
                {

                    // Disable CSS auto loading
                    cssAutoLoad : false,

                    // Base URL to css/*, js/*
                    baseUrl : './',

                    // Connector URL
                    url : '/.wftp/php/connector.minimal.php',

                    // Callback when a file is double-clicked
                    getFileCallback : function(file) {
                        // ...
                    },
                },
                
                // 2nd Arg - before boot up function
                function(fm, extraObj) {
                    // `init` event callback function
                    fm.bind('init', function() {
                        // Optional for Japanese decoder "extras/encoding-japanese.min"
                        delete fm.options.rawStringDecoder;
                        if (fm.lang === 'ja') {
                            fm.loadScript(
                                [ fm.baseUrl + 'js/extras/encoding-japanese.min.js' ],
                                function() {
                                    if (window.Encoding && Encoding.convert) {
                                        fm.options.rawStringDecoder = function(s) {
                                            return Encoding.convert(s,{to:'UNICODE',type:'string'});
                                        };
                                    }
                                },
                                { loadType: 'tag' }
                            );
                        }
                    });
                    
                    // Optional for set document.title dynamically.
                    var title = document.title;
                    fm.bind('open', function() {
                        var path = '',
                            cwd  = fm.cwd();
                        if (cwd) {
                            path = fm.path(cwd.hash) || null;
                        }
                        document.title = path? path + ':' + title : title;
                    }).bind('destroy', function() {
                        document.title = title;
                    });
                }
            );
        });
    </script>
</head>
<body>
    <div id="elfinder"></div>
    <br>

<!-- Extra footers: Start -->
<?php echo $extra_footers; ?>

<!-- Extra footers: End -->

</body>
</html>