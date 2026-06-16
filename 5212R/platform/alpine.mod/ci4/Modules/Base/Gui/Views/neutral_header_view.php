<!DOCTYPE html>
<html lang=<?php echo '"' . $localization . '"';?> dir="ltr" class="no-js">
<head>
	<meta charset="<?php echo $charset; ?>">
	<meta http-equiv="content-type" content="text/html; charset=<?php echo $charset;?>">
	<title><?php echo $page_title;?></title>

<!-- iPhone, iPad and Android specific settings -->

	<meta name="viewport" content="width=device-width; initial-scale=1; maximum-scale=1;">
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

	<link href="/.adm/images/interface/iOS_icon.png" rel="apple-touch-icon">

<!-- Styles -->

	<link rel="stylesheet" type="text/css" href="/.adm/styles/adminica/combined-common-mini.css">

<!-- Start: Style Switcher -->
	<link rel="stylesheet" href="/.adm/styles/themes/layout_switcher.php?default=<?php echo $layout; ?>" >
	<link rel="stylesheet" href="/.adm/styles/themes/nav_switcher.php?default=switcher.css" >
	<link rel="stylesheet" href="/.adm/styles/themes/skin_switcher.php?default=switcher.css" >
	<link rel="stylesheet" href="/.adm/styles/themes/theme_switcher.php?default=theme_blue.css" >
	<link rel="stylesheet" href="/.adm/styles/themes/bg_switcher.php?default=bg_silver.css" >
<!-- End: Style Switcher -->	

	<link rel="stylesheet" href="/.adm/styles/adminica/colours.css"> <!-- overrides the default colour scheme -->
	<script src="/gui/pluginsmin.js?update"></script>

<?php echo $bx_css; ?>

	<!-- Start: Overrides for Adminica functions:-->
	<script src="/gui/validation.js?update"></script>
	<!-- End: Overrides for Adminica functions:-->	

	<!-- DataTables -->
	<link rel="stylesheet" type="text/css" href="/.adm/elmer/datatables/media/css/dataTables.jqueryui.min.css">
	<script type="text/javascript" src="/.adm/elmer/datatables/media/js/jquery.dataTables.min.js"></script>
	<!-- /DataTables -->

	<!-- Compat CSS from Elmer: -->
	<link rel="stylesheet" type="text/css" href="/.elm/dist/css/font-awesome.min.css" >
	<link rel="stylesheet" type="text/css" href="/.adm/elmer/elmer_compat.css" >

<!-- Start: Stylesheet for custom modifications by server owner -->
    <link rel="stylesheet" href="/.adm/styles/customer/customer.css" >
<!-- Stop: Stylesheet for custom modifications by server owner -->

    <script>
        function openUrl(url, target) {
            window.open(url, target);
        }
    </script>

<!-- Extra headers: Start -->
<?php echo $extra_headers; ?>
<!-- Extra headers: End -->

	</head>
<body>
<!-- End: framework_head.php -->

<!-- Start: Wait overlay -->
<?php echo $overlay; ?>
<!-- End: Wait overlay -->

<!-- End: header_view.php --> 
