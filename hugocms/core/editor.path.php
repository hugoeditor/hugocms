<?php
function resolvePath()
{
	if(isset($_POST['mode']) && !empty($_POST['mode']) && isset($_POST['file']) && !empty($_POST['file']))
	{
		if(false !== strpos($_POST['file'], '..'))
		{
			return false;
		}

		$basepath = realpath(PROJECT_DIR);
        $file = $_POST['file'];

		if('easy' == $_POST['mode'])
		{
			$tmp_path = explode('/', $file);
			if('css' == $tmp_path[0] || 'js' == $tmp_path[0] || 'images' == $tmp_path[0]) array_unshift($tmp_path, STATIC_CONTENT_DIR);
			$file = implode('/', $tmp_path);
		}
		elseif('admin' == $_POST['mode'])
		{
			$tmp_path = explode('/', $basepath);
			array_pop($tmp_path);
			$basepath = implode('/', $tmp_path);
		}

		return $basepath.'/'.$file;
	}

	return false;
}
