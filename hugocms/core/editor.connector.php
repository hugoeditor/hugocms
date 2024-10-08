<?php
error_reporting(0); // Set E_ALL for debuging

// // Optional exec path settings (Default is called with command name only)
// define('ELFINDER_TAR_PATH',      '/PATH/TO/tar');
// define('ELFINDER_GZIP_PATH',     '/PATH/TO/gzip');
// define('ELFINDER_BZIP2_PATH',    '/PATH/TO/bzip2');
// define('ELFINDER_XZ_PATH',       '/PATH/TO/xz');
// define('ELFINDER_ZIP_PATH',      '/PATH/TO/zip');
// define('ELFINDER_UNZIP_PATH',    '/PATH/TO/unzip');
// define('ELFINDER_RAR_PATH',      '/PATH/TO/rar');
// define('ELFINDER_UNRAR_PATH',    '/PATH/TO/unrar');
// define('ELFINDER_7Z_PATH',       '/PATH/TO/7za');
// define('ELFINDER_CONVERT_PATH',  '/PATH/TO/convert');
// define('ELFINDER_IDENTIFY_PATH', '/PATH/TO/identify');
// define('ELFINDER_EXIFTRAN_PATH', '/PATH/TO/exiftran');
// define('ELFINDER_JPEGTRAN_PATH', '/PATH/TO/jpegtran');
// define('ELFINDER_FFMPEG_PATH',   '/PATH/TO/ffmpeg');

// define('ELFINDER_CONNECTOR_URL', 'URL to this connector script');  // see elFinder::getConnectorUrl()

// define('ELFINDER_DEBUG_ERRORLEVEL', -1); // Error reporting level of debug mode

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
require __DIR__.'/../plugins/elfinder/php/autoload.php';

// ===============================================

// // Enable FTP connector netmount
elFinder::$netDrivers['ftp'] = 'FTP';
// ===============================================

// // Required for Dropbox network mount
// // Installation by composer
// // `composer require kunalvarma05/dropbox-php-sdk` on php directory
// // Enable network mount
// elFinder::$netDrivers['dropbox2'] = 'Dropbox2';
// // Dropbox2 Netmount driver need next two settings. You can get at https://www.dropbox.com/developers/apps
// // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=dropbox2&host=1"
// // If the elFinder HTML element ID is not "elfinder", you need to change "host=1" to "host=ElementID"
// define('ELFINDER_DROPBOX_APPKEY',    '');
// define('ELFINDER_DROPBOX_APPSECRET', '');
// ===============================================

// // Required for Google Drive network mount
// // Installation by composer
// // `composer require google/apiclient:^2.0` on php directory
// // Enable network mount
// elFinder::$netDrivers['googledrive'] = 'GoogleDrive';
// // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
// // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
// // If the elFinder HTML element ID is not "elfinder", you need to change "host=1" to "host=ElementID"
// define('ELFINDER_GOOGLEDRIVE_CLIENTID',     '');
// define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', '');
// // Required case when Google API is NOT added via composer
// define('ELFINDER_GOOGLEDRIVE_GOOGLEAPICLIENT', '/path/to/google-api-php-client/vendor/autoload.php');
// ===============================================

// // Required for Google Drive network mount with Flysystem
// // Installation by composer
// // `composer require nao-pon/flysystem-google-drive:~1.1 nao-pon/elfinder-flysystem-driver-ext` on php directory
// // Enable network mount
// elFinder::$netDrivers['googledrive'] = 'FlysystemGoogleDriveNetmount';
// // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
// // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
// // If the elFinder HTML element ID is not "elfinder", you need to change "host=1" to "host=ElementID"
// define('ELFINDER_GOOGLEDRIVE_CLIENTID',     '');
// define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', '');
// // And "php/.tmp" directory must exist and be writable by PHP.
// ===============================================

// // Required for One Drive network mount
// //  * cURL PHP extension required
// //  * HTTP server PATH_INFO supports required
// // Enable network mount
// elFinder::$netDrivers['onedrive'] = 'OneDrive';
// // OneDrive Netmount driver need next two settings. You can get at
// // https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps
// // AND require register redirect url to "YOUR_CONNECTOR_URL/netmount/onedrive/1"
// // If the elFinder HTML element ID is not "elfinder", you need to change "/1" to "/ElementID"
// define('ELFINDER_ONEDRIVE_CLIENTID',     '');
// define('ELFINDER_ONEDRIVE_CLIENTSECRET', '');
// ===============================================

// // Required for Box network mount
// //  * cURL PHP extension required
// // Enable network mount
// elFinder::$netDrivers['box'] = 'Box';
// // Box Netmount driver need next two settings. You can get at https://developer.box.com
// // AND require register redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=box&host=1"
// // If the elFinder HTML element ID is not "elfinder", you need to change "host=1" to "host=ElementID"
// define('ELFINDER_BOX_CLIENTID',     '');
// define('ELFINDER_BOX_CLIENTSECRET', '');
// ===============================================


// // Zoho Office Editor APIKey
// // https://www.zoho.com/docs/help/office-apis.html
// define('ELFINDER_ZOHO_OFFICE_APIKEY', '');
// ===============================================

// // Online converter (online-convert.com) APIKey
// // https://apiv2.online-convert.com/docs/getting_started/api_key.html
// define('ELFINDER_ONLINE_CONVERT_APIKEY', '');
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
require __DIR__.'/editor.restricted.php';  //Angedacht: der Admin-User kann alles, andere nur bestimmte Dateien/Modies (Easy und Normal), der zuerst angelegte User wird Admin

$cmsroot_dir = $project_dir; // CMS root directory (Working directory)
$content_dir = $cmsroot_dir.'content/';
$static_dir = $cmsroot_dir.'static/';
$trash_dir = $cmsroot_dir.'.trash/';

if(isset($_GET['mode']) && !empty($_GET['mode'])) {
    $mode = $_GET['mode'];
} else {
    $mode = 'easy';
}

$opt = array();
if('easy' == $mode) {
    $css_dir = $static_dir.'css/';
    $img_dir = $static_dir.'images/';
    $js_dir = $static_dir.'js/';

    $opts = array(
        // 'debug' => true,
        'roots' => array(
            // Content volume
            array(
                'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                'path'          => $content_dir,                 // path to files (REQUIRED)
                'URL'           => dirname($_SERVER['PHP_SELF']) . '/content/', // URL to files (REQUIRED)
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'tmbPath'       => false,          //
                'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
                'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
                'attributes' => array(
                    array(
                        'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                        'read'    => false,
                        'write'   => false,
                        'hidden'  => true,
                        'locked'  => true
                    )
                )
            ),
        ),
    );

    // CCS volume
    if(is_dir($css_dir)) {
       array_push($opts['roots'], array(
           'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
            'path'          => $css_dir,                 // path to files (REQUIRED)
            'URL'           => dirname($_SERVER['PHP_SELF']) . '/css/', // URL to files (REQUIRED)
            'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
            'tmbPath'       => false,          //
            'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
            'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
            'attributes' => array(
                array(
                    'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                    'read'    => false,
                    'write'   => false,
                    'hidden'  => true,
                    'locked'  => true
                )
            )
        ));
    }

    // Javascript volume
    if(is_dir($js_dir)) {
        array_push($opts['roots'], array(
            'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
             'path'          => $js_dir,                 // path to files (REQUIRED)
             'URL'           => dirname($_SERVER['PHP_SELF']) . '/js/', // URL to files (REQUIRED)
             'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
             'tmbPath'       => false,          //
             'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
             'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
             'attributes' => array(
                 array(
                     'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                     'read'    => false,
                     'write'   => false,
                     'hidden'  => true,
                     'locked'  => true
                 )
             )
         ));
     }

     // Images volume
     if(is_dir($img_dir)) {
        array_push($opts['roots'], array(
            'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
             'path'          => $img_dir,                 // path to files (REQUIRED)
             'URL'           => dirname($_SERVER['PHP_SELF']) . '/images/', // URL to files (REQUIRED)
             'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
             'tmbPath'       => false,          //
             'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
             'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
             'attributes' => array(
                 array(
                     'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                     'read'    => false,
                     'write'   => false,
                     'hidden'  => true,
                     'locked'  => true
                 )
             )
         ));
     }

    // Trash volume
    array_push($opts['roots'], array(
        'id'            => '1',
        'driver'        => 'Trash',
        'path'          => $trash_dir,
        'tmbURL'        => dirname($_SERVER['PHP_SELF']) . '/.trash/',
        'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
        'uploadDeny'    => array('all'),                // Recomend the same settings as the original volume that uses the trash
        'uploadAllow'   => $mime_type, // Same as above
        'uploadOrder'   => array('deny', 'allow'),      // Same as above
        'accessControl' => 'access',                    // Same as above
    ));

}
elseif('normal' == $mode) {
    $opts = array(
        // 'debug' => true,
        'roots' => array(
            // Content volume
            array(
                'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                'path'          => $content_dir,                 // path to files (REQUIRED)
                'URL'           => dirname($_SERVER['PHP_SELF']) . '/content/', // URL to files (REQUIRED)
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'tmbPath'       => false,          //
                'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
                'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
                'attributes' => array(
                    array(
                        'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                        'read'    => false,
                        'write'   => false,
                        'hidden'  => true,
                        'locked'  => true
                    )
                )
            ),
            array(
                'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                'path'          => $static_dir,                 // path to files (REQUIRED)
                'URL'           => dirname($_SERVER['PHP_SELF']) . '/static/', // URL to files (REQUIRED)
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'tmbPath'       => false,          //
                'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
                'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)
                'attributes' => array(
                    array(
                        'pattern' => '/\/edit/',            // Regulärer Ausdruck, um das Verzeichnis 'private' zu identifizieren
                        'read'    => false,                     // Lesen nicht erlaubt
                        'write'   => false,                     // Schreiben nicht erlaubt
                        'hidden'  => true,                      // Versteckt
                        'locked'  => true                       // Gesperrt
                    ),
                    array(
                        'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                        'read'    => false,
                        'write'   => false,
                        'hidden'  => true,
                        'locked'  => true
                    )
                )
            ),
            // Trash volume
            array(
                'id'            => '1',
                'driver'        => 'Trash',
                'path'          => $trash_dir,
                'tmbURL'        => false,
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'uploadDeny'    => array('all'),                // Recomend the same settings as the original volume that uses the trash
                'uploadAllow'   => $mime_type, // Same as above
                'uploadOrder'   => array('deny', 'allow'),      // Same as above
                'accessControl' => 'access',                    // Same as above
            ),
        ),
    );
}
elseif('admin' == $mode) {
    $opts = array(
        // 'debug' => true,
        'roots' => array(
            // CMS root volume (Working directory)
            array(
                'driver'        => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                'path'          => $cmsroot_dir,                 // path to files (REQUIRED)
                'URL'           => dirname($_SERVER['PHP_SELF']) . '/content/', // URL to files (REQUIRED)
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'tmbPath'       => false,          //
                'uploadOrder'   => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
                'accessControl' => 'access',                     // disable and hide dot starting files (OPTIONAL)


                'attributes' => array(
                    array(
                        'pattern' => '/static\/edit/',            // Regulärer Ausdruck, um das Verzeichnis 'private' zu identifizieren
                        'read'    => false,                     // Lesen nicht erlaubt
                        'write'   => false,                     // Schreiben nicht erlaubt
                        'hidden'  => true,                      // Versteckt
                        'locked'  => true                       // Gesperrt
                    ),
                    array(
                        'pattern' => '/\.php$/',                // Regulärer Ausdruck für alle .txt-Dateien
                        'read'    => false,
                        'write'   => false,
                        'hidden'  => true,
                        'locked'  => true
                    )
                )



            ),
            // Trash volume
            array(
                'id'            => '1',
                'driver'        => 'Trash',
                'path'          => $trash_dir,
                'tmbURL'        => false,
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', // to make hash same to Linux one on windows too
                'uploadDeny'    => array('all'),                // Recomend the same settings as the original volume that uses the trash
                'uploadAllow'   => $mime_type, // Same as above
                'uploadOrder'   => array('deny', 'allow'),      // Same as above
                'accessControl' => 'access',                    // Same as above
            ),
        ),
    );
}

// run elFinder
$connector = new elFinderConnector(new elFinder($opts));
$connector->run();
