<?php

if (php_sapi_name() != 'cli')
	die ("not allowed");

function deb ($str)
{
	file_put_contents ("/tmp/debug", $str."\n", FILE_APPEND );
}

if(!defined('MTTPATH'))
	define('MTTPATH', dirname(__FILE__) .'/');
require_once (MTTPATH.'db/config.php');

$_GET['API'] = true;

$_GET['signature'] = $config['signature'];
if (!$_GET['signature'])
	die ("error: no signature");

if (isset ($config['defaultlist']))
	$_POST['list'] = $config['defaultlist'];
else
	$_POST['list'] = 1;

function get_include_contents($filename) {
	if (is_file(MTTPATH.$filename))
	{
		ob_start();
		require_once (MTTPATH.$filename);
		return ob_get_clean();
  }
  return false;
}

if (isset ($argv[1]))
{
	switch ($argv[1])
	{
		case "importmail":
			##########################
			# pecl install mailparse #
			##########################
			require_once (MTTPATH."third-party/MimeMailParser.class.php");
			$Parser = new MimeMailParser();
			$Parser->setStream(STDIN);
			$_GET['fullNewTask'] = 1;
			$_POST['title'] = $Parser->getHeader('subject');
			$_POST['note'] = $Parser->getMessageBody('text');
			$json = get_include_contents('ajax.php');
			/*deb(var_export ($json, true));
			deb("t: " . $json);*/
			break;
		default:
			die ("do not understand");
	}
}
else
	die ("nothing todo");


?>