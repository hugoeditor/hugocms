<?php
/*
Note: The superglobals EDITOR_DIR and PROJECT_DIR are defined in index.php.
Routing also runs for Ajax calls via index.php,
which is located in the folder '_default_project/static/edit' and must be published with hugo.
The resource files must be linked in the 'public/edit' folder (see publish.sh script)
*/
if( !defined("PROJECT_DIR") ) die();
define("ROOT_DIR", __DIR__."/../../");
define("STATIC_CONTENT_DIR", "static");
define("GIT_DIR", ROOT_DIR.".git/");
define("PUBLIC_DIR", PROJECT_DIR."public/");
define("PREVIEW_DIR", PROJECT_DIR."public/edit/preview/");
define("TEMPLATE_DIR", "../template/");
define("SETUP_FILE", ROOT_DIR."config/editor.setup.php");
define("LICENSE_FILE", ROOT_DIR."config/hugocms.license");
define("USER_FILE", ROOT_DIR."config/hugocms.user");
define("CONFIG_FILE", PROJECT_DIR."config.json");
define("LICENSEE_FILE", ROOT_DIR."config/hugocms.user");
define("LICENSE_KEY_FILE", ROOT_DIR."config/hugocms.license");
