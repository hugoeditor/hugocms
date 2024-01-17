<?php
require 'editor.inc.php';
require 'editor.config.php';
require 'editor.restricted.php';
require 'editor.path.php';

$full_filename = resolvePath();
writeLog($full_filename);

if(false !== $full_filename && validVar($_POST['text']))
{
    if(!is_writeable($full_filename))
    {
        echo '{ "success":false, "debug":"Die Datei ist schreibgeschützt!" }';
        return;
    }

    if(file_put_contents($full_filename, $_POST['text']))
    {
        echo '{ "success":true }';
        return;
    }
}

echo '{ "success":false }';
