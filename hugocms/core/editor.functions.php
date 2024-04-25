<?php
namespace editor;

define("STDERR_REDIRECT", " 2>&1");
define("PUBLISH_COMMAND", "err=$(".ROOT_DIR."hugo/hugo --cleanDestinationDir -DEF -s ".PROJECT_DIR." -d ".PUBLIC_DIR.STDERR_REDIRECT."); ret=$?; cd ".EDITOR_DIR." && ln -s ".PUBLIC_DIR."../../hugocms hugocms > /dev/null".'; echo $err; exit $ret');
define("PREVIEW_COMMAND", ROOT_DIR."hugo/hugo --cleanDestinationDir -DEF -s ".PROJECT_DIR." -d ".PREVIEW_DIR.STDERR_REDIRECT);

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

function execute($command, $silent_success = false)
{
    exec($command, $output, $retv);
    $text = "";
    $output = str_replace('"', "'", $output);
    foreach($output as $line) if(!empty($line)) $text .= $line.' <br />';
    if($retv === 0)
    {
        if(!$silent_success) resultInfo(true);
        return true;
    }
    else
    {
        resultInfo(false, $text);
        return false;
    }
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
    $purgecss = 'false';
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

        // Prüft, ob die Konfigurationsdatei existiert
        if(!file_exists(CONFIG_FILE))
        {
            resultInfo(false, "The configuration file file does not exist.");
            return;
        }
    
        // Liest den Inhalt der Datei
        $content = file_get_contents(CONFIG_FILE);
        // Wandelt den JSON-Inhalt in ein PHP-Array um
        $config = json_decode($content, true);
    
        // Prüft, ob der Schlüssel 'params' existiert und ein Array ist
        if(isset($config['params']) && is_array($config['params']))
        {
            // Setzt den Wert von 'minimizeCSS' auf den übergebenen Wert
            if(array_key_exists('minimizedCSS', $config['params'])) $purgecss = $config['params']['minimizedCSS'];
        }
        else
        {
            resultInfo(false, "The configuration file is in the wrong format.");
            return;
        }
    
    echo '{ "lang": "'.$lang.'", "mode": "'.$mode.'", "purgecss":"'.$purgecss.'", "login": "'.$_SESSION['hugocms_login'].'" }';
}

function getLicense()
{
    if(!file_exists(LICENSEE_FILE) || !file_exists(LICENSE_KEY_FILE))
    {
        resultInfo(false, 'License file not found!');
        return;
    }
    $licensee = file_get_contents(LICENSEE_FILE);
    $licenseKey = file_get_contents(LICENSE_KEY_FILE);
    echo '{ "licensee": "'.$licensee.'", "licenseKey": "'.$licenseKey.'" }';
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
            resultInfo(false, 'Cannot overwrite login data!');
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

    if(isset($data['username']) && !empty($data['username']))
    {
        $login_data[$index]['username'] = $data['username'];
        $_SESSION['hugocms_login'] = $data['username'];
    }
    if(isset($data['password']) && !empty($data['password'])) $login_data[$index]['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
    if(isset($data['lang']) && !empty(($data['lang']))) $login_data[$index]['lang'] = $data['lang'];
    if(isset($data['mode']) && !empty($data['mode'])) $login_data[$index]['mode'] = $data['mode'];
    if(isset($data['purgecss']) && !empty($data['purgecss'])) $login_data[$index]['purgecss'] = $data['purgecss'];

    if(!isset($login_data[$index]['username']) && empty($login_data[$index]['username'])
		 || !isset($login_data[$index]['password']) && empty($login_data[$index]['password'])
		 || !isset($login_data[$index]['lang']) && empty($login_data[$index]['lang'])
		 || !isset($login_data[$index]['mode']) && empty($login_data[$index]['mode']))
    {
        resultInfo(false, 'Current setup data is incomplete');
        return;
    }

    if(isset($data['project_name']) && !empty($data['project_name'])) createProject($data['project_name'], $data['username'], true);

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

    if(isset($data['purgecss']))
    {
        // Prüft, ob die Konfigurationsdatei existiert
        if(!file_exists(CONFIG_FILE))
        {
            resultInfo(false, "The configuration file file does not exist.");
            return;
        }

        // Liest den Inhalt der Datei
        $content = file_get_contents(CONFIG_FILE);
        // Wandelt den JSON-Inhalt in ein PHP-Array um
        $config = json_decode($content, true);

        // Prüft, ob der Schlüssel 'params' existiert und ein Array ist
        if(isset($config['params']) && is_array($config['params']))
        {
            // Setzt den Wert von 'minimizeCSS' auf den übergebenen Wert
            $config['params']['minimizedCSS'] = (strcmp($data['purgecss'], 'true') === 0) ? true : false;
        }
        else
        {
            resultInfo(false, "The configuration file is in the wrong format.");
            return;
        }

        // Wandelt das PHP-Array zurück in einen JSON-String
        $newContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if($newContent === false)
        {
            resultInfo(false, "Error converting configuration to JSON.");
            return;
        }

        // Schreibt den neuen JSON-Inhalt zurück in die Datei
        if(file_put_contents(CONFIG_FILE, $newContent) === false)
        {
            resultInfo(false, "Error writing updated configuration to file.");
            return;
        }
    }
    
    //Write the new license data, if an error occurs, the user can be canceled
    if(isset($data['licenseKey']) && !empty($data['licenseKey']))
    {
        if(!file_put_contents(LICENSE_KEY_FILE, $data['licenseKey']))
        {
            resultInfo(false, 'Cannot save license key!');
            return;
        }
    }
    if(isset($data['licensee']) && !empty($data['licensee']))
    {
        if(!file_put_contents(LICENSEE_FILE, $data['licensee']))
        {
            resultInfo(false, 'Cannot save licensee data!');
            return;
        }
    }
    resultInfo(true);
}

function writeMdTemplate($data)
{
    $template = $data['template'];
    if(!file_put_contents(TEMPLATE_DIR."editor.template.php", $template))
    {
        resultInfo(false, 'Cannot save template!');
        return;
    }
    resultInfo(true);
}

function createProject($project_name, $username, $setup)
{
    $project_dir = __DIR__."/../../".$project_name;
    if(file_exists($project_dir))
    {
        resultInfo(false, 'Project directory already exists!');
        die();
    }
    $source_dir = __DIR__."/../../_default_project";
    $command = "cp -r $source_dir $project_dir";
    if($setup) $command .= " && rm ".__DIR__."/../../public && ln -s $project_dir/public ".__DIR__."/../../public";
    $command .= " && cd $project_dir && git init";
    $command .= ' && git config user.email "'.$username.'@'.$project_name.'"';
    $command .= ' && git config user.name "'.$username.'"';
    execute($command, $setup);
}

function newProject($data)
{
    if(!isset($data['project_name']) || empty($data['project_name']))
    {
        resultInfo(false, 'Project name is missing!');
        return;
    }
    if(!isset($data['username']) || empty($data['username']))
    {
        resultInfo(false, 'Username is missing!');
        return;
    }   
    createProject($data['project_name'], $data['username'], false);
}