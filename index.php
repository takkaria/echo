<?php
require_once 'fatfree/lib/base.php';

date_default_timezone_set('UTC');
function group_assoc($array, $key)
{
    $return = array();
    foreach ($array as $v) {
        $return[$v[$key]][] = $v;
    }
    return $return;
}

/* ---------- */

F3::set('DB', new DB("sqlite:events.sqlite"));

F3::route('GET /', function() {

	DB::sql("SELECT *
		FROM events
		WHERE date >= date('now', 'start of day') AND
				date <= date('now', 'start of day', '+7 days')
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

	F3::set("title", "Echo");
	echo Template::serve("templates/index.html");
});

F3::route('GET /event_add', function() {
	F3::set("title", "Add an event");
	echo Template::serve("templates/event_add.html");
});

F3::route('POST /event_add', function() {

	$messages = array();
	$parsed_date = NULL;
	$parsed_time = NULL;

	/* XXX try spam filtering logic here? */

	F3::input('title', function($value) use(&$messages) {
		if (strlen($value) < 3)
			$messages[] = "Title too short.";
		else if (strlen($value) > 140)
			$messages[] = "Title too long.";
	});

	F3::input('date', function($value) use(&$messages, &$parsed_date) {
		$parsed_date = strptime($value, "%d-%m-%Y");
		if (!$parsed_date)
			$messages[] = "Invalid date.";
	});

	F3::input('time', function($value) use(&$messages, &$parsed_time) {
		$parsed_time = strptime($value, "%H:%M");
		if (!$parsed_time)
			$messages[] = "Invalid time.";
	});

	F3::input('email', function($value) use(&$messages) {
		if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
			$messages[] = "Invalid email address";
		}
	});

	if (count($messages) > 0) {
		F3::set("title", "Add an event");
		F3::set('POST', F3::scrub($_POST));
		F3::set('messages', $messages);
		echo Template::serve("templates/event_add.html");
	} else {
		/* Add the time to the already-established date */
		$parsed_date['tm_min'] = $parsed_time['tm_min'];
		$parsed_date['tm_hour'] = $parsed_time['tm_hour'];

		/* Find the user record */
		$user = new Axon('users');
		$user->load('email="' . $_POST['email'] . '"'); /* XXX potential injection attack */

		if ($user->dry()) {
			/* send email to confirm, save event */
		} else if ($user->banned) {
			/* reject request */
		}

		/* save event */

		if ($user->approved) {
			/* set event to approved straight away */
		}
	}

/* CREATE TABLE events
(
	id INT auto_increment PRIMARY KEY,
	
	title TEXT,
	date DATETIME,
	location TEXT,
	blurb TEXT,
	facebook_id INT,
	url_info TEXT,
	
	submitter_email TEXT,
	approved BOOLEAN
); */

	}
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
