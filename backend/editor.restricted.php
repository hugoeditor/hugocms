<?php
session_start();

if(!isset($_SESSION['hugocms_client']) && !isset($_SESSION['hugocms_login']) && is_readable('editor.setup.php'))
{
    header('Content-Type: application/json');
    echo '{ "success": false, "session_expired": true, "error": "errLogout" }';
    exit;
}

$client = '';
if(isset($_POST['client'])) $client = $_POST['client'];
else if(isset($_GET['client'])) $client = $_GET['client'];

if(!hash_equals($_SESSION['hugocms_client'], $client))
{
    header('Content-Type: application/json');
    echo '{ "success": false, "invalid_session": true, "error": "errLogout" }';
    exit;
}