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

/*

F3::route('POST /event_add', function() {
	validate fields
	if not valid:
		throw back to user

	else:
		user = user record from email

		if user.banned:
			reject outright
		if user.approved:
			validate straight away
		if not user.validated:
			send email asking to confirm email address
}); */

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
