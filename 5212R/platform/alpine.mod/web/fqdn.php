<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$hn = trim(`uname -n`);

echo json_encode(['fqdn' => $hn]);

?>
