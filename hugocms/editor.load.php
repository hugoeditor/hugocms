<?php
require 'editor.config.php';

if(isset($_POST['file']) && !empty($_POST['file']))
{
	$basepath = realpath(ROOT_DIR);
	$file = $_POST['file'];

	if(($text = file_get_contents($basepath.$file)) !== false)
	{
		echo $text;
		return;
	}
}

header("HTTP/1.0 418 I'm a teapot");	

