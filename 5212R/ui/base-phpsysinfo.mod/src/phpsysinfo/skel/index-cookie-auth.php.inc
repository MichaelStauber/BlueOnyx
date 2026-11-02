/* BlueOnyx: require GUI-issued sysinfoAccess cookie (set by PHPSysinfo controller). */
if (!isset($_COOKIE['sysinfoAccess']) || $_COOKIE['sysinfoAccess'] === '') {
	header('HTTP/1.0 403 Forbidden');
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Access denied.\n";
	exit;
}
