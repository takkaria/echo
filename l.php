<?php
$options = parse_ini_file('doormat.ini', true);
$baseurl = $options['web']['echo_root'];
$appname = $options['general']['name'];

$url = $_GET['p'];

// two security checks:
// 1. check it begins with 'http' (no javascript)

// 2. check it doesn't contain the character " (except in encoded form)


?>

<title><?php echo $appname; ?></title>
<head>
<style>
html, body, iframe, ul { margin: 0; padding: 0; font-family: Arial; }

a { text-decoration: none; color: blue; }

body { overflow: hidden; }

#topbar { position: absolute; top: 0; left: 0; width: 100%; background-color: #eee; display: block; height: 50px; border-bottom: 1px solid #ccc; z-index: 100; box-shadow: 0 2px 2px #ddd; }
ul, li { display: block; }
#img, #back { float: left; }

#back { line-height: 20px; margin-top: 30px; margin-left: 20px; }

#x { float: right; margin-left: 20px; font-size: 35px; line-height: 45px; margin-right: 10px; }
#x a:hover { font-weight: bold; }

iframe { border: 0; margin-top: 50px; width: 100%; height: 100%; }
</style>

<body>
<ul id="topbar">
<li id="img"><a href="<?php echo $baseurl; ?>"><img src="<?php echo $baseurl; ?>/img/header.png" height="50"></a>
<li id="back"><a href="<?php echo $baseurl; ?>">back to <?php echo $appname; ?></a>
<li id="x"><a href="<?php echo $url; ?>">x</a>
</ul>

<iframe src="<?php echo $url; ?>"></iframe>
