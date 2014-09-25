<?php
/************************************
 * Change this to where the ini file and DB folder are kept.
 ************************************/
define("BASEPATH", "");
define("VERSION", "0.2");

$f3 = require(BASEPATH . 'lib/fatfree/base.php');
$f3->set("UI", BASEPATH . "templates/");

/**** Initialise ****/

date_default_timezone_set('Europe/London');

require_once BASEPATH . 'lib/Venue.php';
require_once BASEPATH . 'lib/Event.php';
require_once BASEPATH . 'lib/User.php';
require_once BASEPATH . 'lib/Feeds.php';
require_once BASEPATH . 'lib/template_utils.php';

$options = parse_ini_file(BASEPATH . 'echo.ini', true);
define("READONLY", $options['db']['readonly']);
$f3->set('readonly', READONLY);
$f3->set('version', VERSION);
$f3->set('DEBUG', $options['general']['debug']);
$f3->set("domain", $options['web']['domain']);

$db = new DB\SQL("sqlite:" . BASEPATH . $options['db']['events']);
Events::init($db);
User::init($db);

$feedsdb = new DB\SQL("sqlite:" . BASEPATH . $options['db']['feeds']);
Feeds::init($feedsdb);

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
		$_SESSION['message'] = "Sorry, you can't do that.  The site is in read-only mode.";
		$f3->reroute("/");
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
	$where = "hidden IS NOT 1 AND date >= date('now', '-3 months') AND ( ".
		"SELECT COUNT(p2.feed_url) FROM post_info AS p2 " .
		"WHERE p2.feed_url = post_info.feed_url AND p2.date > post_info.date ".
	") == 0 ORDER BY date DESC";

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

function event_page($f3, $events, $calendar = FALSE) {
	/* Check for what kind of listing we want */
	if (isset($_GET['calendar']))
		$_SESSION['calendar'] = true;
	else if (isset($_GET['listing']))
		$_SESSION['calendar'] = false;
	else
		$_SESSION['calendar'] = true;

	/* Choose what to display */
	if ($_SESSION['calendar']) {
		$f3->set('json', Events::json($events));
		return Template::instance()->render("events_calendar.html");
	} else {
		$f3->set('events', $events);
		return Template::instance()->render("events_listing.html");
	}
}


$f3->route('GET /events/@year/@month', function($f3) {
	admin_check(FALSE);

	$year = $f3->get('PARAMS.year');
	$month = $f3->get('PARAMS.month');

	$dt = DateTime::createFromFormat("Y-M-d", $year."-".$month."-01");
	if (!$dt) $f3->error("Sorry, I can't find that month.");

	$month_next = clone $dt;
	$month_next->modify("first day of next month");
	$month_prev = clone $dt;
	$month_prev->modify("first day of last month");

	$month_begins = $dt->format('Y-m-d');

	$where = "state == 'approved' AND (" .
				"startdt >= date('".$month_begins."') AND " .
				"startdt <= date('".$month_begins."', 'start of month', '+1 month', '-1 day')" .
			")";

	$f3->set('nav', [
		"title" => "Events in ". $dt->format("F Y"),
		"next" => [
			"title" => $month_next->format("F Y"),
			"url" => "/events/".strtolower($month_next->format("Y/M"))
		],
		"prev" => [
			"title" => $month_prev->format("F Y"),
			"url" => "/events/".strtolower($month_prev->format("Y/M"))
		],
		"date" => [
			"year" => intval($dt->format('Y')),
			"month" => intval($dt->format('n')) - 1,
		],
	]);

	echo event_page($f3, Events::load($where));
});

$f3->route('GET /events', function($f3) {
	$f3->reroute("/events/" . strtolower(date("Y/M")));
});

$f3->route('GET /events/unapproved', function($f3) {
	admin_check();
	$f3->set('nav', [
		"title" => "Unapproved events",
		"prev" => [
			"title" => "Back to admin panel",
			"url" => "/admin"
		],
		"allow_nav" => true
	]);

	echo event_page($f3, Events::load("state IS NOT 'approved' AND state IS NOT 'rejected'"));
});

$f3->route('POST /events/purge', function($f3) {
	admin_check();
	readonly_check();
	Events::purge(intval($_POST['months']));
	$_SESSION["message"] = 'Old events purged.';
	$f3->reroute("/admin");
});

/***************************/
/**** Adding new events ****/
/***************************/

$f3->route('GET /event/add', function($f3) {
	$event = new Event();
	$messages = $event->parse_form_data($_GET);
	$event->set_form_data();

	admin_check(FALSE);
	if (isset($_SESSION['email']))
		$f3->set("email", $_SESSION['email']);


	echo Template::instance()->render("event_add.html");
});

$f3->route('POST /event/add', function($f3) {
	admin_check(FALSE);
	spam_check();
	readonly_check();

	$admin = $f3->get('admin');

	$event = new Event();
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		$event->set_form_data();
		$f3->set('messages', $messages);
		echo Template::instance()->render("event_add.html");
	} else if ($admin) {
		$event->state = "approved";
		$event->save();
		$_SESSION['message'] = "Event added and approved.";
		$f3->reroute("/");
	} else {
		$event->state = "submitted";
		$event->save();
		$event->send_confirm_mail();

		User::notify_all($event);

		$_SESSION['message'] = "<b>Event submitted</b>. Please wait while one of our moderators checks it.";
		$f3->reroute("/");
	}
});

function find_dupes($f3, $event) {
	if ($_SESSION['admin'] && $event->state == 'imported') {

		$n = Events::load("date(startdt) IS date('" . $event->startdt->format("Y-m-d") . "')" .
				" AND id IS NOT " . $event->id .
				" AND time(startdt) >= time('" . $event->startdt->format("H:i") . "', '-1 hour')" .
				" AND time(startdt) <= time('" . $event->startdt->format("H:i") . "', '+1 hour')");

		/* Try to find duplicate events */
		/* For now just make an array with this event in */
		if (count($n) > 0)
			$f3->set("dupes", $n);
	}
}

$f3->route('GET /event/@id [ajax]', function($f3) {
	admin_check(FALSE);
	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	find_dupes($f3, $event);

	$f3->set("event", $event);
	echo Template::instance()->render("_event_box.html");
});

$f3->route('GET /event/@id', function($f3) {
	admin_check(FALSE);
	try { $event = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$f3->set('nav', [
		"title" => "Event",
		"prev" => [
			"title" => "More events in " . $event->startdt->format("F Y"),
			"url" => "/events/".strtolower($event->startdt->format("Y/M"))
		]
	]);

	find_dupes($f3, $event);

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
		$_SESSION['message'] = 'Event saved.';
		$f3->reroute("/event/" . $id . "/edit");
	}
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

$f3->route('GET /event/@id/reject', function($f3) {
	global $options;

	admin_check();
	readonly_check();

	try { $e = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	$f3->set("event", $e);
	$f3->set("from_email", $options['general']['email']);

	echo Template::instance()->render("event_reject.html");
});

$f3->route('POST /event/@id/reject', function($f3) {
	global $options;

	admin_check();
	readonly_check();

	try { $e = new Event(intval($f3->get('PARAMS.id'))); }
	catch (Exception $e) { $f3->error(404); }

	if ($e->state != 'imported') {
		$to = $e->email;
		$subject = 'Unpublished event: "' . $e->title . '"';
		$body = wordwrap($_POST['text']);
		$headers = "From: " . $options['general']['email'];

		mail($to, $subject, $body, $headers);
	}

	$e->state = "rejected";
	$e->save();

	$_SESSION['message'] = "Event rejected.";
	$f3->reroute("/admin");
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
	$_SESSION['message'] = "Old posts purged.";
	$f3->reroute("/admin");
});

$f3->route('GET /posts/eventish', function($f3) {
	admin_check();
	$posts = Feeds::load("eventish = 1 ORDER BY date DESC");

	function eventish($s) {
		$times = [
			"/\d?\d[\.:]\d\d([ap]m)?/",
			"/\d?\d([ap]m)/"
		];

		$dates = [
			"/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)/",
			"/(\d?\d)(th|rd|nd)/",
		];

		$time = [];
		foreach ($times as $expr) {
			if (preg_match($expr, $s, $matches))
				$time[] = $matches[0];
		}

		$date = [];
		foreach ($dates as $expr) {
			if (preg_match($expr, $s, $matches))
				$date[] = $matches[1];
		}

		if (preg_match("/(January|February|March|May|April|June|July|August|September|October|November|December)/", $s, $matches))
			$date[] = $matches[0];
		else if (preg_match("/(Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/", $s, $matches))
			$date[] = $matches[0];

		$return = [
			'time' => '',
			'date' => ''
		];

		if (isset($time[0])) $return['time'] = $time[0];
		if (isset($date[0])) $return['date'] = join(" ", $date);

		return $return;
	}

	foreach ($posts as &$post) {
		$post['event'] = eventish($post['content']);
		$post['summary'] = preg_replace('/\s{2,}/', ' ', trim($post['summary']));
	}

	$f3->set('posts', $posts);
	echo Template::instance()->render("posts.html");
});

/***********************/
/**** Editing posts ****/
/***********************/

$f3->route('GET /post/edit', function($f3) {
	admin_check();
	readonly_check();

	$r = Feeds::$db->exec("SELECT id, title, summary FROM posts WHERE id=:id", [ ":id" => $_GET['id'] ]);
	$r = $r[0];

	$f3->set('id', $r['id']);
	$f3->set('title', $r['title']);
	$f3->set('summary', $r['summary']);

	echo Template::instance()->render("post_edit.html");
});

$f3->route('POST /post/edit', function($f3) {
	admin_check();
	readonly_check();

	$values = [
		":id" => $_POST['id'],
		":title" => $_POST['title'],
		":summary" => $_POST['summary']
	];

	Feeds::$db->exec("UPDATE posts SET title=:title, summary=:summary WHERE id=:id", $values);

	$_SESSION['message'] = "Changes saved.";
	$f3->reroute("/");
});

$f3->route('POST /post/hide', function($f3) {
	admin_check();
	readonly_check();

	$values = [ ":id" => $_GET['id'] ];
	Feeds::$db->exec("UPDATE posts SET hidden=1 WHERE id=:id", $values);

	$_SESSION['message'] = "Post hidden.";
	$f3->reroute("/");
});

$f3->route('POST /post/not-event', function($f3) {
	admin_check();
	readonly_check();

	$values = [ ":id" => $_GET['id'] ];
	Feeds::$db->exec("UPDATE posts SET eventish=0 WHERE id=:id", $values);

	echo "Marked as not an event.";
});

/****************/
/**** Venues ****/
/****************/

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

	$_SESSION['message'] = "Venue added.";
	$f3->reroute("/");
});

/********************************/
/**** Feed display & editing ****/
/********************************/

$f3->route('GET /feeds', function($f3) {
	admin_check();

	$f3->set('feeds', Feeds::getlist());
	echo Template::instance()->render("admin_feeds.html");
});

$f3->route('POST /feeds/add', function($f3) {
	admin_check();
	readonly_check();

	if (Feeds::add($_POST['url']))
		$_SESSION['message'] = "Feed added.";
	else
		$_SESSION['message'] = "Failed to add feed to database.";

	$f3->reroute("/feeds");
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

	$_SESSION['message'] = 'Changes saved.';
	$f3->reroute("/feeds");
});

/******************************/
/**** Admin login & logout ****/
/******************************/

$f3->route('GET /admin', function($f3) {
	admin_check();

	/** Retrieve event info **/
	$events_info = [
		"submitted" => 0,
		"approved" => 0,
		"old" => 0
	];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state IS "submitted" OR state IS NULL');
	$events_info['submitted'] = $r[0]['count'];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="approved"');
	$events_info['approved'] = $r[0]['count'];

	$r = Events::$db->exec('SELECT count(*) AS count FROM events WHERE state="approved" AND date < date("now", "start of day")');
	$events_info['old'] = $r[0]['count'];

	$f3->set("events", $events_info);

	$feed_info = [
		"feeds" => 0,
		"posts" => 0,
		"old_posts" => 0
	];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM feeds');
	$feed_info['feeds'] = $r[0]['count'];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM posts');
	$feed_info['posts'] = $r[0]['count'];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM posts WHERE eventish=1');
	$feed_info['posts_eventish'] = $r[0]['count'];

	$r = Feeds::$db->exec('SELECT count(*) AS count FROM posts WHERE date < date("now", "-2 month")');
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
		[ ':email' => $_POST['email'] ]);

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
	$_SESSION['admin'] = TRUE;
	$_SESSION['email'] = $r['email'];
	$_SESSION['rights'] = $r['rights'];
	session_commit();

	$f3->reroute("/admin");
});

$f3->route('GET /admin/logout', function($f3) {

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

$f3->route('GET /admin/venues', function($f3) {
	$f3->set("venues", Events::$db->exec(
		"SELECT location AS name, count(location) AS count ".
		"FROM events WHERE location != CAST(location AS INTEGER)".
		"GROUP BY location ORDER BY name ASC;"));

	echo Template::instance()->render("admin_venues.html");
});

$f3->route('GET /admin/users', function($f3) {
	admin_check();
	if ($_SESSION['rights'] != "admin")
		$f3->reroute("/admin");

	$f3->set("users", Events::$db->exec("SELECT * FROM users"));

	echo Template::instance()->render("admin_users.html");
});

$f3->route('POST /admin/users', function($f3) {
	admin_check();
	if ($_SESSION['rights'] != "admin")
		$f3->reroute("/admin");

	$user = $_POST['user'];

	switch ($_POST['what']) {
		case 'update_notify': {
			$notify = isset($_POST['notify']) ? true : false;
			if (!User::set_notify($user, $notify))
				$f3->error(500);
			break;
		}

		case 'reset_password': {
			User::reset_password($user);
			break;
		}

		case 'update_rights': {
			User::set_rights($user, $_POST['rights']);
			break;
		}

		case 'new_user': {
			User::new_user($user, $_POST['rights']);
			break;
		}

		default: {
			echo "Error";
		}
	}

	$f3->reroute("/admin/users");
});

$f3->route('GET /p/@key', function($f3) {
	$key = $f3->get('PARAMS.key');
	$user = User::find_pwreset($key);
	if (!$user)
		$f3->error(404);

	$f3->set('email', $user);

	echo Template::instance()->render('password.html');
});

$f3->route('POST /p/@key', function($f3) {
	$key = $f3->get('PARAMS.key');
	$user = User::find_pwreset($key);
	if (!$user)
		$f3->error(404);

	$pw = $_POST['password'];
	$pw2 = $_POST['password2'];
	if ($pw != $pw2)
		$f3->reroute("/p/" . $key);

	User::new_password($user, $pw);

	$_SESSION['message'] = "Password reset successfully.";
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

$f3->route('GET /leaflet', function($f3) {
	$where = "startdt >= date('now', 'start of day') AND state == 'approved'";
	$f3->set('events', Events::load($where));
	echo Template::instance()->render("leaflet.html");
});

$f3->route('GET /newsletter', function($f3) {
	$where = "startdt >= date('now', 'start of day') AND state == 'approved'";
	$f3->set('events', Events::load($where));
	echo Template::instance()->render("newsletter.html");
});

$f3->route('GET /icalendar', function($f3) {
	$where = "startdt >= date('now', 'start of day') AND state == 'approved'";
	$f3->set('events', Events::load($where));
	$f3->set('ESCAPE', FALSE);
	echo Template::instance()->render("ical.txt", 'text/plain');
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

	header("Content-Type: application/json");
	echo Events::json($e);
});

$f3->set('ONERROR',
	function($f3) {
		header("Content-Type: text/html");

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

<h1><img src="/img/header.png" alt="Echo" width="100%"></h1>
<h2>Error <?=$code?></h2>
<p><?=$msg?>
<?php
if ($f3->get('DEBUG') != 0) {
	echo '<p>'.$text.'</p>';
	echo '<ul>'.$out."</ul>";
}
?>
<p><a href="/">← Go back to main site</a>
<?php

	}
);

ini_set("zlib.output_compression", "On");
session_start();

if (isset($_SESSION['message'])) {
	$f3->set("message", $_SESSION['message']);
	$_SESSION['message'] = NULL;	
}

try {
	$f3->run();
} catch (Exception $e) {
	$f3->error(500, $e->getmessage(), $e->gettrace());
}

?>
