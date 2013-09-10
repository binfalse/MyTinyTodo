<?php


function prepareTaskRow($r, $lists)
{
	$lang = Lang::instance();
	$dueA = prepare_duedate($r['duedate']);
	$formatCreatedInline = $formatCompletedInline = Config::get('dateformatshort');
	if(date('Y') != date('Y',$r['d_created'])) $formatCreatedInline = Config::get('dateformat2');
	if($r['d_completed'] && date('Y') != date('Y',$r['d_completed'])) $formatCompletedInline = Config::get('dateformat2');

	$dCreated = timestampToDatetime($r['d_created']);
	$dCompleted = $r['d_completed'] ? timestampToDatetime($r['d_completed']) : '';

	return array(
		'id' => $r['id'],
		'title' => escapeTags($r['title']),
		'listId' => $r['list_id'],
		'listName' => $lists['list'][$r['list_id']]['name'],
		'date' => htmlarray($dCreated),
		'dateInt' => (int)$r['d_created'],
		'dateInline' => htmlarray(formatTime($formatCreatedInline, $r['d_created'])),
		'dateInlineTitle' => htmlarray(sprintf($lang->get('taskdate_inline_created'), $dCreated)),
		'dateEditedInt' => (int)$r['d_edited'],
		'dateCompleted' => htmlarray($dCompleted),
		'dateCompletedInline' => $r['d_completed'] ? htmlarray(formatTime($formatCompletedInline, $r['d_completed'])) : '',
		'dateCompletedInlineTitle' => htmlarray(sprintf($lang->get('taskdate_inline_completed'), $dCompleted)),
		'compl' => (int)$r['compl'],
		'prio' => $r['prio'],
		'note' => nl2br(escapeTags($r['note'])),
		'noteText' => (string)$r['note'],
		'ow' => (int)$r['ow'],
		'tags' => htmlarray($r['tags']),
		'tags_ids' => htmlarray($r['tags_ids']),
		'duedate' => $dueA['formatted'],
		'dueClass' => $dueA['class'],
		'dueStr' => htmlarray($r['compl'] && $dueA['timestamp'] ? formatTime($formatCompletedInline, $dueA['timestamp']) : $dueA['str']),
		'dueInt' => date2int($r['duedate']),
		'dueTitle' => htmlarray(sprintf($lang->get('taskdate_inline_duedate'), $dueA['formatted'])),
	);
}

function check_read_access($listId = null)
{
	$db = DBConnection::instance();
	if(Config::get('password') == '') return true;
	if(is_logged()) return true;
	if($listId !== null)
	{
		$id = $db->sq("SELECT id FROM {$db->prefix}lists WHERE id=? AND published=1", array($listId));
		if($id) return;
	}
	jsonExit( array('total'=>0, 'list'=>array(), 'denied'=>1) );
}

function have_write_access($listId = null)
{
	if(is_readonly()) return false;
	// check list exist
	if($listId !== null)
	{
		$db = DBConnection::instance();
		$count = $db->sq("SELECT COUNT(*) FROM {$db->prefix}lists WHERE id=?", array($listId));
		if(!$count) return false;
	}
	return true;
}

function check_write_access($listId = null)
{
	if(have_write_access($listId)) return;
	jsonExit( array('total'=>0, 'list'=>array(), 'denied'=>1) );
}

function inputTaskParams()
{
	$a = array(
		'id' => _post('id'),
		'title'=> trim(_post('title')),
		'note' => str_replace("\r\n", "\n", trim(_post('note'))),
		'prio' => (int)_post('prio'),
		'duedate' => '',
		'tags' => trim(_post('tags')),
		'listId' => (int)_post('list'),

	);
	if($a['prio'] < -1) $a['prio'] = -1;
	elseif($a['prio'] > 2) $a['prio'] = 2;
	return $a;
}

function prepareTags($tagsStr)
{
	$tags = explode(',', $tagsStr);
	if(!$tags) return 0;

	$aTags = array('tags'=>array(), 'ids'=>array());
	foreach($tags as $tag)
	{
		$tag = str_replace(array('"',"'",'<','>','&','/','\\','^'),'',trim($tag));
		if($tag == '') continue;

		$aTag = getOrCreateTag($tag);
		if($aTag && !in_array($aTag['id'], $aTags['ids'])) {
			$aTags['tags'][] = $aTag['name'];
			$aTags['ids'][] = $aTag['id'];
		}
	}
	return $aTags;
}

function getOrCreateTag($name)
{
	$db = DBConnection::instance();
	$tagId = $db->sq("SELECT id FROM {$db->prefix}tags WHERE name=?", array($name));
	if($tagId) return array('id'=>$tagId, 'name'=>$name);

	$db->ex("INSERT INTO {$db->prefix}tags (name) VALUES (?)", array($name));
	return array('id'=>$db->last_insert_id(), 'name'=>$name);
}

function getTagId($tag)
{
	$db = DBConnection::instance();
	$id = $db->sq("SELECT id FROM {$db->prefix}tags WHERE name=?", array($tag));
	return $id ? $id : 0;
}

function get_task_tags($id)
{
	$db = DBConnection::instance();
	$q = $db->dq("SELECT tag_id FROM {$db->prefix}tag2task WHERE task_id=?", $id);
	$a = array();
	while($r = $q->fetch_row()) {
		$a[] = $r[0];
	}
	return $a;
}


function addTaskTags($taskId, $tagIds, $listId)
{
	$db = DBConnection::instance();
	if(!$tagIds) return;
	foreach($tagIds as $tagId)
	{
		$db->ex("INSERT INTO {$db->prefix}tag2task (task_id,tag_id,list_id) VALUES (?,?,?)", array($taskId,$tagId,$listId));
	}
}

function parse_smartsyntax($title)
{
	$a = array();
	if(!preg_match("|^(/([+-]{0,1}\d+)?/)?(.*?)(\s+/([^/]*)/$)?$|", $title, $m)) return false;
	$a['prio'] = isset($m[2]) ? (int)$m[2] : 0;
	$a['title'] = isset($m[3]) ? trim($m[3]) : '';
	$a['tags'] = isset($m[5]) ? trim($m[5]) : '';
	if($a['prio'] < -1) $a['prio'] = -1;
	elseif($a['prio'] > 2) $a['prio'] = 2;
	return $a;
}

function tag_size($qmin, $q, $step)
{
	if($step == 0) return 1;
	$v = ceil(($q - $qmin)/$step);
	if($v == 0) return 0;
	else return $v-1;

}

function parse_duedate($s)
{
	$df2 = Config::get('dateformat2');
	if(max((int)strpos($df2,'n'), (int)strpos($df2,'m')) > max((int)strpos($df2,'d'), (int)strpos($df2,'j'))) $formatDayFirst = true;
	else $formatDayFirst = false;

	$y = $m = $d = 0;
	if(preg_match("|^(\d+)-(\d+)-(\d+)\b|", $s, $ma)) {
		$y = (int)$ma[1]; $m = (int)$ma[2]; $d = (int)$ma[3];
	}
	elseif(preg_match("|^(\d+)\/(\d+)\/(\d+)\b|", $s, $ma))
	{
		if($formatDayFirst) {
			$d = (int)$ma[1]; $m = (int)$ma[2]; $y = (int)$ma[3];
		} else {
			$m = (int)$ma[1]; $d = (int)$ma[2]; $y = (int)$ma[3];
		}
	}
	elseif(preg_match("|^(\d+)\.(\d+)\.(\d+)\b|", $s, $ma)) {
		$d = (int)$ma[1]; $m = (int)$ma[2]; $y = (int)$ma[3];
	}
	elseif(preg_match("|^(\d+)\.(\d+)\b|", $s, $ma)) {
		$d = (int)$ma[1]; $m = (int)$ma[2]; 
		$a = explode(',', date('Y,m,d'));
		if( $m<(int)$a[1] || ($m==(int)$a[1] && $d<(int)$a[2]) ) $y = (int)$a[0]+1; 
		else $y = (int)$a[0];
	}
	elseif(preg_match("|^(\d+)\/(\d+)\b|", $s, $ma))
	{
		if($formatDayFirst) {
			$d = (int)$ma[1]; $m = (int)$ma[2];
		} else {
			$m = (int)$ma[1]; $d = (int)$ma[2];
		}
		$a = explode(',', date('Y,m,d'));
		if( $m<(int)$a[1] || ($m==(int)$a[1] && $d<(int)$a[2]) ) $y = (int)$a[0]+1; 
		else $y = (int)$a[0];
	}
	else return null;
	if($y < 100) $y = 2000 + $y;
	elseif($y < 1000 || $y > 2099) $y = 2000 + (int)substr((string)$y, -2);
	if($m > 12) $m = 12;
	$maxdays = daysInMonth($m,$y);
	if($m < 10) $m = '0'.$m;
	if($d > $maxdays) $d = $maxdays;
	elseif($d < 10) $d = '0'.$d;
	return "$y-$m-$d";
}

function prepare_duedate($duedate)
{
	$lang = Lang::instance();

	$a = array( 'class'=>'', 'str'=>'', 'formatted'=>'', 'timestamp'=>0 );
	if($duedate == '') {
		return $a;
	}
	$ad = explode('-', $duedate);
	$at = explode('-', date('Y-m-d'));
	$a['timestamp'] = mktime(0,0,0,$ad[1],$ad[2],$ad[0]);
	$diff = mktime(0,0,0,$ad[1],$ad[2],$ad[0]) - mktime(0,0,0,$at[1],$at[2],$at[0]);

	if($diff < -604800 && $ad[0] == $at[0])	{ $a['class'] = 'past'; $a['str'] = formatDate3(Config::get('dateformatshort'), (int)$ad[0], (int)$ad[1], (int)$ad[2], $lang); }
	elseif($diff < -604800)	{ $a['class'] = 'past'; $a['str'] = formatDate3(Config::get('dateformat2'), (int)$ad[0], (int)$ad[1], (int)$ad[2], $lang); }
	elseif($diff < -86400)		{ $a['class'] = 'past'; $a['str'] = sprintf($lang->get('daysago'),ceil(abs($diff)/86400)); }
	elseif($diff < 0)			{ $a['class'] = 'past'; $a['str'] = $lang->get('yesterday'); }
	elseif($diff < 86400)		{ $a['class'] = 'today'; $a['str'] = $lang->get('today'); }
	elseif($diff < 172800)		{ $a['class'] = 'today'; $a['str'] = $lang->get('tomorrow'); }
	elseif($diff < 691200)		{ $a['class'] = 'soon'; $a['str'] = sprintf($lang->get('indays'),ceil($diff/86400)); }
	elseif($ad[0] == $at[0])	{ $a['class'] = 'future'; $a['str'] = formatDate3(Config::get('dateformatshort'), (int)$ad[0], (int)$ad[1], (int)$ad[2], $lang); }
	else						{ $a['class'] = 'future'; $a['str'] = formatDate3(Config::get('dateformat2'), (int)$ad[0], (int)$ad[1], (int)$ad[2], $lang); }

	$a['formatted'] = formatTime(Config::get('dateformat2'), $a['timestamp']);

	return $a;
}

function date2int($d)
{
	if(!$d) return 33330000;
	$ad = explode('-', $d);
	$s = $ad[0];
	if(strlen($ad[1]) < 2) $s .= "0$ad[1]"; else $s .= $ad[1];
	if(strlen($ad[2]) < 2) $s .= "0$ad[2]"; else $s .= $ad[2];
	return (int)$s;
}

function daysInMonth($m, $y=0)
{
	if($y == 0) $y = (int)date('Y');
	$a = array(1=>31,(($y-2000)%4?28:29),31,30,31,30,31,31,30,31,30,31);
	if(isset($a[$m])) return $a[$m]; else return 0;
}

function myErrorHandler($errno, $errstr, $errfile, $errline)
{
	if($errno==E_ERROR || $errno==E_CORE_ERROR || $errno==E_COMPILE_ERROR || $errno==E_USER_ERROR || $errno==E_PARSE) $error = 'Error';
	elseif($errno==E_WARNING || $errno==E_CORE_WARNING || $errno==E_COMPILE_WARNING || $errno==E_USER_WARNING || $errno==E_STRICT) {
		if(error_reporting() & $errno) $error = 'Warning'; else return;
	}
	elseif($errno==E_NOTICE || $errno==E_USER_NOTICE) {
		if(error_reporting() & $errno) $error = 'Notice'; else return;
	}
	elseif(defined('E_DEPRECATED') && ($errno==E_DEPRECATED || $errno==E_USER_DEPRECATED)) { # since 5.3.0
		if(error_reporting() & $errno) $error = 'Notice'; else return;
	}
	else $error = "Error ($errno)";	# here may be E_RECOVERABLE_ERROR
	throw new Exception("$error: '$errstr' in $errfile:$errline", -1);
}

function myExceptionHandler($e)
{
	if(-1 == $e->getCode()) {
		echo $e->getMessage()."\n". $e->getTraceAsString();
		exit;
	}
	echo 'Exception: \''. $e->getMessage() .'\' in '. $e->getFile() .':'. $e->getLine(); //."\n". $e->getTraceAsString();
	exit;
}

function deleteTask($id)
{
	check_write_access();
	$db = DBConnection::instance();
	$db->ex("BEGIN");
	$db->ex("DELETE FROM {$db->prefix}tag2task WHERE task_id=$id");
	//TODO: delete unused tags?
	$db->dq("DELETE FROM {$db->prefix}todolist WHERE id=$id");
	$affected = $db->affected();
	$db->ex("COMMIT");
	return $affected;
}

function moveTask($id, $listId)
{
	check_write_access();
	$db = DBConnection::instance();

	// Check task exists and not in target list
	$r = $db->sqa("SELECT * FROM {$db->prefix}todolist WHERE id=?", array($id));
	if(!$r || $listId == $r['list_id']) return false;

	// Check target list exists
	if(!$db->sq("SELECT COUNT(*) FROM {$db->prefix}lists WHERE id=?", $listId))
		return false;

	$ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}todolist WHERE list_id=? AND compl=?", array($listId, $r['compl']?1:0));
	
	$db->ex("BEGIN");
	$db->ex("UPDATE {$db->prefix}tag2task SET list_id=? WHERE task_id=?", array($listId, $id));
	$db->dq("UPDATE {$db->prefix}todolist SET list_id=?, ow=?, d_edited=? WHERE id=?", array($listId, $ow, time(), $id));
	$db->ex("COMMIT");
	return true;
}

function getUserListsSimple()
{
	$db = DBConnection::instance();
	$a = array();
	$q = $db->dq("SELECT id,name FROM {$db->prefix}lists ORDER BY id ASC");
	while($r = $q->fetch_row()) {
		$a[$r[0]] = $r[1];
	}
	return $a;
}

function prepareList($row)
{
	$taskview = (int)$row['taskview'];
	return array(
		'id' => $row['id'],
		'name' => htmlarray($row['name']),
		'sort' => (int)$row['sorting'],
		'published' => $row['published'] ? 1 :0,
		'showCompl' => $taskview & 1 ? 1 : 0,
		'showNotes' => $taskview & 2 ? 1 : 0,
		'hidden' => $taskview & 4 ? 1 : 0,
		);
}

function loadLists ($db, $sqlWhere)
{
	$t = array();
	$t['total'] = 0;
	$q = $db->dq("SELECT * FROM {$db->prefix}lists $sqlWhere ORDER BY ow ASC, id ASC");
	while($r = $q->fetch_assoc($q))
	{
		$t['total']++;
		$l = prepareList($r);
		$t['list'][$l['id']] = $l;
	}
	return $t;
}

function addList ($db, $name)
{
	$t = array();
	$t['total'] = 0;
	$name = str_replace(array('"',"'",'<','>','&'),array('','','','',''),trim($name));
	$ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}lists");
	$db->dq("INSERT INTO {$db->prefix}lists (uuid,name,ow,d_created,d_edited) VALUES (?,?,?,?,?)",
	array(generateUUID(), $name, $ow, time(), time()) );
	$id = $db->last_insert_id();
	$t['total'] = 1;
	$r = $db->sqa("SELECT * FROM {$db->prefix}lists WHERE id=$id");
	$t['list'][] = prepareList($r);
	return $t;
}

function addTask ($db, $listId, $title, $tag, $note = null, $priority = null, $duedate = null, $tags = null)
{
	$t = array();
	$t['total'] = 0;
	$title = trim($title);
	if($title == '')
		return $t;
	
	if ($note)
		$note = str_replace("\r\n", "\n", trim($note));
	else
		$note = "";
	
	$duedate = parse_duedate(trim($duedate));
	
	$prio = 0;
	
	if ($tags)
		$tags = trim($tags);
	else
		$tags = '';
	
	if(Config::get('smartsyntax') != 0)
	{
		$a = parse_smartsyntax($title);
		if($a === false) {
			jsonExit($t);
		}
		$title = $a['title'];
		$prio = $a['prio'];
		$tags = ($tags ? $tags."," : "").$a['tags'];
	}
	
	if ($priority)
		$prio = (int)$priority;
	if ($prio < -1)
		$prio = -1;
	elseif ($prio > 2)
		$prio = 2;
	
	
	if(Config::get('autotag'))
		$tags .= ','._post('tag');
	$ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}todolist WHERE list_id=$listId AND compl=0");
	$db->ex("BEGIN");
	$db->dq("INSERT INTO {$db->prefix}todolist (uuid,list_id,title,d_created,d_edited,ow,prio,note,duedate) VALUES(?,?,?,?,?,?,?,?,?)",
	array(generateUUID(), $listId, $title, time(), time(), $ow, $prio, $note, $duedate) );
	$id = $db->last_insert_id();
	if($tags != '')
	{
		$aTags = prepareTags($tags);
		if($aTags) {
			addTaskTags($id, $aTags['ids'], $listId);
			$db->ex("UPDATE {$db->prefix}todolist SET tags=?,tags_ids=? WHERE id=$id", array(implode(',',$aTags['tags']), implode(',',$aTags['ids'])));
		}
	}
	$db->ex("COMMIT");
	$r = $db->sqa("SELECT * FROM {$db->prefix}todolist WHERE id=$id");
	$t['list'][] = prepareTaskRow($r, loadLists ($db, ''));
	$t['total'] = 1;
	return $t;
}

function is_logged()
{
	if (defined ("__API__"))
		return true;
	if (Config::get('signature') != '' && (_get("signature") == Config::get('signature') || _post("signature") == Config::get('signature') || (defined("__SIG__") && __SIG__ == Config::get('signature'))))
		return true;
	if(!isset($_SESSION['logged']) || !$_SESSION['logged']) return false;
	return true;
}

function is_readonly()
{
	$needAuth = (Config::get('password') != '') ? 1 : 0;
	if($needAuth && !is_logged()) return true;
	return false;
}

function timestampToDatetime($timestamp)
{
	$format = Config::get('dateformat') .' '. (Config::get('clock') == 12 ? 'g:i A' : 'H:i');
	return formatTime($format, $timestamp);
}

function formatTime($format, $timestamp=0)
{
	$lang = Lang::instance();
	if($timestamp == 0) $timestamp = time();
	$newformat = strtr($format, array('F'=>'%1', 'M'=>'%2'));
	$adate = explode(',', date('n,'.$newformat, $timestamp), 2);
	$s = $adate[1];
	if($newformat != $format)
	{
		$am = (int)$adate[0];
		$ml = $lang->get('months_long');
		$ms = $lang->get('months_short');
		$F = $ml[$am-1];
		$M = $ms[$am-1];
		$s = strtr($s, array('%1'=>$F, '%2'=>$M));
	}
	return $s;
}

?>