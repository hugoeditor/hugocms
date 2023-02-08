<?php
namespace editor;

define("STDERR_REDIRECT", " 2>&1");
define("PUBLISH_COMMAND", "hugo --cleanDestinationDir -DEF -s ".DIR_PREF." -d ".PUBLIC_DIR.STDERR_REDIRECT);
define("PREVIEW_COMMAND", "hugo --cleanDestinationDir -DEF -s ".DIR_PREF." -d ".PREVIEW_DIR.STDERR_REDIRECT);

const FILE_NO_ACCESS = 0;
const FILE_WRITE_PROTECTED = 1;
const FILE_FULL_ACCESS = 2;

function resultInfo($success, $text = '', $debug = false)
{
	$info = '{ "success":'.(($success)? 'true' : 'false');
	if(!empty($text)) if(!$success || $debug) $info .= ', "debug":"'.$text.'"';
	echo $info.' }';
}

function publish()
{
	execute(PUBLISH_COMMAND);
}

function preview()
{
	execute(PREVIEW_COMMAND);
}

function execute($command, $success_debug_info = false)
{
	exec($command, $output, $retv);
	$text = "";
	$output = str_replace('"', "'", $output);
	foreach($output as $line) if(!empty($line)) $text .= $line.' <br />';
	if($retv === 0) resultInfo(true);
	else resultInfo(false, $text);
}

function getFullPath()
{
	session_start();
	$basepath = realpath(ROOT_DIR)."/";
	$dir = "";
	if(isset($_SESSION["current_dir"])) $dir = $_SESSION["current_dir"];
	$folders = explode("/", $dir);
	$dir = "";
	foreach($folders as &$folder)
	{
		if(strcmp($folder, ".") !== 0 && strcmp($folder, "..") !== 0 && !empty($folder)) $dir .= $folder."/";
	}
	$fullpath = $basepath.$dir;
	echo '{ "fullpath":"'.$fullpath.'" }';
}

function newFileInfo($basepath, $file, $is_dir = false)
{
	$fileInfo = array();
	$fileInfo['name'] = $file;
	$fileInfo['dir'] = $is_dir;
	if(!is_readable($basepath.$file)) $fileInfo['permission'] = FILE_NO_ACCESS;
	elseif(!is_writable($basepath.$file)) $fileInfo = FILE_WRITE_PROTECTED;
	else $fileInfo['permission'] = FILE_FULL_ACCESS;
	return $fileInfo;
}

function scanCurrentDirectory($data)
{
	$basepath = realpath(ROOT_DIR)."/";
	if(is_array($data) && array_key_exists('path', $data)) $basepath .= $data['path'];
	$basepath = realpath($basepath).'/';
	$result = array();
	$scanDir = true;

	if($dh = opendir($basepath))
	{
		while (($file = readdir($dh)) !== false)
		{
			if(is_dir($basepath.$file) && strcmp($file, ".") !== 0 && strcmp($file, "..") !== 0 && strcmp($file, ".git") !== 0) $result[] = newFileInfo($basepath, $file, true);
		}
		rewinddir($dh);
		while (($file = readdir($dh)) !== false)
		{
			if(is_file($basepath.$file)) $result[] = newFileInfo($basepath, $file);
		}
		closedir($dh);
	}
	echo json_encode($result);
}

function makeDirectory($data)
{
	$dirname = $data['dirname'];
	$path = realpath(ROOT_DIR).$dirname;
	if(is_dir($path))
	{
		resultInfo(false, 'Das Verzeichnis `'.$dirname.'` existiert bereits!', true);
		return;
	}
	if(mkdir($path))
	{
		resultInfo(true);
		return;
	}
	resultInfo(false);
}

function newFile($data)
{
	$filename = $data['filename'];
	$path = realpath(ROOT_DIR).$filename;
	if(file_exists($path))
	{
		resultInfo(false, 'Die Datei `'.$filename.'` existiert bereits!', true);
		return;
	}
	if(is_readable('template.md'))
	{
		if(copy('template.md', $path))
		{
			resultInfo(true);
			return;
		}
	}
	elseif($fd = fopen($path, "w"))
	{
		fclose($fd);
		resultInfo(true);
		return;
	}
	resultInfo(false);
}

function removeTarget($data)
{
	$target = $data['target'];
	$path = realpath(ROOT_DIR).$target;
	if(is_file($path))
	{
		if(unlink($path)) resultInfo(true);
		else resultInfo(false, 'Die Datei `'.$target.'` kann nicht gelöscht werden!', true);
		return;
	}
	if(is_dir($path))
	{
		if(rmdir($path)) resultInfo(true);
		else resultInfo(false, 'Das Verzeichnis `'.$target.'` kann nicht gelöscht werden! Ist es leer?', true);
		return;
	}
	resultInfo(false, 'Die Datei oder das Verzeichnis `'.$target.'` existiert nicht!', true);
}

function renameTarget($data)
{
	$target = realpath(ROOT_DIR).$data['target'];
	$filename = realpath(ROOT_DIR).$data['filename'];
	if(is_file($target) && substr($target, -strlen('.md')) === '.md' && substr($filename, -strlen('.md')) !== '.md') $filename .= '.md';
	if(is_file($filename))
	{
		resultInfo(false, 'Die Datei existiert bereits!');
		return;
	}
	if(is_dir($filename))
	{
		resultInfo(false, 'Das Verzeichnis existiert bereits!');
		return;
	}
	if(rename($target, $filename))
	{
		resultInfo(true);
		return;
	}
	resultInfo(false);
}

function setLang($data)
{
	session_start();
	$_SESSION['lang'] = $data['lang'];
	resultInfo(true);
}

function getLang()
{
	session_start();
	$lang = (isset($_SESSION['lang']))? $_SESSION['lang'] : "en";
	echo '{ "lang": "'.$lang.'" }';
}

