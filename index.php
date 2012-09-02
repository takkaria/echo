<?php
require_once 'fatfree/lib/base.php';

F3::set('DB', new DB("sqlite:events.sqlite"));

F3::route('GET /', function() {

	F3::set("title", "Echo");

	DB::sql("SELECT *, strftime('%H:%M', date) AS time FROM events
		WHERE date >= date('now', 'start of day') AND
				date <= date('now', 'start of day', '+7 days')
		ORDER BY date");
	echo Template::serve("templates/index.html");
});

/* F3::route('GET /event_add', function() {
	display form
});

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

F3::run();

?>
