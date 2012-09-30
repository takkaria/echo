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

function reroute($where) {
	F3::reroute($where);
}

function admin_check() {
	// XXX fill this in
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

/* admin routing... 

/admin {

	if not logged in:
		silent redir /admin/login
	else:
		
}

/admin/login GET {
	display form
}

/admin/login POST {
	validate form
	check pass = "pw" & email = "em"
	if worked:
		create session
		session.loggedin = true
		redir /admin
}

*/

F3::run();

?>
