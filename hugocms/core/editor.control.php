<?php
require 'editor.restricted.php';
require 'editor.config.php';
require 'editor.functions.php';

header('Content-Type: application/json');

$data   = varExist( $_GET, 'data' ) ? $_GET['data'] : varExist( $_POST, 'data' );

$licence = "";
if(file_exists(LICENSE_FILE))
{
    $license = file_get_contents(LICENSE_FILE);
    if(!$license) die( editor\resultInfo(false, "Invalid license file!") );
}
if(empty($license) || !password_verify("cmTvVBCfhEkq8s96", $license))
{
    if(strcmp("editor\\restore", $action) == 0) die( editor\resultInfo(false, "Invalid license key, you need a upgrade!") );
    if(strcmp("editor\\versioning", $action) == 0) die( editor\resultInfo(false, "Invalid license key, you need a upgrade!") );
    if(strcmp("editor\\purgecss", $action) == 0) die( editor\resultInfo(false, "Invalid license key, you need a upgrade!") );
    
    require 'editor.os.commands.php';
}
else
{
    require 'editor.pro.commands.php';
}

( $action and function_exists( $action ) ) or die( editor\resultInfo(false, "Param action or function `'.$action.'` not defined!") );
if( $data ) $action( $data ); else $action();
