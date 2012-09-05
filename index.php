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

function spam_check()
{
	$list = "dnsbl.sorbs.net; xbl.spamhaus.org; ubl.lashback.com";
	$addr = F3::realip();
	$quad = implode('.', array_reverse(explode('.',$addr)));

	foreach (F3::split($blocklists) as $list) {
		// Check against DNS blacklist
		if (gethostbyname($quad.'.'.$list) != $quad.'.'.$list) {
			Template::serve("templates/spam.html");
			die;
		}
	}
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
		$event->title = F3::get('REQUEST.title');
		$event->date = $date->format("Y-m-d H:i");
		$event->location = $_POST['location'];
		$event->blurb = $_POST['blurb'];
		$event->submitter_email = F3::get('REQUEST.email');

		/* Find the user record */
		$user = new Axon('users');
		$user->load('email="' . F3::get('REQUEST.email') . '"'); /* XXX potential injection attack */

		if ($user->dry()) {
			$user->email = F3::get('REQUEST.email');
			$user->validated = 0;
			$user->approved = 0;
			$user->banned = 0;
			$user->save();

			/* XXX Now send email to confirm... */
		}

		if ($user->approved)
			$event->approved = 1;

		if (!$user->banned)
			$event->save();

		F3::reroute("/../echo");
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
