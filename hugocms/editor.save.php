<?php
require 'editor.config.php';

if(isset($_POST['file']) && !empty($_POST['file']) && isset($_POST['text']) && !empty($_POST['text']))
{
	$basepath = realpath(ROOT_DIR);
	$file = $_POST['file'];

	if(!is_writeable($basepath.$file))
	{
		echo '{ "success":false, "debug":"Die Datei ist schreibgeschützt!" }';
		return;
	}

	if(file_put_contents($basepath.$file, $_POST['text']))
	{
		echo '{ "success":true }';
		return;
	}
	echo '{ "success":false }';
	return;
}

header("HTTP/1.0 418 I'm a teapot");	

