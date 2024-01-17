<?php
require 'editor.inc.php';
require 'editor.config.php';
require 'editor.restricted.php';
require 'editor.path.php';

$full_filename = resolvePath();
if(false !== $full_filename && ($text = file_get_contents($full_filename)) !== false)
{
    echo $text;
    return;
}

header("HTTP/1.0 418 I'm a teapot");
