<?php 
if (Config::get('password') == '' || (isset ($_POST['pasword']) && isset ($_POST['user']) && $_POST['pasword'] == Config::get('password') && $_POST['user'] == Config::get('username')))
{
	session_regenerate_id(1);
	$_SESSION['logged'] = 1;
	header('Location: '.$_SERVER['REQUEST_URI']);
}
else
	header("Content-type: text/html; charset=utf-8");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title><?php mttinfo('title'); ?></title>
<link rel="stylesheet" type="text/css" href="<?php mttinfo('template_url'); ?>style.css?v=<?php echo $VERSION; ?>" media="all" />
<?php if(Config::get('rtl')): ?>
<link rel="stylesheet" type="text/css" href="<?php mttinfo('template_url'); ?>style_rtl.css?v=<?php echo $VERSION; ?>" media="all" />
<?php endif; ?>
<?php if(isset($_GET['pda'])): ?>
<meta name="viewport" id="viewport" content="width=device-width" />
<link rel="stylesheet" type="text/css" href="<?php mttinfo('template_url'); ?>pda.css?v=<?php echo $VERSION; ?>" media="all" />
<?php else: ?>
<link rel="stylesheet" type="text/css" href="<?php mttinfo('template_url'); ?>print.css?v=<?php echo $VERSION; ?>" media="print" />
<?php endif; ?>
</head>

<body>
<?php



?>
<div id="wrapper">
<div id="container">
<h2><?php mttinfo('title'); ?></h2>
<form method='POST' action='<?php echo $_SERVER['REQUEST_URI'];?>'>
<?php _e('set_user');?>: <input type='text' id='user' name='user'/>
<?php _e('password');?>: <input type='password' id='password' name='pasword'/>
<input type='submit' value='<?php _e('btn_login');?>'/>
</form>
</div>

</div>
</body>
</html>