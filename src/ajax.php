<?php

/*
	This file is part of myTinyTodo.
	(C) Copyright 2009-2010 Max Pozdeev <maxpozdeev@gmail.com>
	Licensed under the GNU GPL v3 license. See file COPYRIGHT for details.
*/ 

if(!defined('MTTPATH'))
	define('MTTPATH', dirname(__FILE__) .'/');

require_once(MTTPATH.'init.php');

if (!defined ("__API__"))
{
	set_error_handler('myErrorHandler');
}

$db = DBConnection::instance();


if(isset($_GET['loadLists']))
{
	if($needAuth && !is_logged()) $sqlWhere = 'WHERE published=1';
	else $sqlWhere = '';
	$t = loadLists ($db, $sqlWhere);
	jsonExit($t);
}
elseif(isset($_GET['loadTasks']))
{
	stop_gpc($_GET);
	$listId = (int)_get('list');
	check_read_access($listId);
	$sqlWhere = $inner = '';
	if($listId == -1) {
		$userLists = getUserListsSimple();
		$sqlWhere .= " AND {$db->prefix}todolist.list_id IN (". implode(array_keys($userLists), ','). ") ";
	}
	else $sqlWhere .= " AND {$db->prefix}todolist.list_id=". $listId;
	if(_get('compl') == 0) $sqlWhere .= ' AND compl=0';
	
	$tag = trim(_get('t'));
	if($tag != '')
	{
		$at = explode(',', $tag);
		$tagIds = array();
		$tagExIds = array();
		foreach($at as $i=>$atv) {
			$atv = trim($atv);
			if($atv == '' || $atv == '^') continue;
			if(substr($atv,0,1) == '^') {
				$tagExIds[] = getTagId(substr($atv,1));
			} else {
				$tagIds[] = getTagId($atv);
			}
		}

		if(sizeof($tagIds) > 1) {
			$inner .= "INNER JOIN (SELECT task_id, COUNT(tag_id) AS c FROM {$db->prefix}tag2task WHERE list_id=$listId AND tag_id IN (".
						implode(',',$tagIds). ") GROUP BY task_id) AS t2t ON id=t2t.task_id";
			$sqlWhere = " AND c=". sizeof($tagIds); //overwrite sqlWhere!
		}
		elseif($tagIds) {
			$inner .= "INNER JOIN {$db->prefix}tag2task ON id=task_id";
			$sqlWhere .= " AND tag_id = ". $tagIds[0];
		}
		
		if($tagExIds) {
			$sqlWhere .= " AND id NOT IN (SELECT DISTINCT task_id FROM {$db->prefix}tag2task WHERE list_id=$listId AND tag_id IN (".
						implode(',',$tagExIds). "))"; //DISTINCT ?
		}
	}

	$s = trim(_get('s'));
	if($s != '') $sqlWhere .= " AND (title LIKE ". $db->quoteForLike("%%%s%%",$s). " OR note LIKE ". $db->quoteForLike("%%%s%%",$s). ")";
	$sort = (int)_get('sort');
	$sqlSort = "ORDER BY compl ASC, ";
	if($sort == 1) $sqlSort .= "prio DESC, ddn ASC, duedate ASC, ow ASC";		// byPrio
	elseif($sort == 101) $sqlSort .= "prio ASC, ddn DESC, duedate DESC, ow DESC";	// byPrio (reverse)
	elseif($sort == 2) $sqlSort .= "ddn ASC, duedate ASC, prio DESC, ow ASC";	// byDueDate
	elseif($sort == 102) $sqlSort .= "ddn DESC, duedate DESC, prio ASC, ow DESC";// byDueDate (reverse)
	elseif($sort == 3) $sqlSort .= "d_created ASC, prio DESC, ow ASC";			// byDateCreated
	elseif($sort == 103) $sqlSort .= "d_created DESC, prio ASC, ow DESC";		// byDateCreated (reverse)
	elseif($sort == 4) $sqlSort .= "d_edited ASC, prio DESC, ow ASC";			// byDateModified
	elseif($sort == 104) $sqlSort .= "d_edited DESC, prio ASC, ow DESC";		// byDateModified (reverse)
	else $sqlSort .= "ow ASC";
	
	$lists = loadLists ($db, '');
	
	$t = array();
	$t['total'] = 0;
	$t['list'] = array();
	$q = $db->dq("SELECT *, duedate IS NULL AS ddn FROM {$db->prefix}todolist $inner WHERE 1=1 $sqlWhere $sqlSort");
	while($r = $q->fetch_assoc($q))
	{
		$t['total']++;
		$t['list'][] = prepareTaskRow($r, $lists);
	}
	if(_get('setCompl') && have_write_access($listId)) {
		$bitwise = (_get('compl') == 0) ? 'taskview & ~1' : 'taskview | 1';
		$db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=$listId");
	}
	jsonExit($t);
}
elseif(isset($_GET['newTask']))
{
	stop_gpc($_POST);
	$listId = (int)_post('list');
	check_write_access($listId);
	$t = addTask ($db, $listId, _post('title'), _post('tag'));
	jsonExit($t);
}
elseif(isset($_GET['fullNewTask']))
{
	stop_gpc($_POST);
	$listId = (int)_post('list');
	check_write_access($listId);
	$t = addTask ($db, $listId, _post('title'), _post('tag'), _post('note'), _post('prio'), _post('duedate'), _post('tags'));
	jsonExit($t);
}
elseif(isset($_GET['deleteTask']))
{
	$id = (int)_post('id');
	$deleted = deleteTask($id);
	$t = array();
	$t['total'] = $deleted;
	$t['list'][] = array('id'=>$id);
	jsonExit($t);
}
elseif(isset($_GET['completeTask']))
{
	check_write_access();
	$id = (int)_post('id');
	$compl = _post('compl') ? 1 : 0;
	$listId = (int)$db->sq("SELECT list_id FROM {$db->prefix}todolist WHERE id=$id");
	if($compl) 	$ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}todolist WHERE list_id=$listId AND compl=1");
	else $ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}todolist WHERE list_id=$listId AND compl=0");
	$dateCompleted = $compl ? time() : 0;
	$db->dq("UPDATE {$db->prefix}todolist SET compl=$compl,ow=$ow,d_completed=?,d_edited=? WHERE id=$id",
				array($dateCompleted, time()) );
	$t = array();
	$t['total'] = 1;
	$r = $db->sqa("SELECT * FROM {$db->prefix}todolist WHERE id=$id");
	$t['list'][] = prepareTaskRow($r, loadLists ($db, ''));
	jsonExit($t);
}
elseif(isset($_GET['editNote']))
{
	check_write_access();
	$id = (int)_post('id');
	stop_gpc($_POST);
	$note = str_replace("\r\n", "\n", trim(_post('note')));
	$db->dq("UPDATE {$db->prefix}todolist SET note=?,d_edited=? WHERE id=$id", array($note, time()) );
	$t = array();
	$t['total'] = 1;
	$t['list'][] = array('id'=>$id, 'note'=>nl2br(escapeTags($note)), 'noteText'=>(string)$note);
	jsonExit($t);
}
elseif(isset($_GET['editTask']))
{
	check_write_access();
	$id = (int)_post('id');
	stop_gpc($_POST);
	$title = trim(_post('title'));
	$note = str_replace("\r\n", "\n", trim(_post('note')));
	$prio = (int)_post('prio');
	if($prio < -1) $prio = -1;
	elseif($prio > 2) $prio = 2;
	$duedate = parse_duedate(trim(_post('duedate')));
	$t = array();
	$t['total'] = 0;
	if($title == '') {
		jsonExit($t);
	}
	$listId = $db->sq("SELECT list_id FROM {$db->prefix}todolist WHERE id=$id");
	$tags = trim(_post('tags'));
	$db->ex("BEGIN");
	$db->ex("DELETE FROM {$db->prefix}tag2task WHERE task_id=$id");
	$aTags = prepareTags($tags);
	if($aTags) {
		$tags = implode(',', $aTags['tags']);
		$tags_ids = implode(',',$aTags['ids']);
		addTaskTags($id, $aTags['ids'], $listId);
	}
	$db->dq("UPDATE {$db->prefix}todolist SET title=?,note=?,prio=?,tags=?,tags_ids=?,duedate=?,d_edited=? WHERE id=$id",
			array($title, $note, $prio, $tags, $tags_ids, $duedate, time()) );
	$db->ex("COMMIT");
	$r = $db->sqa("SELECT * FROM {$db->prefix}todolist WHERE id=$id");
	if($r) {
		$t['list'][] = prepareTaskRow($r, loadLists ($db, ''));
		$t['total'] = 1;
	}
	jsonExit($t);
}
elseif(isset($_GET['changeOrder']))
{
	check_write_access();
	stop_gpc($_POST);
	$s = _post('order');
	parse_str($s, $order);
	$t = array();
	$t['total'] = 0;
	if($order)
	{
		$ad = array();
		foreach($order as $id=>$diff) {
			$ad[(int)$diff][] = (int)$id;
		}
		$db->ex("BEGIN");
		foreach($ad as $diff=>$ids) {
			if($diff >=0) $set = "ow=ow+".$diff;
			else $set = "ow=ow-".abs($diff);
			$db->dq("UPDATE {$db->prefix}todolist SET $set,d_edited=? WHERE id IN (".implode(',',$ids).")", array(time()) );
		}
		$db->ex("COMMIT");
		$t['total'] = 1;
	}
	jsonExit($t);
}
elseif(isset($_POST['login']))
{
	$t = array('logged' => 0);
	if(!$needAuth) {
		$t['disabled'] = 1;
		jsonExit($t);
	}
	stop_gpc($_POST);
	$password = _post('password');
	if($password == Config::get('password')) {
		$t['logged'] = 1;
		session_regenerate_id(1);
		$_SESSION['logged'] = 1;
	}
	jsonExit($t);
}
elseif(isset($_POST['logout']))
{
	if (isset ($_SESSION['logged'])) unset($_SESSION['logged']);
	if (isset ($_COOKIE["MTTAUTH"])) setcookie ("MTTAUTH", '', 1);
	$t = array('logged' => 0);
	jsonExit($t);
}
elseif(isset($_GET['suggestTags']))
{
	$listId = (int)_get('list');
	check_read_access($listId);
	$begin = trim(_get('q'));
	$limit = (int)_get('limit');
	if($limit<1) $limit = 8;
	$q = $db->dq("SELECT name,id FROM {$db->prefix}tags INNER JOIN {$db->prefix}tag2task ON id=tag_id WHERE list_id=$listId AND name LIKE ".
					$db->quoteForLike('%s%%',$begin) ." GROUP BY tag_id ORDER BY name LIMIT $limit");
	$s = '';
	while($r = $q->fetch_row()) {
		$s .= "$r[0]|$r[1]\n";
	}
	echo htmlarray($s);
	exit; 
}
elseif(isset($_GET['setPrio']))
{
	check_write_access();
	$id = (int)$_GET['setPrio'];
	$prio = (int)_get('prio');
	if($prio < -1) $prio = -1;
	elseif($prio > 2) $prio = 2;
	$db->ex("UPDATE {$db->prefix}todolist SET prio=$prio,d_edited=? WHERE id=$id", array(time()) );
	$t = array();
	$t['total'] = 1;
	$t['list'][] = array('id'=>$id, 'prio'=>$prio);
	jsonExit($t);
}
elseif(isset($_GET['tagCloud']))
{
	$listId = (int)_get('list');
	check_read_access($listId);

	$q = $db->dq("SELECT name,tag_id,COUNT(tag_id) AS tags_count FROM {$db->prefix}tag2task INNER JOIN {$db->prefix}tags ON tag_id=id ".
						"WHERE list_id=$listId GROUP BY (tag_id) ORDER BY tags_count ASC");
	$at = array();
	$ac = array();
	while($r = $q->fetch_assoc()) {
		$at[] = array('name'=>$r['name'], 'id'=>$r['tag_id']);
		$ac[] = $r['tags_count'];
	}

	$t = array();
	$t['total'] = 0;
	$count = sizeof($at);
	if(!$count) {
		jsonExit($t);
	}

	$qmax = max($ac);
	$qmin = min($ac);
	if($count >= 10) $grades = 10;
	else $grades = $count;
	$step = ($qmax - $qmin)/$grades;
	foreach($at as $i=>$tag)
	{
		$t['cloud'][] = array('tag'=>htmlarray($tag['name']), 'id'=>(int)$tag['id'], 'w'=> tag_size($qmin,$ac[$i],$step) );
	}
	$t['total'] = $count;
	jsonExit($t);
}
elseif(isset($_GET['addList']))
{
	check_write_access();
	stop_gpc($_POST);
	$t = addList ($db, _post('name'));
	jsonExit($t);
}
elseif(isset($_GET['renameList']))
{
	check_write_access();
	stop_gpc($_POST);
	$t = array();
	$t['total'] = 0;
	$id = (int)_post('list');
	$name = str_replace(array('"',"'",'<','>','&'),array('','','','',''),trim(_post('name')));
	$db->dq("UPDATE {$db->prefix}lists SET name=?,d_edited=? WHERE id=$id", array($name, time()) );
	$t['total'] = $db->affected();
	$r = $db->sqa("SELECT * FROM {$db->prefix}lists WHERE id=$id");
	$t['list'][] = prepareList($r);
	jsonExit($t);
}
elseif(isset($_GET['deleteList']))
{
	check_write_access();
	stop_gpc($_POST);
	$t = array();
	$t['total'] = 0;
	$id = (int)_post('list');
	$db->ex("BEGIN");
	$db->ex("DELETE FROM {$db->prefix}lists WHERE id=$id");
	$t['total'] = $db->affected();
	if($t['total']) {
		$db->ex("DELETE FROM {$db->prefix}tag2task WHERE list_id=$id");
		$db->ex("DELETE FROM {$db->prefix}todolist WHERE list_id=$id");
	}
	$db->ex("COMMIT");
	jsonExit($t);
}
elseif(isset($_GET['setSort']))
{
	check_write_access();
	$listId = (int)_post('list');
	$sort = (int)_post('sort');
	if($sort < 0 || $sort > 104) $sort = 0;
	elseif($sort < 101 && $sort > 4) $sort = 0;
	$db->ex("UPDATE {$db->prefix}lists SET sorting=$sort,d_edited=? WHERE id=$listId", array(time()));
	jsonExit(array('total'=>1));
}
elseif(isset($_GET['publishList']))
{
	check_write_access();
	$listId = (int)_post('list');
	$publish = (int)_post('publish');
	$db->ex("UPDATE {$db->prefix}lists SET published=?,d_created=? WHERE id=$listId", array($publish ? 1 : 0, time()));
	jsonExit(array('total'=>1));
}
elseif(isset($_GET['moveTask']))
{
	check_write_access();
	$id = (int)_post('id');
	$fromId = (int)_post('from');
	$toId = (int)_post('to');
	$result = moveTask($id, $toId);
	$t = array('total' => $result ? 1 : 0);
	if($fromId == -1 && $result && $r = $db->sqa("SELECT * FROM {$db->prefix}todolist WHERE id=$id")) {
		$t['list'][] = prepareTaskRow($r, loadLists ($db, ''));
	}
	jsonExit($t);
}
elseif(isset($_GET['changeListOrder']))
{
	check_write_access();
	stop_gpc($_POST);
	$order = (array)_post('order');
	$t = array();
	$t['total'] = 0;
	if($order)
	{
		$a = array();
		$setCase = '';
		foreach($order as $ow=>$id) {
			$id = (int)$id;
			$a[] = $id;
			$setCase .= "WHEN id=$id THEN $ow\n";
		}
		$ids = implode($a, ',');
		$db->dq("UPDATE {$db->prefix}lists SET d_edited=?, ow = CASE\n $setCase END WHERE id IN ($ids)",
					array(time()) );
		$t['total'] = 1;
	}
	jsonExit($t);
}
elseif(isset($_GET['parseTaskStr']))
{
	check_write_access();
	stop_gpc($_POST);
	$t = array(
		'title' => trim(_post('title')),
		'prio' => 0,
		'tags' => ''
	);
	if(Config::get('smartsyntax') != 0 && (false !== $a = parse_smartsyntax($t['title'])))
	{
		$t['title'] = $a['title'];
		$t['prio'] = $a['prio'];
		$t['tags'] = $a['tags'];
	}
	jsonExit($t);
}
elseif(isset($_GET['clearCompletedInList']))
{
	check_write_access();
	stop_gpc($_POST);
	$t = array();
	$t['total'] = 0;
	$listId = (int)_post('list');
	$db->ex("BEGIN");
	$db->ex("DELETE FROM {$db->prefix}tag2task WHERE task_id IN (SELECT id FROM {$db->prefix}todolist WHERE list_id=? and compl=1)", array($listId));
	$db->ex("DELETE FROM {$db->prefix}todolist WHERE list_id=$listId and compl=1");
	$t['total'] = $db->affected();
	$db->ex("COMMIT");
	jsonExit($t);
}
elseif(isset($_GET['setShowNotesInList']))
{
	check_write_access();
	$listId = (int)_post('list');
	$flag = (int)_post('shownotes');
	$bitwise = ($flag == 0) ? 'taskview & ~2' : 'taskview | 2';
	$db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=$listId");
	jsonExit(array('total'=>1));
}
elseif(isset($_GET['setHideList']))
{
	check_write_access();
	$listId = (int)_post('list');
	$flag = (int)_post('hide');
	$bitwise = ($flag == 0) ? 'taskview & ~4' : 'taskview | 4';
	$db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=$listId");
	jsonExit(array('total'=>1));	
}


###################################################################################################


?>