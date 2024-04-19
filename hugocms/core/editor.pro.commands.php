<?php
namespace editor;

define("GIT_COMMAND", "cd ".PROJECT_DIR." && git add --all && git commit -m ");
define("GIT_PUSH_COMMAND", " && git push".STDERR_REDIRECT);
define("GIT_RESET_COMMAND", "cd ".PROJECT_DIR." && git reset --hard".STDERR_REDIRECT);
define("PURGECSS_COMMAND", "cd ".ROOT_DIR." && purgecss/nodejs/bin/node purgecss/node_modules/purgecss/bin/purgecss.js --config purgecss/purgecss.config.js -con ".PROJECT_DIR."/public/index.html ".PROJECT_DIR."/public/contact/index.html -css ".PROJECT_DIR."themes/inter-data/static/css/bootstrap/bootstrap.min.css -o ".PROJECT_DIR."themes/inter-data/static/css/minimized.css".STDERR_REDIRECT);

function versioning($data)
{
    $msg = "generated commit by HugoCMS Editor";
    if(isset($data['commsg'])) $msg = $data['commsg'];
    execute(GIT_COMMAND.'"'.$msg.'"'.GIT_PUSH_COMMAND);
}

function restore()
{
    execute(GIT_RESET_COMMAND);
}

function purgecss()
{
    execute(PURGECSS_COMMAND);
}