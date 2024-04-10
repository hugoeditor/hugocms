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

$license_error = "Community Edition";
$license_user = "";

if(file_exists(LICENSE_FILE))
{
    if(file_exists(USER_FILE))
    {
        $license_user = file_get_contents(USER_FILE);
        if(!$license_user) $license_user = "";
    }
    $licence = "";
    if(file_exists(LICENSE_FILE))
    {
        $license = file_get_contents(LICENSE_FILE);
        if(!$license) die( editor\resultInfo(false, "Invalid license file!") );
    }
    if(!empty($license) && password_verify("cmTvVBCfhEkq8s96", $license))
    {
        $license_error = "";
    }
}
