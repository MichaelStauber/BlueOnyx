<?php

error_reporting(0); // Set E_ALL for debuging

// // Optional exec path settings (Default is called with command name only)
define('ELFINDER_TAR_PATH',      '/usr/bin/tar');
define('ELFINDER_GZIP_PATH',     '/usr/bin/gzip');
define('ELFINDER_BZIP2_PATH',    '');
define('ELFINDER_XZ_PATH',       '/usr/bin/xz');
define('ELFINDER_ZIP_PATH',      '/usr/bin/zip');
define('ELFINDER_UNZIP_PATH',    '/usr/bin/unzip');
// define('ELFINDER_RAR_PATH',      '/PATH/TO/rar');
// define('ELFINDER_UNRAR_PATH',    '/PATH/TO/unrar');
// define('ELFINDER_7Z_PATH',       '/PATH/TO/7za');
define('ELFINDER_CONVERT_PATH',  '/usr/bin/convert');
define('ELFINDER_IDENTIFY_PATH', '/usr/bin/identify');
// define('ELFINDER_EXIFTRAN_PATH', '/PATH/TO/exiftran');
// define('ELFINDER_JPEGTRAN_PATH', '/PATH/TO/jpegtran');
// define('ELFINDER_FFMPEG_PATH',   '/PATH/TO/ffmpeg');

// define('ELFINDER_CONNECTOR_URL', 'URL to this connector script');  // see elFinder::getConnectorUrl()

// define('ELFINDER_DEBUG_ERRORLEVEL', -1); // Error reporting level of debug mode
//define('ELFINDER_DEBUG_ERRORLEVEL', E_ALL);

// // To Enable(true) handling of PostScript files by ImageMagick
// // It is disabled by default as a countermeasure 
// // of Ghostscript multiple -dSAFER sandbox bypass vulnerabilities
// // see https://www.kb.cert.org/vuls/id/332928
// define('ELFINDER_IMAGEMAGICK_PS', true);
// ===============================================

// // load composer autoload before load elFinder autoload If you need composer
// // You need to run the composer command in the php directory.
is_readable('./vendor/autoload.php') && require './vendor/autoload.php';

// // elFinder autoload
require './autoload.php';
// ===============================================

// // Enable FTP connector netmount
elFinder::$netDrivers['ftp'] = 'FTP';
// ===============================================

// // Zip Archive editor
// // Installation by composer
// // `composer require nao-pon/elfinder-flysystem-ziparchive-netmount` on php directory
// define('ELFINDER_DISABLE_ZIPEDITOR', false); // set `true` to disable zip editor
// ===============================================

/**
 * Simple function to demonstrate how to control file access using "accessControl" callback.
 * This method will disable accessing files/folders starting from '.' (dot)
 *
 * @param  string    $attr    attribute name (read|write|locked|hidden)
 * @param  string    $path    absolute file path
 * @param  string    $data    value of volume option `accessControlData`
 * @param  object    $volume  elFinder volume driver object
 * @param  bool|null $isDir   path is directory (true: directory, false: file, null: unknown)
 * @param  string    $relpath file path relative to volume root directory started with directory separator
 * @return bool|null
 **/
function access($attr, $path, $data, $volume, $isDir, $relpath) {
    $basename = basename($path);
    return $basename[0] === '.'                  // if file/folder begins with '.' (dot)
             && strlen($relpath) !== 1           // but with out volume root
        ? !($attr == 'read' || $attr == 'write') // set read+write to false, other (locked+hidden) set to true
        :  null;                                 // else elFinder decide it itself
}


// Documentation for connector options:
// https://github.com/Studio-42/elFinder/wiki/Connector-configuration-options
// FTP connection options

$host = $_SERVER['SERVER_NAME'];
$username = $_COOKIE['loginName'];
$port = '21';

// Test the decryption
if (isset($_COOKIE['fm_enc_auth'])) {
    $password = getAuth($_COOKIE['fm_enc_auth']);
}
else {
    die("Unauthorized Access!");
    exit;
}

$opts = [
    'roots' => [
        [
            'driver'        => 'FTP',          // Use FTP driver
            'host'          => '127.0.0.1',    // FTP server hostname or IP
            'user'          => $username,      // FTP username
            'pass'          => $password,      // FTP password
            'port'          => $port,          // FTP port
            'path'          => '/',            // Default path on FTP
            'timeout'       => 10,             // Connection timeout
            'alias'         => $host,          // Display name for the volume
            'mimeDetect'    => 'internal',
            'tmbPath'       => '',             // Optional: path for thumbnail storage
            'tmbURL'        => '',             // Optional: URL for thumbnails
            'separator'     => '/',            // Optional: Directory separator
            'accessControl' => 'access',       // Disable access for non-authenticated users
            'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows 
        ]
    ]
];

// Run elFinder
$connector = new elFinderConnector(new elFinder($opts));
$connector->run();

//
//--- Functions:
//

function getAuth($encodedData = '') {
    // Decode the base64 encoded data
    $decodedData = base64_decode($encodedData);
    if ($decodedData === false) {
        error_log("Decoding failed: Invalid base64 encoding.");
        return null;
    }

    // Fetch and convert the encryption key to binary
    $keyPath = '/usr/sausalito/capcache/authkey';
    $keyHex = trim(file_get_contents($keyPath));
    if ($keyHex === false || strlen($keyHex) !== 64) { // 64 hex characters for 32 bytes
        error_log("Key file read failed or key is not 64 hex characters long.");
        return null;
    }

    $binaryKey = hex2bin($keyHex);
    if ($binaryKey === false || strlen($binaryKey) !== 32) {
        error_log("Invalid key: Unable to convert to binary or incorrect length.");
        return null;
    }

    // Extract IV and ciphertext
    $ivLength = openssl_cipher_iv_length('AES-256-CBC');
    if (strlen($decodedData) < $ivLength) {
        error_log("Decryption failed: Decoded data length is shorter than IV length.");
        return null;
    }

    $iv = substr($decodedData, 0, $ivLength);
    $ciphertext = substr($decodedData, $ivLength);

    if (strlen($iv) !== $ivLength) {
        error_log("IV length mismatch: Expected $ivLength, got " . strlen($iv));
        return null;
    }

    // Decrypt the data
    $decryptedData = openssl_decrypt($ciphertext, 'AES-256-CBC', $binaryKey, OPENSSL_RAW_DATA, $iv);
    if ($decryptedData === false) {
        error_log("Decryption failed: " . openssl_error_string());
        return null;
    }

    return $decryptedData;
}
