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
	
	if ($parsed['duedate'])
		$parsed['duedate'] = pasreDate ($parsed['duedate']);
	
	if (!$parsed['list'])
		err ("no list defined (neither in mail nor in config)", $mail);
	
	return $parsed;
}

function pasreDate ($date)
{
	switch ($date)
	{
		case "today":
			return date ("Y-m-d");
			break;
		case "tomorrow":
			return date ("Y-m-d", time () + 60*60*24);
			break;
		case "nextweek":
			return date ("Y-m-d", time () + 60*60*24*7);
			break;
		case "nextmonth":
			return date ("Y-m-d", time () + 60*60*24*30);
			break;
		case "yesterday":
			return date ("Y-m-d", time () - 60*60*24);
			break;
		case "lastmonth":
			return date ("Y-m-d", time () - 60*60*24*30);
			break;
		case "lastweek":
			return date ("Y-m-d", time () - 60*60*24*7);
			break;
	}
	return $date;
}

function help ($str = null)
{
	if ($str)
		echo $str."\n\n";
	echo "USAGE:
	CALL	ARGUMENTS	IMPACT
	getduetasks
		--from DATE	set min date (format: YYYY-MM-DD; default: today)
		--till DATE	set max date (format: YYYY-MM-DD; default: today + 14 days)
		--minprio INT	set minimum priority of the shown tasks (default: -1 -> no minimum)
		--list LISTNAME	show only tasks from todolist LIST
		--mail ADDRESS	send digest to ADDRESS
";
	die ("try again\n");
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
		case "getduetasks":
			
			$where = array ();
			$start = date ("Y-m-d");
			$end = date ("Y-m-d", time () + 60*60*24*14); # now + 14 days
			$minprio = -1;
			$list = null;
			$mail = null;
			
			for ($i = 2; $i < count ($argv); $i += 2)
			{
				if ($i < count ($argv) - 1)
				{
					switch ($argv[$i])
					{
						case "--from":
							$date = pasreDate ($argv[$i + 1]);
							if (!preg_match ('/\d{4}-\d{2}-\d{2}/', $date))
								help ("invalid start date: ".$date);
							$start = $date;
							break;
						case "--till":
							$date = pasreDate ($argv[$i + 1]);
							if (!preg_match ('/\d{4}-\d{2}-\d{2}/', $date))
								help ("invalid end date: ".$date);
							$end = $date;
							break;
						case "--minprio":
							$minprio = (int) $argv[$i + 1];
							if ($minprio > 2)
								$minprio = 2;
							break;
						case "--list":
							$list = $argv[$i + 1]; # do not use in sql statement. further checks needed.
							break;
						case "--mail":
							$mail = $argv[$i + 1];
							break;
						default:
							help ("do not understand: ".$argv[$i]);
					}
				}
				else
				{
					switch ($argv[$i])
					{
						case "--help":
						case "-h":
							help ();
							break;
						default:
							help ("do not understand: ".$argv[$i]);
					}
				}
			}
			
			if ($start)
				$where[] = "{$db->prefix}todolist.duedate >= '$start'";
			if ($end)
				$where[] = "{$db->prefix}todolist.duedate <= '$end'";
			if ($minprio > -1)
				$where[] = "{$db->prefix}todolist.prio >= '$minprio'";
			
			if (count ($where))
				$where = "AND ".implode (" AND ", $where);
			else
				$where = "";
			
			$stmnt = "SELECT {$db->prefix}lists.name, {$db->prefix}todolist.title, {$db->prefix}todolist.duedate, {$db->prefix}todolist.prio FROM {$db->prefix}lists LEFT JOIN {$db->prefix}todolist ON {$db->prefix}lists.id={$db->prefix}todolist.list_id WHERE {$db->prefix}todolist.compl=0 $where ORDER BY {$db->prefix}todolist.duedate DESC, {$db->prefix}todolist.prio DESC";
			
			$today =  date ("Y-m-d");
			$tomorrow = date ("Y-m-d", time () + 60*60*24);
			$nextweek = date ("Y-m-d", time () + 60*60*24*7);
			$nextmonth = date ("Y-m-d", time () + 60*60*24*30);
			$yesterday = date ("Y-m-d", time () - 60*60*24);
			$lastmonth = date ("Y-m-d", time () - 60*60*24*30);
			$lastweek = date ("Y-m-d", time () - 60*60*24*7);
			$pre = date ("Y-m-d", time () - 60*60*24*31);
			$post = "2030-12-31"; # we'll propably have an alternative in 2029 ;-)
			
			$buckets = array ($pre, $lastmonth, $lastweek, $yesterday, $today, $tomorrow, $nextweek, $nextmonth, $post);
			$res = array ();
			
			$q = $db->dq($stmnt);
			while($r = $q->fetch_assoc($q))
			{
				if ($list && $r['name'] != $list)
					continue;
				
				$d = $r['duedate'];
				
				if ($d < $lastmonth)
					$res[$pre][] = $r;
				
				else if ($d < $lastweek)
					$res[$lastmonth][] = $r;
				
				else if ($d < $yesterday)
					$res[$lastweek][] = $r;
				
				else if ($d < $today)
					$res[$yesterday][] = $r;
				
				else if ($d == $today)
					$res[$today][] = $r;
				
				else if ($d > $nextmonth)
					$res[$post][] = $r;
				
				else if ($d > $nextweek)
					$res[$nextmonth][] = $r;
				
				else if ($d > $tomorrow)
					$res[$nextweek][] = $r;
				
				else 
					$res[$tomorrow][] = $r;
			}
			
			$msg = "";
			
			foreach ($buckets as $key)
			{
				if (isset ($res[$key]) && count ($res[$key]))
				{
					switch ($key)
					{
						case $pre:
							$msg .= "*PAST*\n";
							break;
						case $lastmonth:
							$msg .= "*Past MONTH*\n";
							break;
						case $lastweek:
							$msg .= "*Past WEEK*\n";
							break;
						case $yesterday:
							$msg .= "*YESTERDAY*\n";
							break;
						case $today:
							$msg .= "*TODAY*\n";
							break;
						case $tomorrow:
							$msg .= "*TOMORROW*\n";
							break;
						case $nextweek:
							$msg .= "*Upcoming WEEK*\n";
							break;
						case $nextmonth:
							$msg .= "*Upcoming MONTH*\n";
							break;
						case $post:
							$msg .= "*FUTURE*\n";
							break;
					}
					
					foreach ($res[$key] as $r)
					{
						$d = strtotime($r['duedate']);
						$msg .= ">".$r['title']." in [".$r['name']."] due to ".date ("D, j M Y", $d)."";
						if ($r['prio'] > 0)
							$msg .= " [+".$r['prio']."]";
						if ($r['prio'] < 0)
							$msg .= " [".$r['prio']."]";
						$msg .= "\n";
					}
					$msg .= "\n\n";
					
				}
			}
			
			if ($msg)
			{
				$msg = "Tasks due between $start and $end:\n\n".$msg;
				if ($mail)
				{
					$header = "From: MyTinyTodo <noreply@localhost>\r\n";
					mail ($mail, 'Your Open Tasks Digest', $msg, $header);
				}
				else
					echo $msg;
			}
			
			break;
		default:
			help ("do not understand: ".$argv[1]);
	}
}
else
	help ("no arguments. nothing todo.");


?>
