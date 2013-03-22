<?php
/************************************
 * Change this to where the ini file and DB folder are kept.
 ************************************/
define("BASEPATH", "");

$f3 = require(BASEPATH . 'lib/fatfree/base.php');

// Disable this when in production
$f3->set('DEBUG', TRUE);

$f3->set("UI", BASEPATH . "templates/");

/**** Initialise ****/

require_once BASEPATH . 'lib/Venue.php';
require_once BASEPATH . 'lib/Event.php';
require_once BASEPATH . 'lib/template_utils.php';

date_default_timezone_set('Europe/London');
$options = parse_ini_file(BASEPATH . 'doormat.ini', true);

define("READONLY", $options['db']['readonly']);

Events::init(BASEPATH . $options['db']['events']);
$feeds = new DB\SQL("sqlite:" . BASEPATH . $options['db']['feeds']);
if (isset($_GET['msg']))
	$f3->set('message', strip_tags($_GET['msg']));
$f3->set('baseurl', $options['web']['echo_root']);
$f3->set('appname', $options['general']['name']);
$f3->set('readonly', READONLY);

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
	$where = "startdt >= date('now', 'start of day') AND " .
			"startdt <= date('now', 'start of day', '+14 days') AND " .
			"state == 'approved'";
	$results = Events::load($where);
	$f3->set('events', $results);

	/* Feed posts */
	global $feeds;
	$results = $feeds->exec("SELECT *
		FROM post_info
		WHERE hidden IS NOT 1
		ORDER BY date DESC
		LIMIT 0, 10");

	foreach ($results as &$post) {
		$ts = strtotime($post['date']);

		$post['id'] = $post['id'];
		$post['time'] = strftime('%H:%M', $ts);
		$post['date'] = strftime('%a %e %B', $ts);
		$post['feed'] = array();
		$post['feed']['url'] = $post['feed_url'];
		$post['feed']['title'] = $post['title:1'];
		$post['feed']['site'] = $post['site_url'];
	}

	$f3->set('posts', $results);

	/* Serve it up! */
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
	$where = "startdt >= date('now', 'start of day') AND " .
			"startdt <= date('now', 'start of month', '+2 month', '-1 day') AND " .
			"state == 'approved'";
	$results = Events::load($where);
	$f3->set('events', $results);
		echo Template::instance()->render("events.html");
});

$f3->route('GET /events/unapproved', function($f3) {
	admin_check();
	$f3->set('events', Events::load("state == 'validated'"));
	$f3->set('admin', TRUE);
		echo Template::instance()->render("events.html");
});

$f3->route('POST /events/purge', function($f3) {
	admin_check();
	readonly_check();

	$m = intval($_POST['months']);
	DB::sql("DELETE FROM events WHERE startdt < date('now', '-".$m." months')");

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
		die;
	}

	// Check user isn't banned
	$r = Events::sql("SELECT banned FROM users" .
			" WHERE email=:email AND banned = 1",
			array(':email' => $event->email));
	if ($r) {
				echo Template::instance()->render("spam.html");
		var_dump($r);
		die;
	}

	$event->generate_key();
	$event->save();
	$event->send_confirm_mail();

	$f3->reroute("/?msg=Event+submitted.+Please+check+your+email.");
});

$f3->route('GET /event/@id', function($f3) {
	admin_check(FALSE);
	$id = intval($f3->get('PARAMS.id'));

	$event = new Event($id);
	$f3->set("event", $event);
	echo Template::instance()->render("event.html");
});

$f3->route('GET /event/@id/edit', function($f3) {
	admin_check();
	$id = intval($f3->get('PARAMS.id'));

	$event = new Event($id);
	$event->set_form_data();
	echo Template::instance()->render("event_add.html");
});

$f3->route('POST /event/@id/edit', function($f3) {
	admin_check();
	readonly_check();
	$id = intval($f3->get('PARAMS.id'));

	$event = new Event($id);
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
	if (!ctype_alnum($key))
		$id = "";

	$result = Events::sql("UPDATE events SET key=NULL, state=:state" .
			" WHERE key=:key", 
				array(':state' => "validated", ':key' => $key));

	if (!$result)
		$message = "No event to approve found!  Maybe you already approved it?";
	else
		$message = "Event submitted.  Please await approval :)";

	$f3->reroute("/?msg=" . urlencode($message));
});

$f3->route('POST /event/@id/approve', function($f3) {
	admin_check();
	readonly_check();
	$id = intval($f3->get('PARAMS.id'));

	DB::sql("UPDATE events SET key=NULL, state=:state WHERE id=:id", 
			array(':state' => "approved", ':id' => $id));

	if ($f3->get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
});

$f3->route('POST /event/@id/unapprove', function($f3) {
	admin_check();
	readonly_check();
	$id = intval($f3->get('PARAMS.id'));

	DB::sql("UPDATE events SET state=:state WHERE id=:id", 
			array(':state' => "validated", ':id' => $id));

	if ($f3->get('DB->result') == 0)
		echo "Failure";
	else
		echo "Unapproved";
});

$f3->route('POST /event/@id/approve', function($f3) {
	admin_check();
	readonly_check();
	$id = intval($f3->get('PARAMS.id'));

	$e = new Event($id);
	$e->state = "approved";
	$e->save();
	$e->send_approve_mail();
});

$f3->route('POST /event/@id/delete', function($f3) {
	admin_check();
	readonly_check();
	$id = intval($f3->get('PARAMS.id'));

	DB::sql("DELETE FROM events WHERE id=:id", array(':id' => $id));

	if ($f3->get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
});

/***********************/
/**** Posts in bulk ****/
/***********************/

$f3->route('POST /posts/purge', function($f3) {
	admin_check();
	readonly_check();

	$m = intval($_POST['months']);
	DB::sql("DELETE FROM posts WHERE date < date('now', '-".$m." months')", NULL, 0, 'feeds');

	$f3->reroute("/admin?msg=Purged.");
});

/***********************/
/**** Editing posts ****/
/***********************/

$f3->route('GET /post/edit', function($f3) {
	admin_check();
	readonly_check();

	DB::sql("SELECT id, title, summary FROM posts WHERE id=:id", array(":id" => $_GET['id']), 0, 'feeds');
	$r = $f3->get('feeds->result');
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

	DB::sql("UPDATE posts SET title=:title, summary=:summary WHERE id=:id", $values, 0, 'feeds');

	$f3->reroute("/?msg=Done.");
});

$f3->route('POST /post/hide', function($f3) {
	admin_check();
	readonly_check();

	$values = array(":id" => $_GET['id']);
	DB::sql("UPDATE posts SET hidden=1 WHERE id=:id", $values, 0, 'feeds');

	$f3->reroute("/?msg=Hidden!");
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

	DB::sql("SELECT * FROM feeds", NULL, 0, 'feeds');
	$f3->set('feeds', $f3->get('feeds->result'));
	echo Template::instance()->render("feeds.html");
});

$f3->route('POST /feeds/add', function($f3) {
	admin_check();
	readonly_check();

	require_once 'lib/simplepie_1.3.compiled.php';

	$feed = new SimplePie();
	$feed->set_feed_url($_POST['url']);
	$feed->enable_cache(false);
	$feed->init();
	$feed->handle_content_type();

	$values = array(
		':feed' => $feed->feed_url,
		':site' => $feed->get_link(),
		':title' => $feed->get_title()
	);

	DB::sql("INSERT OR IGNORE INTO feeds " .
			"(feed_url, site_url, title) VALUES (:feed, :site, :title)",
			$values, 0, 'feeds');

	if ($f3->get('DB->result') != 0)
		$message = "Failed to add feed to database.";
	else
		$message = "Feed added.";

	$f3->reroute("/feeds?msg=" . $message);
});

$f3->route('POST /feeds/edit', function($f3) {
	admin_check();
	readonly_check();

	for ($i = 1; $i <= count($_POST["feed_url"]); $i++) {
		if (isset($_POST["delete"][$i])) {
			$values = array(":feed_url" => $_POST["feed_url"][$i]);
			DB::sql("DELETE FROM feeds WHERE feed_url=:feed_url", $values, 0, 'feeds');
			DB::sql("DELETE FROM posts WHERE feed_url=:feed_url", $values, 0, 'feeds');

		} else {
			$values = array(
				":feed_url" => $_POST["feed_url"][$i],
				":site_url" => $_POST["site_url"][$i],
				":title" => $_POST["title"][$i]);

			DB::sql("UPDATE feeds SET site_url=:site_url, title=:title WHERE feed_url=:feed_url", $values, 0, 'feeds');
		}
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

	$r = Events::sql('SELECT count(*) AS count FROM events WHERE state="submitted"');
	$events_info['submitted'] = $r[0]['count'];

	$r = Events::sql('SELECT count(*) AS count FROM events WHERE state="validated"');
	$events_info['validated'] = $r[0]['count'];

	$r = Events::sql('SELECT count(*) AS count FROM events WHERE state="approved"');
	$events_info['approved'] = $r[0]['count'];

	$r = Events::sql('SELECT count(*) AS count FROM events WHERE state="approved" AND date < date("now", "start of day")');
	$events_info['old'] = $r[0]['count'];

	$f3->set("events", $events_info);

	$feed_info = array(
		"feeds" => 0,
		"posts" => 0,
		"old_posts" => 0
	);

	global $feeds;

	$r = $feeds->exec('SELECT count(*) AS count FROM feeds', NULL, 0, 'feeds');
	$feed_info['feeds'] = $r[0]['count'];

	$r = $feeds->exec('SELECT count(*) AS count FROM posts', NULL, 0, 'feeds');
	$feed_info['posts'] = $r[0]['count'];

	$r = $feeds->exec('SELECT count(*) AS count FROM posts WHERE date < date("now", "-1 month")');
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
	$result = Events::sql("SELECT * FROM users WHERE email=:email",
		array(':email' => $_POST['email']));

	/* No such user! */
	if (sizeof($result) == 0) {
		$f3->set('message', "FAIL.");
				echo Template::instance()->render("admin_login.html");
		exit();
	}

	/* Take the first row */
	$r = $result[0];

	/* Now take the salt and the user's input and compare it to the digest */
	if ($r['digest'] != hash("sha256", $r['salt'] . $_POST['password'])) {
		$f3->set('message', "FAIL.");
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


/**************************/
/**** iCalendar format ****/
/**************************/

$f3->route('GET /icalendar', function($f3) {
	$where = "date >= date('now', 'start of day') AND state == 'approved'";
	$f3->set('events', Events::load($where));
	echo Template::instance()->render("ical.txt");
});

$f3->run();

?>
