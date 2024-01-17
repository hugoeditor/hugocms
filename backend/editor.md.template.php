<?php
require 'editor.inc.php';
require 'editor.config.php';
require 'editor.restricted.php';

if(!file_exists(TEMPLATE_DIR."template.md")) $template = file_get_contents("template.md");
else $template = file_get_contents(TEMPLATE_DIR."template.md");
if(false === $template) $template = "";
echo $template;
