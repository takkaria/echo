<?php
require_once 'lib/fatfree/lib/base.php';

// Disable this when in production
F3::set('DEBUG', 3);

/**** Initialise ****/

require_once 'lib/Event.php';
require_once 'lib/template_utils.php';

date_default_timezone_set('UTC');
$options = parse_ini_file('echo.ini', true);

F3::set('DB', new DB("sqlite:" . $options['db']['events']));
F3::set('feeds', new DB("sqlite:" . $options['db']['feeds']));
if (isset($_GET['msg']))
	F3::set('message', strip_tags($_GET['msg']));
F3::set('baseurl', $options['web']['echo_root']);

function spam_check() {
	global $options;

	$blocklist = $options['spam']['blocklist'];
	$addr = F3::realip();
	$quad = implode('.', array_reverse(explode('.',$addr)));

	foreach ($blocklist as $list) {
		// Check against DNS blacklist
		if (gethostbyname($quad.'.'.$list) != $quad.'.'.$list) {
			Template::serve("templates/spam.html");
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

function admin_check() {
	session_start();
	if (!isset($_SESSION['admin'])) {
		reroute("/admin/login");
		exit();
	}

	F3::set("admin", TRUE);
}


/********************/
/**** Front page ****/
/********************/

F3::route('GET /', function() {

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
	echo Template::serve("templates/index.html");
});

/***************************/
/**** Displaying events ****/
/***************************/

F3::route('GET /events', function() {

	/* Events */
	$where = "date >= date('now', 'start of month', '+1 month') AND " .
			"date <= date('now', '+2 months', '-1 day') AND " .
			"approved == 0";
	$results = Event::load($where);
	F3::set('events', $results);
	echo Template::serve("templates/events.html");
});

F3::route('GET /events/unapproved', function() {
	admin_check();
	F3::set('events', Event::load("state == 'validated'"));
	F3::set('admin', TRUE);
	echo Template::serve("templates/events_unapproved.html");
});

/***************************/
/**** Adding new events ****/
/***************************/

F3::route('GET /event/add', function() {
	echo Template::serve("templates/event_add.html");
});

F3::route('POST /event/add', function() {
	spam_check();

	$event = new Event();
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		F3::set("title", "Add an event");
		set_event_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("templates/event_add.html");
	} else {
		/* Find the user record */
		$user = new Axon('users');
		$user->load('email="' . F3::get('REQUEST.email') . '"'); /* XXX potential injection attack */

		if (!$user->dry() && $user->banned) {
			Template::serve("templates/spam.html");
			die;
		}

		/* XXX should check the event hasn't already been saved */
		/* Make event to save */
		$event->generate_key();
		$event->save();
		$event->send_confirm_mail();

		reroute("/?msg=Event+submitted.+Please+check+your+email.");
	}
});

/*********************************/
/**** Editing existing events ****/
/*********************************/

F3::route('GET /event/@id', function() {
	admin_check();
	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	set_event_data_from_Event($event);
	echo Template::serve("templates/event_add.html");
});

F3::route('POST /event/@id', function() {
	admin_check();
	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		set_event_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("templates/event_add.html");
	} else {
		$event->save();
		reroute("/event/" . $id . "?msg=Event%20saved.");
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
		$message = "Event approved :)";

	reroute("/?msg=" . urlencode($message));
});

F3::route('POST /event/@id/approve', function() {
	admin_check();
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
	$id = intval(F3::get('PARAMS.id'));

	DB::sql("UPDATE events SET key=NULL, state=:state WHERE id=:id", 
			array(':state' => "approved", ':id' => $id));

	if (F3::get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
});

F3::route('POST /event/@id/delete', function() {
	admin_check();
	$id = intval(F3::get('PARAMS.id'));

	DB::sql("DELETE FROM events WHERE id=:id", array(':id' => $id));

	if (F3::get('DB->result') == 0)
		echo "Failure";
	else
		echo "Approved";
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

	echo Template::serve("templates/feeds.html");	
});

F3::route('POST /feeds/add', function() {
	admin_check();

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

	// Show some kind of dashboard?
});

F3::route('GET /admin/login', function() {
	echo 'helo?';
	echo Template::serve("templates/admin_login.html");
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
		echo Template::serve("templates/admin_login.html");
		exit();
	}

	$r = F3::get("DB->result");
	$r = $r[0];

	/* Now take the salt and the user's input and compare it to the digest */
	if ($r['digest'] != hash("sha256", $r['salt'] . $_POST['password'])) {
		F3::set('message', "FAIL.");
		echo Template::serve("templates/admin_login.html");
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

F3::run();

?>
