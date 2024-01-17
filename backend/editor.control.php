<?php
require 'editor.inc.php';
require 'editor.restricted.php';
require 'editor.config.php';
require 'editor.functions.php';

header('Content-Type: application/json');

if(file_exists('editor.pro.control.php') && file_exists("editor.pro.commands.php"))
{
    require 'editor.pro.commands.php';
    require 'editor.pro.control.php';
}
else
{
    require 'editor.os.commands.php';
    require 'editor.os.control.php';
}
