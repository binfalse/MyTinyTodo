<?php

if (php_sapi_name() != 'cli')
	die ("not allowed");

function err ($str, $mail)
{
	mail ($mail, 'ERROR adding todo item', $str);
	die ();
}

function deb ($str)
{
	file_put_contents ("/tmp/debug", $str."\n", FILE_APPEND );
}

function getList ($name, $db, $mail)
{
	$t = loadLists ($db, "");
	foreach ($t["list"] as $list)
	{
		if (strcasecmp($list["name"], $name) == 0)
			return $list["id"];
	}
	$newList = addList ($db, $name);
	if (!$newList['total'] || $newList['total'] < 1)
		err ("error creating new list.", $mail);
	return $newList['list'][0]['id'];
}

function findProp ($prop, &$array)
{
	if (preg_match ('/^\s*'.$prop.':(.+)$/mi', $array['body'], $match))
	{
		$array[$prop] = trim ($match[1]);
		$array['body'] = str_replace ($match[0], "", $array['body']);
	}
	else
		$array[$prop] = null;
}

function parseMail ($body, $db, $mail)
{
	$parsed = array ();
	
	$parsed['body'] = $body;
	
	findProp ('priority', $parsed);
	findProp ('tags', $parsed);
	findProp ('duedate', $parsed);
	findProp ('list', $parsed);
	if ($parsed['list'])
		$parsed['list'] = getList ($parsed['list'], $db, $mail);
	else
		$parsed['list'] = getList ("MailImport", $db, $mail);
	
	if (!$parsed['list'])
		err ("no list defined (neither in mail nor in config)", $mail);
	
	return $parsed;
}

define ("__API__", true);

if(!defined('MTTPATH'))
	define('MTTPATH', dirname(__FILE__) .'/');
require_once (MTTPATH.'init.php');

$db = DBConnection::instance();

define ("__SIG__", Config::get('signature'));
if (!__SIG__)
	die ("error: no signature");

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
			$parsed = parseMail ($Parser->getMessageBody('text'), $db, $Parser->getHeader('from'));
			if ($parsed['body'])
				$parsed['body'] = trim($parsed['body'])."\n\n";
			else
				$parsed['body'] = "";
			$parsed['body'] .= "Received ".date("F j, Y, g:i A")." via mail from: ".$Parser->getHeader('from');
			
			$t = addTask ($db, $parsed['list'], $Parser->getHeader('subject'), null, $parsed['body'], $parsed['priority'], $parsed['duedate'], $parsed['tags']);
			if (!$t['total'] || $t['total'] < 1)
				err ("error creating new task.", $Parser->getHeader('from'));
			break;
		default:
			die ("do not understand");
	}
}
else
	die ("nothing todo");


?>
