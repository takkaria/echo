<?php
/************************************
 * Change this to where the ini file and DB folder are kept.
 ************************************/
define("BASEPATH", "");

require_once BASEPATH . 'lib/fatfree/lib/base.php';

// Disable this when in production
F3::set('DEBUG', 3);


F3::set("UI", BASEPATH . "templates/");

/**** Initialise ****/

require_once BASEPATH . 'lib/Venue.php';
require_once BASEPATH . 'lib/Event.php';
require_once BASEPATH . 'lib/template_utils.php';

date_default_timezone_set('Europe/London');
$options = parse_ini_file(BASEPATH . 'doormat.ini', true);

define("READONLY", $options['db']['readonly']);
F3::set('DB', new DB("sqlite:" . BASEPATH . $options['db']['events']));
F3::set('feeds', new DB("sqlite:" . BASEPATH . $options['db']['feeds']));
if (isset($_GET['msg']))
	F3::set('message', strip_tags($_GET['msg']));
F3::set('baseurl', $options['web']['echo_root']);
F3::set('appname', $options['general']['name']);
F3::set('readonly', READONLY);

function spam_check() {
	global $options;

	$blocklist = $options['spam']['blocklist'];
	$addr = F3::realip();
	$quad = implode('.', array_reverse(explode('.',$addr)));

	foreach ($blocklist as $list) {
		// Check against DNS blacklist
		if (gethostbyname($quad.'.'.$list) != $quad.'.'.$list) {
			Template::serve("spam.html");
			die;
		}
	}
}

// F3 only lets us do temporary re-routing when submitting a form using POST, so here is alternative logic...
function reroute($where) {
	global $options;

	F3::status(303);
	if (session_id())
		session_commit();
	header('Location: ' . $options['web']['echo_root'] . $where);
	die;
}

function admin_check($reroute = TRUE) {
	session_start();
	if (!isset($_SESSION['admin'])) {
		if ($reroute) {
			reroute("/admin/login");
			exit();
		}
	} else {
		F3::set("admin", TRUE);
	}
}

function readonly_check() {
	if (READONLY) {
		reroute("/?msg=Sorry,+can't+do+that.+Database+in+read-only+mode.");
		die;
	}
}

/********************/
/**** Front page ****/
/********************/

F3::route('GET /', function() {
	admin_check(FALSE);

	/* Events */
	$where = "date >= date('now', 'start of day') AND " .
			"date <= date('now', 'start of day', '+14 days') AND " .
			"state == 'approved'";
	$results = Event::load($where);
	F3::set('events', $results);

	/* Feed posts */
	DB::sql("SELECT *
		FROM post_info
		ORDER BY date DESC
		LIMIT 0, 10", NULL, 0, 'feeds');

	$results = F3::get('feeds->result');
	foreach ($results as &$post) {
		$ts = strtotime($post['date']);

		$post['time'] = strftime('%H:%M', $ts);
		$post['date'] = strftime('%a %e %B', $ts);
		$post['feed'] = array();
		$post['feed']['url'] = $post['feed_url'];
		$post['feed']['title'] = $post['title:1'];
		$post['feed']['site'] = $post['site_url'];
	}

	F3::set('posts', $results);

	/* Serve it up! */
	echo Template::serve("index.html");
});

/* XXX need to work out a better way to do this that is generic, no time now though */
/* maybe a var 'passthrough' in the ini which just makes pages listed in it serve up <page>.html */
F3::route('GET /about', function() {
	echo Template::serve("about.html");
});

/***************************/
/**** Displaying events ****/
/***************************/

F3::route('GET /events', function() {

	/* Events */
	$where = "date >= date('now', 'start of day') AND " .
			"date <= date('now', 'start of month', '+2 month', '-1 day') AND " .
			"state == 'approved'";
	$results = Event::load($where);
	F3::set('events', $results);
	echo Template::serve("events.html");
});

F3::route('GET /events/unapproved', function() {
	admin_check();
	F3::set('events', Event::load("state == 'validated'"));
	F3::set('admin', TRUE);
	echo Template::serve("events_unapproved.html");
});

/***************************/
/**** Adding new events ****/
/***************************/

F3::route('GET /event/add', function() {
	echo Template::serve("event_add.html");
});

F3::route('POST /event/add', function() {
	spam_check();
	readonly_check();

	$event = new Event();
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		set_event_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("event_add.html");
		die;
	}

	// Check user isn't banned
	DB::sql("SELECT banned FROM users WHERE email=:email", 
		array(':email' => $event->email));
	if (F3::get("DB->results")) {
		echo Template::serve("spam.html");
		die;
	}

	$event->generate_key();
	$event->save();
	$event->send_confirm_mail();

	reroute("/?msg=Event+submitted.+Please+check+your+email.");
});

/*********************************/
/**** Editing existing events ****/
/*********************************/

F3::route('GET /event/@id', function() {
	admin_check(FALSE);
	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	F3::set("event", $event);
	echo Template::serve("event.html");
});

F3::route('GET /event/@id/edit', function() {
	admin_check();
	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	set_event_data_from_Event($event);
	echo Template::serve("event_add.html");
});

F3::route('POST /event/@id/edit', function() {
	admin_check();
	readonly_check();
	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		set_event_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("event_add.html");
	} else {
		$event->save();
		reroute("/event/" . $id . "/edit?msg=Event%20saved.");
	}
});

F3::route('GET /c/@key', function() {
	$key = F3::get('PARAMS.key');
	if (!ctype_alnum($key))
		$id = "";

	DB::sql("UPDATE events SET key=NULL, state=:state WHERE key=:key", 
			array(':state' => "validated", ':key' => $key));

	if (F3::get('DB->result') == 0)
		$message = "No event to approve found!  Maybe you already approved it?";
	else
		$message = "Event submitted.  Please await approval :)";

	reroute("/?msg=" . urlencode($message));
});

F3::route('POST /event/@id/approve', function() {
	admin_check();
	readonly_check();
	$id = intval(F3::get('PARAMS.id'));

	DB::sql("UPDATE events SET key=NULL, state=:state WHERE id=:id", 
			array(':state' => "approved", ':id' => $id));

	if (F3::get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
});

F3::route('POST /event/@id/unapprove', function() {
	admin_check();
	readonly_check();
	$id = intval(F3::get('PARAMS.id'));

	DB::sql("UPDATE events SET state=:state WHERE id=:id", 
			array(':state' => "validated", ':id' => $id));

	if (F3::get('DB->result') == 0)
		echo "Failure";
	else
		echo "Unapproved";
});

F3::route('POST /event/@id/approve', function() {
	admin_check();
	readonly_check();
	$id = intval(F3::get('PARAMS.id'));

	$e = new Event($id);
	$e->state = "approved";
	$e->save();
	$e->send_approve_mail();
});

F3::route('POST /event/@id/delete', function() {
	admin_check();
	readonly_check();
	$id = intval(F3::get('PARAMS.id'));

	DB::sql("DELETE FROM events WHERE id=:id", array(':id' => $id));

	if (F3::get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
});

/****************/
/**** Venues ****/
/****************/

F3::route('GET /venue/@id', function() {
	$v = new Venue(intval(F3::get('PARAMS.id')));
	F3::set("venue", $v);
	echo Template::serve("venue.html");
});

F3::route('GET /venue/add', function() {
	readonly_check();
	echo Template::serve("venue_add.html");
});

F3::route('POST /venue/add', function() {
	readonly_check();
	admin_check();

	$venue = new Venue();
	$messages = $venue->parse_form_data();

	if (count($messages) > 0) {
		set_venue_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("venue_add.html");
		die;
	}

	$venue->save();

	reroute("/?msg=Venue+added.");
});



/********************************/
/**** Feed display & editing ****/
/********************************/

F3::route('GET /feeds', function() {
	admin_check();

	DB::sql("SELECT * FROM feeds", NULL, 0, 'feeds');

	$results = F3::get('feeds->result');
//	foreach ($results as &$feeds) {
//	}
	F3::set('feeds', $results);

	echo Template::serve("feeds.html");	
});

F3::route('POST /feeds/add', function() {
	admin_check();
	readonly_check();

	require_once 'lib/simplepie_1.3.compiled.php';

	$feed = new SimplePie();
	$feed->set_feed_url($_POST['url']);
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

	if (F3::get('DB->result') != 0)
		$message = "Failed to add feed to database.";
	else
		$message = "Feed added.";

	reroute("/feeds?msg=" . $message);
});

/******************************/
/**** Admin login & logout ****/
/******************************/

F3::route('GET /admin', function() {
	admin_check();

	/** Retrieve event info **/
	$events_info = array(
		"submitted" => 0,
		"validated" => 0,
		"approved" => 0
	);

	DB::sql('SELECT count(*) AS count FROM events WHERE state="submitted"');
	$r = F3::get('DB->result');
	$events_info['submitted'] = $r[0]['count'];

	DB::sql('SELECT count(*) AS count FROM events WHERE state="validated"');
	$r = F3::get('DB->result');
	$events_info['validated'] = $r[0]['count'];

	DB::sql('SELECT count(*) AS count FROM events WHERE state="approved"');
	$r = F3::get('DB->result');
	$events_info['approved'] = $r[0]['count'];

	F3::set("events", $events_info);

	$feed_info = array(
		"feeds" => 0,
		"posts" => 0,
		"old_posts" => 0
	);

	DB::sql('SELECT count(*) AS count FROM feeds', NULL, 0, 'feeds');
	$r = F3::get('feeds->result');
	$feed_info['feeds'] = $r[0]['count'];

	DB::sql('SELECT count(*) AS count FROM posts', NULL, 0, 'feeds');
	$r = F3::get('feeds->result');
	$feed_info['posts'] = $r[0]['count'];

	DB::sql('SELECT count(*) AS count FROM posts WHERE date < date("now", "-1 month")', NULL, 0, 'feeds');
	$r = F3::get('feeds->result');
	$feed_info['old_posts'] = $r[0]['count'];

	F3::set("feeds", $feed_info);

	/** Serve it up! **/
	echo Template::serve("admin.html");
});

F3::route('GET /admin/login', function() {
	echo Template::serve("admin_login.html");
});

F3::route('POST /admin/login', function() {

	// XXX do the other stuff mentioned in this article:
	// http://throwingfire.com/storing-passwords-securely/
	// and perhaps add in nth character of a passphrase type stuff

	/* Get the salt */
	DB::sql("SELECT * FROM users WHERE email=:email",
		array(':email' => $_POST['email']));

	/* No such user! */
	if (sizeof(F3::get('DB->result')) == 0) {
		F3::set('message', "FAIL.");
		echo Template::serve("admin_login.html");
		exit();
	}

	$r = F3::get("DB->result");
	$r = $r[0];

	/* Now take the salt and the user's input and compare it to the digest */
	if ($r['digest'] != hash("sha256", $r['salt'] . $_POST['password'])) {
		F3::set('message', "FAIL.");
		echo Template::serve("admin_login.html");
		exit();	
	}

	// We're in!
	session_start();
	$_SESSION['admin'] = TRUE;
	$_SESSION['email'] = $r['email'];
	session_commit();

	reroute("/admin");
});

F3::route('GET /admin/logout', function() {

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

	reroute("/");
});


/**************************/
/**** iCalendar format ****/
/**************************/

F3::route('GET /icalendar', function() {
	$where = "date >= date('now', 'start of day') AND state == 'approved'";
	$results = Event::load($where);
	F3::set('events', $results);
	echo Template::serve("ical.txt");
});


F3::run();

?>
