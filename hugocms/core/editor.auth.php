<?php
$setup = false;
$setup_no_cancel = false;
$error_msg = '';

function showMessage($style, $msg)
{
    global $error_msg;
    $error_msg = '<p class="bg-'.$style.'" id="message" style="padding: 1em" id="empty-password">'.$msg.'</p>';
}

if(!is_readable(SETUP_FILE))
{
    $setup = true;
    $setup_no_cancel = true;
    $_SESSION['hugocms_client'] = bin2hex(random_bytes(16));

    $licensee = '';
    if(file_exists(LICENSEE_FILE)) $licensee = file_get_contents(LICENSEE_FILE);
    if(!$licensee) $licensee = '';

    $licenseKey = '';
    if(file_exists(LICENSE_KEY_FILE)) $licenseKey = file_get_contents(LICENSE_KEY_FILE);
    if(!$licenseKey) $licenseKey = '';
}
elseif(!isset($_SESSION['hugocms_login']))
{
    $login_data = array();
    require SETUP_FILE;

    if(!isset($_POST['username']) || !isset($_POST['password']))
    {
        require 'editor.login.php';
        exit;
    }

    foreach($login_data as $login_entry)
    {
        if(0 == strcmp($_POST['username'], $login_entry['username']) && password_verify($_POST['password'], $login_entry['password']))
        {
            $_SESSION['hugocms_login'] = $login_entry['username'];
            break;
        }
    }

    if(!isset($_SESSION['hugocms_login']))
    {
        global $error_msg;
        $error_msg = "Invalid login data!";
        require 'editor.login.php';
        exit;
    }

    if(!isset($_SESSION['hugocms_client'])) $_SESSION['hugocms_client'] = bin2hex(random_bytes(16));
}
