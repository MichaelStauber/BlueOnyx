<?php

putenv("_HTTP_HOST=" . @$_SERVER["HTTP_HOST"]);
putenv("_SCRIPT_NAME=" . @$_SERVER["SCRIPT_NAME"]);
putenv("_SCRIPT_FILENAME=" . @$_SERVER["SCRIPT_FILENAME"]);
putenv("_DOCUMENT_ROOT=" . @$_SERVER["DOCUMENT_ROOT"]);
putenv("_REMOTE_ADDR=" . @$_SERVER["REMOTE_ADDR"]);
putenv("_SOWNER=" . @get_current_user());

// Over-quota header/footer: Check if fwrite is disabled:
if (!function_exists('fwrite')) {
    // Start output buffering to capture the page content
    ob_start();
    
    // Output the header message immediately
    echo "<header style='text-align: center; padding: 10px; background-color: #f8d7da; color: #721c24; border-bottom: 1px solid #f5c6cb;'>"
       . "This <a href='https://www.blueonyx.it' target='_blank' style='color: #721c24; text-decoration: underline;'>BlueOnyx</a> hosted virtual site is over quota. Access to some features has been restricted.</header>";
    
    // Register a shutdown function to append the footer
    register_shutdown_function(function() {
        $output = ob_get_clean();

        // Define the footer message
        $footer = "<footer style='text-align: center; padding: 10px; background-color: #f8d7da; color: #721c24; border-top: 1px solid #f5c6cb;'>"
                . "This <a href='https://www.blueonyx.it' target='_blank' style='color: #721c24; text-decoration: underline;'>BlueOnyx</a> hosted virtual site is over quota. Access to some features has been restricted.</footer>";
        // Output the header, original content, and footer
        echo $output . $footer;
    });
}
?>