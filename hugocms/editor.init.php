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

if(file_exists('hugocms/editor.pro.control.php') && file_exists('hugocms/hugocms.user') && file_exists('hugocms/hugocms.license'))
{
	$license_user = file_get_contents('./hugocms/hugocms.user');
	if(!$license_user) $license_error = "Invalid user license file!";
}
else if(file_exists('hugocms/editor.pro.control.php'))
{
	$license_error = "Missing license files!";
}
else
{
	$license_error = "Opensource Edition";
}

