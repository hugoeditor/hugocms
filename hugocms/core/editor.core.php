<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
/*
Note: Routing also runs for Ajax calls via index.php,
which is located in the folder '_default_project/static/edit' and must be published with hugo.
The resource files must be linked in the 'public/edit' folder (see publish.sh script)
*/
require 'editor.inc.php';

$action = varExist( $_GET, 'action' ) ? $_GET['action'] : varExist( $_POST, 'action' );

$setup_ready = false;

if($action)
{
    if(strcmp($action, "elfinder") === 0) require 'editor.connector.php';
    elseif(strcmp($action, "template") === 0) require 'editor.md.template.php';
    elseif(strcmp($action, "load") === 0) require 'editor.load.php';
    elseif(strcmp($action, "save") === 0) require 'editor.save.php';
    elseif(strcmp($action, "setup_ready") === 0)
    {
        $setup_ready = true;
        opcache_reset();
        
        require 'editor.config.php';
        require 'editor.init.php';
        require 'editor.auth.php';
        require 'editor.view.php';
    }
    else require 'editor.control.php';
}
else
{
    require 'editor.config.php';
    require 'editor.init.php';
    require 'editor.auth.php';
    require 'editor.view.php';
}
