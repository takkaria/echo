<?php
require_once 'lib/fatfree/lib/base.php';

date_default_timezone_set('UTC');
$options = parse_ini_file('echo.ini', true);

function group_assoc($array, $key) {
    $return = array();
    foreach ($array as $v) {
        $return[$v[$key]][] = $v;
    }
    return $return;
}

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

function send_confirm_email($to, $approved_hash) {
	global $options;

	/* Send confirm email */
	F3::set("approved_id", $approved_hash);
	$message = Template::serve('templates/event_confirm_mail.txt');

	$subject = $options['general']['name'] . ": Please confirm your event";
	$headers = "From: " . $options['general']['email'];
	mail($to, $subject, $message, $headers);
}

function reroute($where) {
	F3::reroute($where);
}

/* ---------- */

F3::set('DB', new DB("sqlite:" . $options['db']['events']));
F3::set('feeds', new DB("sqlite:" . $options['db']['feeds']));

F3::route('GET /', function() {

	/* Events */
	DB::sql("SELECT *
		FROM events
		WHERE date >= date('now', 'start of day') AND
				date <= date('now', 'start of day', '+7 days') AND
				approved == 0
		ORDER BY date");

	$results = F3::get('DB->result');
	foreach ($results as &$event) {
		$ts = strtotime($event['date']);

		$event['timestamp'] = $ts;
		$event['time'] = strftime('%H:%M', $ts);
		$event['date'] = strftime('%A %e %B', $ts);
	}

	$sorted = group_assoc($results, "date");
	F3::set('events', $sorted);

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
	F3::set("title", "Echo");
	echo Template::serve("templates/index.html");
});

F3::route('GET /event_add', function() {
	F3::set("title", "Add an event");
	echo Template::serve("templates/event_add.html");
});

F3::route('POST /event_add', function() {
	spam_check();

	$messages = array();
	$date = NULL;

	F3::input('title', function($value) use(&$messages) {
		if (strlen($value) < 3)
			$messages[] = "Title too short.";
		else if (strlen($value) > 140)
			$messages[] = "Title too long.";
	});

	F3::input('date', function($value) use(&$messages, &$date) {
		$date = DateTime::createFromFormat("j-m-Y", $value);
		if (!$date)
			$messages[] = "Invalid date.";
	});

	F3::input('time', function($value) use(&$messages, &$date) {
		$time = date_parse_from_format("H:i", $value);
		if ($time['error_count'] > 0)
			$messages[] = "Invalid time.";
		if ($date)
			$date->setTime($time['hour'], $time['minute']);
	});

	F3::input('email', function($value) use(&$messages) {
		if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
			$messages[] = "Invalid email address";
		}
	});

	/* XXX need to make sure location and blurb are provided */

	if (count($messages) > 0) {
		F3::set("title", "Add an event");
		F3::set('POST', F3::scrub($_POST));
		F3::set('messages', $messages);
		echo Template::serve("templates/event_add.html");
	} else {
		/* XXX should check the event hasn't already been saved */

		/* Make event to save */
		$event = new Axon('events');
		$event->title = $_POST['title'];
		$event->date = $date->format("Y-m-d H:i");
		$event->location = $_POST['location'];
		$event->blurb = $_POST['blurb'];
		$event->email = $_POST['email'];
		$event->approved = md5($event->email . rand());

		/* Find the user record */
		$user = new Axon('users');
		$user->load('email="' . F3::get('REQUEST.email') . '"'); /* XXX potential injection attack */

		if (!$user->dry() && $user->banned) {
			Template::serve("templates/spam.html");
			die;
		}

		$event->save();

		send_confirm_email($event->email, $event->approved);
		reroute("/");
	}

});

F3::route('GET /c/@id', function() {
	F3::set("title", "Confirm event");

	$id = F3::get('PARAMS.id');
	if (!ctype_alnum($id))
		$id = "";

	DB::sql("UPDATE events SET approved=0 WHERE approved=:id", 
			array(':id' => $id));

	/* XXX find some way to relay to main page */
	if (F3::get('DB->result') == 0)
		$message = "No event to approve found!  Maybe you already approved it?";
	else
		$message = "Fail!";

	reroute("/");
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
