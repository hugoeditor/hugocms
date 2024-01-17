<?php
$title = $_SERVER["HTTP_HOST"];
session_start();

function siteURL()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'].'/';
    return $protocol.$domainName;
}
define('SITE_URL', siteURL());

$license_error = "";
$license_user = "";

if(file_exists('backend/editor.pro.control.php') && file_exists(USER_FILE) && file_exists(LICENSE_FILE))
{
    $license_user = file_get_contents(USER_FILE);
    if(!$license_user) $license_error = "Invalid user license file!";
}
else if(file_exists('backend/editor.pro.control.php'))
{
    $license_error = "Missing license files!";
}
else
{
    $license_error = "Opensource Edition";
}
