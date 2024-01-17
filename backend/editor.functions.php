<?php
namespace editor;

define("STDERR_REDIRECT", " 2>&1");
define("PUBLISH_COMMAND", ROOT_DIR."hugo/hugo --cleanDestinationDir -DEF -s ".ROOT_DIR." -d ".PUBLIC_DIR.STDERR_REDIRECT);
define("PREVIEW_COMMAND", ROOT_DIR."hugo/hugo --cleanDestinationDir -DEF -s ".ROOT_DIR." -d ".PREVIEW_DIR.STDERR_REDIRECT);

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

function setLang($data)
{
    $_SESSION['lang'] = $data['lang'];
    resultInfo(true);
}

function getSetup()
{
    $lang = 'en';
    $mode = 'easy';
    $login_data = array();
    require SETUP_FILE;
    foreach($login_data as $login_entry)
    {
        if(0 == strcmp($_SESSION['hugocms_login'], $login_entry['username']))
        {
            $lang = $login_entry['lang'];
            $mode = $login_entry['mode'];
            break;
        }
    }
    echo '{ "lang": "'.$lang.'", "mode": "'.$mode.'", "login": "'.$_SESSION['hugocms_login'].'" }';
}

function logout()
{
    $_SESSION['hugocms_login'] = null;
    session_destroy();
    resultInfo(true);
}

function writeUserSetup($data)
{
    if(isset($data['username']) || isset($data['password']))
    {
        if(file_exists(SETUP_FILE) && !isset($_SESSION['hugocms_login']))
        {
            resultInfo(false, 'Cannot overwrite login data');
            return;
        }
    }

    $index = null;
    $login_data = array();
    if(file_exists(SETUP_FILE) && isset($_SESSION['hugocms_login']))
    {
        require SETUP_FILE;
        for($i = 0; count($login_data) > $i; $i++)
        {
            if(0 == strcmp($_SESSION['hugocms_login'], $login_data[$i]['username']))
            {
                $index = $i;
                break;
            }
        }
    }

    if($index === null)
    {
        array_push( $login_data, array() );
        $index = count($login_data) - 1;
    }

    if(validVar($data['username']))
    {
        $login_data[$index]['username'] = $data['username'];
        $_SESSION['hugocms_login'] = $data['username'];
    }
    if(validVar($data['password'])) $login_data[$index]['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
    if(validVar($data['lang'])) $login_data[$index]['lang'] = $data['lang'];
    if(validVar($data['mode'])) $login_data[$index]['mode'] = $data['mode'];

    if(!validVar($login_data[$index]['username']) || !validVar($login_data[$index]['password']) || !validVar($login_data[$index]['lang']) || !validVar($login_data[$index]['mode']))
    {
        resultInfo(false, 'Current setup data is incomplete');
        return;
    }

    $setup_file = "<?php\n";
    foreach($login_data as &$login_entry)
    {
        $setup_line = "array_push(\$login_data, array('username' => '".$login_entry['username']."', 'password' => '".$login_entry['password']."', 'lang' => '".$login_entry['lang']."', 'mode' => '".$login_entry['mode']."'));";
        $setup_file .= $setup_line."\n";
    }
    if(!file_put_contents(SETUP_FILE, $setup_file))
    {
        resultInfo(false, 'Cannot save login data');
        return;
    }
    resultInfo(true);
}

function writeMdTemplate($data)
{
    $template = $data['template'];
    if(!file_put_contents(TEMPLATE_DIR."editor.template.php", $template))
    {
        resultInfo(false, 'Cannot save template');
        return;
    }
    resultInfo(true);
}