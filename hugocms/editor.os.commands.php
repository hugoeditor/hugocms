<?php
namespace editor;

define("GIT_COMMAND", 'echo "Invalid license key, you need a upgrade!"; exit 1');
define("GIT_RESET_COMMAND", 'echo "Invalid license key, you need a upgrade!"; exit 1');

function versioning($data)
{
	execute(GIT_COMMAND);
}

function restore()
{
	execute(GIT_RESET_COMMAND);
}

