<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

$messagesJSON = file_get_contents('messages.json');
$messages = array();
if(false !== $messagesJSON) $messages = json_decode($messagesJSON, true);
function getMessage($message)
{
    global $messages;
    return (is_array($messages) && array_key_exists($message, $messages))? $messages[$message] : $message;
}

$configJSON = file_get_contents('mailapi.json');
if(false === $configJSON)
{
    echo getMessage('ERROR_READING_CONFIG');
    exit;
}

$config = json_decode($configJSON, true);
if(!is_array($config))
{
    echo getMessage('ERROR_READING_CONFIG');
    exit;
}
function getConfig($key)
{
    global $config;
    if(!array_key_exists($key, $config))
    {
        echo getMessage('ERROR_CONFIG_PARAMETER_MISSING')." ($key)";
        exit;
    }
    return $config[$key];
}

if(isset($_POST['message']) && !empty($_POST['message']))
{
	$message = $_POST['message'];

	$mailer = new PHPMailer(true);
	try
	{
		$mailer->isSMTP();
		$mailer->Host = getConfig('host');
		$mailer->SMTPAuth = getConfig('auth');
		$mailer->SMTPAutoTLS = getConfig('autoTLS'); 
		$mailer->Port = getConfig('port');
		if(array_key_exists('debug', $config)) $mailer->SMTPDebug = $config['debug']; // Enable verbose debug output
		if(array_key_exists('username', $config)) $mailer->Username = $config['username']; // SMTP username
		if(array_key_exists('password', $config)) $mailer->Password = $config['password']; // SMTP password
		if(array_key_exists('secure', $config)) $mailer->SMTPSecure = $config['secure']; // Enable TLS encryption, `ssl` also accepted
        $email = '';
    	if(array_key_exists('from', $config)) $email = $config['from'];
		if(isset($_POST['email']) && !empty($_POST['email'])) $email = $_POST['email'];

		$mailer->setFrom($email);
		$mailer->addAddress(getConfig('to'));

		$mailer->CharSet = 'UTF-8';

		$mailer->Subject = getMessage('SUBJECT');
		$mailer->Body = $message;
		if(isset($_FILES['userfile']))
		{
			if(strcmp($_FILES['userfile']['type'], 'application/pdf') == 0)
			{
				$mailer->addAttachment($_FILES['userfile']['tmp_name'], $_FILES['userfile']['name']);
			}
			else
			{
				echo getMessage('ERROR_WRONG_MIMETYPE');
				return;
			}
		}

		$mailer->send();

		echo getMessage('SUCCESS');
	}
	catch(Exception $e)
	{
		echo getMessage('ERROR_SENT_MAIL').$mailer->ErrorInfo;
	}
	return;
}

echo getMessage('ERROR_MALFORMED_MAIL');

