<?php
/************************************
 * Change this to where the ini file and DB folder are kept.
 ************************************/
define("BASEPATH", "");

$f3 = require(BASEPATH . 'lib/fatfree/base.php');
$f3->set("UI", BASEPATH . "templates/");

/**** Initialise ****/

date_default_timezone_set('Europe/London');

require_once BASEPATH . 'lib/Venue.php';
require_once BASEPATH . 'lib/Event.php';
require_once BASEPATH . 'lib/User.php';
require_once BASEPATH . 'lib/Feeds.php';
require_once BASEPATH . 'lib/template_utils.php';

$options = parse_ini_file(BASEPATH . 'doormat.ini', true);
define("READONLY", $options['db']['readonly']);
$f3->set('readonly', READONLY);
$f3->set('DEBUG', $options['general']['debug']);

$db = new DB\SQL("sqlite:" . BASEPATH . $options['db']['events']);
Events::init($db);
User::init($db);

$feedsdb = new DB\SQL("sqlite:" . BASEPATH . $options['db']['feeds']);
Feeds::init($feedsdb);

if (isset($_GET['msg']))
	$f3->set('message', strip_tags($_GET['msg']));
$f3->set('baseurl', $options['web']['echo_root']);
$f3->set('appname', $options['general']['name']);

function spam_check() {
	global $options;

	if (!isset($options['spam']['blocklist'])) return;
	$blocklist = $options['spam']['blocklist'];
	$addr = $f3->realip();
	$quad = implode('.', array_reverse(explode('.',$addr)));

	foreach ($blocklist as $list) {
		// Check against DNS blacklist
		if (gethostbyname($quad.'.'.$list) != $quad.'.'.$list) {
						echo Template::instance()->render("spam.html");
			die;
		}
	}
}

function admin_check($reroute = TRUE) {
	global $f3;
	session_start();
	if (!isset($_SESSION['admin'])) {
		if ($reroute) {
			$f3->reroute("/admin/login");
			exit();
		}
	} else {
		$f3->set("admin", TRUE);
	}
}

function readonly_check() {
	global $f3;
	if (READONLY) {
		$f3->reroute("/?msg=Sorry,+can't+do+that.+Database+in+read-only+mode.");
		die;
	}
}

/********************/
/**** Front page ****/
/********************/

$f3->route('GET /', function($f3) {
	admin_check(FALSE);

	/* Events */
	$where = "(startdt >= date('now', 'start of day') OR date('now') <= enddt) AND state == 'approved'";
	$limit = "0,10";
	$f3->set('events', Events::load($where, $limit));

	/* Posts */
	$where = "hidden IS NOT 1 ORDER BY date DESC LIMIT 0, 15";
	$f3->set('posts', Feeds::load($where));

	echo Template::instance()->render("index.html");
});

/* XXX need to work out a better way to do this that is generic, no time now though */
/* maybe a var 'passthrough' in the ini which just makes pages listed in it serve up <page>.html */
$f3->route('GET /about', function($f3) {
	echo Template::instance()->render("about.html");
});

/***************************/
/**** Displaying events ****/
/***************************/

$f3->route('GET /events', function($f3) {
	$where = "date('now') <= enddt OR (startdt >= date('now', 'start of day') AND " .
			"startdt <= date('now', 'start of month', '+2 month', '-1 day')) AND " .
			"state == 'approved'";
	$results = Events::load($where);
	$f3->set('events', $results);
	echo Template::instance()->render("events.html");
});

$f3->route('GET /events/unapproved', function($f3) {
	admin_check();
	$f3->set('events', Events::load("state == 'validated'"));
	echo Template::instance()->render("events.html");
});

$f3->route('GET /events/unvalidated', function($f3) {
	admin_check();
	$f3->set('events', Events::load("state == 'submitted'"));
	echo Template::instance()->render("events.html");
});

$f3->route('POST /events/purge', function($f3) {
	admin_check();
	readonly_check();
	Events::purge(intval($_POST['months']));
	$f3->reroute("/admin?msg=Purged.");
});

/***************************/
/**** Adding new events ****/
/***************************/

$f3->route('GET /event/add', function($f3) {
	echo Template::instance()->render("event_add.html");
});

$f3->route('POST /event/add', function($f3) {
	spam_check();
	readonly_check();

	$event = new Event();
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		$event->set_form_data();
		$f3->set('messages', $messages);
		echo Template::instance()->render("event_add.html");
	} else {
		// Check user isn't banned
		$banned = User::isbanned($event->email);
		if ($banned) {
			echo Template::instance()->render("spam.html");
			die;
		}

		$event->generate_key();
		$event->save();
		$event->send_confirm_mail();

		global $options;

		// XXX This is a hack until we have something better
		mail($options['general']['notify'], "New Echo event", "As above.", "From: " . $options['general']['email']);

		// XXX How about sending the user to a special 'event added' page?
		$f3->reroute("/?msg=Event+submitted.+Please+check+your+email.");
	}
});

$f3->route('GET /event/@id [ajax]', function($f3) {
	admin_check(FALSE);
	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$f3->set("event", $event);
	echo Template::instance()->render("event_box.html");
});

$f3->route('GET /event/@id', function($f3) {
	admin_check(FALSE);
	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$f3->set("event", $event);
	echo Template::instance()->render("event.html");
});

$f3->route('GET /event/@id/edit', function($f3) {
	admin_check();
	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$event->set_form_data();
	// XXX need to fix this - an event edited by an admin shouldn't have a required 'email' field
	echo Template::instance()->render("event_add.html");
});

$f3->route('POST /event/@id/edit', function($f3) {
	admin_check();
	readonly_check();

	$id = intval($f3->get('PARAMS.id'));

	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		$event->set_form_data();
		$f3->set('messages', $messages);
		echo Template::instance()->render("event_add.html");
	} else {
		$event->save();
		$f3->reroute("/event/" . $id . "/edit?msg=Event%20saved.");
	}
});

$f3->route('GET /c/@key', function($f3) {
	global $events;

	$key = $f3->get('PARAMS.key');
	if (ctype_alnum($key) && Events::validate($key))
		$message = "Event submitted.  Please await approval :)";
	else
		$message = "No event to approve found!  Maybe you already approved it?";

	$f3->reroute("/?msg=" . urlencode($message));
});

$f3->route('POST /event/@id/approve', function($f3) {
	admin_check();
	readonly_check();

	try { $e = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }
	$e->approve();

	echo "Approved";
});

$f3->route('POST /event/@id/unapprove', function($f3) {
	admin_check();
	readonly_check();

	try { $e = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }
	$e->unapprove();

	echo "Unapproved";
});

$f3->route('POST /event/@id/delete', function($f3) {
	admin_check();
	readonly_check();
	Events::delete(intval($f3->get('PARAMS.id')));
	echo "Deleted";
});

/***********************/
/**** Posts in bulk ****/
/***********************/

$f3->route('POST /posts/purge', function($f3) {
	admin_check();
	readonly_check();

	Feeds::purge(intval($_POST['months']));
	$f3->reroute("/admin?msg=Purged.");
});

/***********************/
/**** Editing posts ****/
/***********************/

$f3->route('GET /post/edit', function($f3) {
	admin_check();
	readonly_check();

	$r = Feeds::$db->exec("SELECT id, title, summary FROM posts WHERE id=:id", array(":id" => $_GET['id']));
	$r = $r[0];

	$f3->set('id', $r['id']);
	$f3->set('title', $r['title']);
	$f3->set('summary', $r['summary']);

	echo Template::instance()->render("post_edit.html");
});

$f3->route('POST /post/edit', function($f3) {
	admin_check();
	readonly_check();

	$values = array(
		":id" => $_POST['id'],
		":title" => $_POST['title'],
		":summary" => $_POST['summary']);

	Feeds::$db->exec("UPDATE posts SET title=:title, summary=:summary WHERE id=:id", $values);

	$f3->reroute("/?msg=Done.");
});

$f3->route('POST /post/hide', function($f3) {
	admin_check();
	readonly_check();

	$values = array(":id" => $_GET['id']);
	Feeds::$db->exec("UPDATE posts SET hidden=1 WHERE id=:id", $values, 0, 'feeds');

	$f3->reroute("/?msg=Hidden!");
});

/****************/
/**** Venues ****/
/****************/

$f3->route('GET /venues', function($f3) {
	$f3->set("venues", Events::$db->exec(
		"SELECT location AS name, count(location) AS count ".
		"FROM events WHERE location != CAST(location AS INTEGER)".
		"GROUP BY location ORDER BY count DESC;"));

	echo Template::instance()->render("venues.html");
});

$f3->route('GET /venue/@id', function($f3) {
	$v = new Venue(intval($f3->get('PARAMS.id')));
	$f3->set("venue", $v);
	echo Template::instance()->render("venue.html");
});

$f3->route('GET /venue/add', function($f3) {
	admin_check();
	readonly_check();
	echo Template::instance()->render("venue_add.html");
});

$f3->route('POST /venue/add', function($f3) {
	readonly_check();
	admin_check();

	$venue = new Venue();
	$messages = $venue->parse_form_data();

	if (count($messages) > 0) {
		set_venue_data_from_POST();
		$f3->set('messages', $messages);
		echo Template::instance()->render("venue_add.html");
		die;
	}

	$venue->save();

	$f3->reroute("/?msg=Venue+added.");
});

/********************************/
/**** Feed display & editing ****/
/********************************/

$f3->route('GET /feeds', function($f3) {
	admin_check();

	$f3->set('feeds', Feeds::getlist());
	echo Template::instance()->render("feeds.html");
});

$f3->route('POST /feeds/add', function($f3) {
	admin_check();
	readonly_check();

	if (Feeds::add($_POST['url']))
		$message = "Feed added.";
	else
		$message = "Failed to add feed to database.";

	$f3->reroute("/feeds?msg=" . $message);
});

$f3->route('POST /feeds/edit', function($f3) {
	global $feedsdb;

	admin_check();
	readonly_check();

	for ($i = 1; $i <= count($_POST["feed_url"]); $i++) {
		if (isset($_POST["delete"][$i]))
			Feeds::delete($_POST["feed_url"][$i]);
		else
			Feeds::update($_POST["feed_url"][$i], $_POST["title"][$i], $_POST["site_url"][$i]);
	}

	$f3->reroute("/feeds");
});

/******************************/
/**** Admin login & logout ****/
/******************************/

$f3->route('GET /admin', function($f3) {
	admin_check();

	/** Retrieve event info **/
	$events_info = array(
		"submitted" => 0,
		"validated" => 0,
		"approved" => 0,
		"old" => 0
	);

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="submitted"');
	$events_info['submitted'] = $r[0]['count'];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="validated"');
	$events_info['validated'] = $r[0]['count'];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="approved"');
	$events_info['approved'] = $r[0]['count'];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="approved" AND date < date("now", "start of day")');
	$events_info['old'] = $r[0]['count'];

	$f3->set("events", $events_info);

	$feed_info = array(
		"feeds" => 0,
		"posts" => 0,
		"old_posts" => 0
	);

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM feeds');
	$feed_info['feeds'] = $r[0]['count'];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM posts');
	$feed_info['posts'] = $r[0]['count'];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM posts WHERE date < date("now", "-1 month")');
	$feed_info['old_posts'] = $r[0]['count'];

	$f3->set("feeds", $feed_info);

	/** Serve it up! **/
	echo Template::instance()->render("admin.html");
});

$f3->route('GET /admin/login', function($f3) {
	echo Template::instance()->render("admin_login.html");
});

$f3->route('POST /admin/login', function($f3) {

	// XXX do the other stuff mentioned in this article:
	// http://throwingfire.com/storing-passwords-securely/
	// and perhaps add in nth character of a passphrase type stuff

	/* Get the salt */
	$result = Events::$db->exec("SELECT * FROM users WHERE email=:email",
		array(':email' => $_POST['email']));

	/* No such user! */
	if (sizeof($result) == 0) {
		$f3->set('message', "Failed login attempt, try again.");
		echo Template::instance()->render("admin_login.html");
		exit();
	}

	/* Take the first row */
	$r = $result[0];

	/* Now take the salt and the user's input and compare it to the digest */
	if ($r['digest'] != hash("sha256", $r['salt'] . $_POST['password'])) {
		$f3->set('message', "Failed login attempt, try again.");
		echo Template::instance()->render("admin_login.html");
		exit();	
	}

	// We're in!
	session_start();
	$_SESSION['admin'] = TRUE;
	$_SESSION['email'] = $r['email'];
	session_commit();

	$f3->reroute("/admin");
});

$f3->route('GET /admin/logout', function($f3) {

	session_start();

	// Nuke the session cookie
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000,
		$params["path"], $params["domain"],
		$params["secure"], $params["httponly"]
	);

	// Delete server-side data
	unset($_SESSION['admin']);
	unset($_SESSION['email']);
	session_destroy();

	$f3->reroute("/");
});


/***********************/
/**** For embedding ****/
/***********************/

$f3->route('GET /embed', function($f3) {
	echo Template::instance()->render('calendar.html');
});


/***********************/
/**** Other formats ****/
/***********************/

$f3->route('GET /icalendar', function($f3) {
	$where = "startdt >= date('now', 'start of day') AND state == 'approved'";
	$f3->set('events', Events::load($where));
	echo Template::instance()->render("ical.txt");
});

$f3->route('GET /json', function($f3) {
	$startts = intval($_GET['start']);
	$endts = intval($_GET['end']);
	if (!$startts || !$endts) {
		echo "No start/end times";
		exit();
	}

	$e = Events::load("state == 'approved' AND ".
		"startdt > datetime(". $startts .",'unixepoch') AND ".
		"startdt < datetime(". $endts .",'unixepoch')");
	$f3->set('events', $e);

	$events = array();
	foreach ($e as $event) {
		$insert = array(
			'id' => $event->id,
			'title' => $event->title,
			'start' => $event->startdt->format('U'),
		);
		if ($event->enddt)
			$insert['end'] = $event->enddt->format('U');
		if ($event->url)
			$insert['url'] = $event->url;

		$events[] = $insert;

	}

	echo json_encode($events);
	header("Content-Type: application/json");
});

$f3->set('ONERROR',
	function($f3) {
		$code = $f3->get('ERROR.code');
		$title = $f3->get('ERROR.title');
		$text = $f3->get('ERROR.text');
		$trace = $f3->get('ERROR.trace');
		$eol = "\n";
		$out = '';
		$highlight = true;

		if ($code == 404)
			$msg = "File not found.";
		else if ($code == 500)
			$msg = "There's been a server error.  There's nothing you can do about this, sorry.  Please retry whatever you were doing.";
		else
			$msg = "";

		foreach ($trace as $frame) {
			$line='';
			if (isset($frame['class']))
				$line.=$frame['class'].$frame['type'];
			if (isset($frame['function']))
				$line.=$frame['function'].' ('.(isset($frame['args'])?
					$f3->csvspace($frame['args']):'').')';
			$src=$f3->fixslashes($frame['file']).':'.$frame['line'].' ';
			error_log('- '.$src.$line);
			$out.='<li> '.($highlight?
				($f3->highlight($src).' '.$f3->highlight($line)):
				($src.$line)).$eol;
		}

?>
<!DOCTYPE html>
<title><?=$code?></title>
<style>
body { background: #eee; width: 40em; margin: auto; margin-top: 5em; }
ul { overflow-x: auto; }
</style>

<h1><img src="<?=$f3->get('baseurl')?>/img/header.png" alt="Echo" width="100%"></h1>
<h2>Error <?=$code?></h2>
<p><?=$msg?>
<?php
if ($f3->get('DEBUG') != 0) {
	echo '<p>'.$text.'</p>';
	echo '<ul>'.$out."</ul>";
}
?>
<p><a href="<?=$f3->get('baseurl')?>/">← Go back to main site</a>
<?php

	}
);

try {
	$f3->run();
} catch (Exception $e) {
	$f3->error(500, $e->getmessage(), $e->gettrace());
}

?>
