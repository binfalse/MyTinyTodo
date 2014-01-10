<?php
/*
	This file is part of myTinyTodo.
	(C) Copyright 2009-2010 Max Pozdeev <maxpozdeev@gmail.com>
	Licensed under the GNU GPL v3 license. See file COPYRIGHT for details.
*/

# check if it is installed correctly, otherwise open setup.php
if (!file_exists('./db/config.php'))
{
	$url = preg_replace('/index.php.*$/', "", $_SERVER["REQUEST_URI"]);
	if ($url[strlen ($url) - 1] != "/")
		$url .= "/";
	$url .= "setup.php";
	
	header('Location: '.$url);
	die ("Please install MyTinyTodo: $url");
}


require_once('./init.php');

$lang = Lang::instance();

if($lang->rtl()) Config::set('rtl', 1);

if(!is_int(Config::get('firstdayofweek')) || Config::get('firstdayofweek')<0 || Config::get('firstdayofweek')>6) Config::set('firstdayofweek', 1);

define('TEMPLATEPATH', MTTPATH. 'themes/'.Config::get('template').'/');

// extend cookie
if (isset ($_COOKIE["MTTAUTH"]))
	setcookie ("MTTAUTH", $_COOKIE["MTTAUTH"], time()+60*60*24*30*3);

if (is_logged () || Config::get('password') == '')
	require(TEMPLATEPATH. 'index.php');
else
	require(TEMPLATEPATH. 'login.php');
?>
