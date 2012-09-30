<?php

// Group events by day
F3::set('group_events', function() {
	$events = F3::get('events');
	$sorted = array();
	foreach ($events as $e) {
		$dt = clone $e->datetime;
		$sorted[$dt->modify("today")->format("Y-m-d")][] = $e;
	}
	return $sorted;
});

// Format a nice prettily for the events listing
F3::set('formatdate', function($date) {
	$today = new DateTime("today"); // This gets the beginning of the day
	$event = new DateTime($date);
	$format = 'l j F';	// This should be in the templates but for some reason F3 was screwing up with it

	$diff = intval($today->diff($event)->format('%R%a'));

	if ($diff < -1)
		return -$diff . " days ago";
	else if ($diff == -1)
		return "1 day ago";
	else if ($diff == 0)
		return "Today";
	else if ($diff == 1)
		return "Tomorrow";
	else
		return $event->format($format);
});

// Output 'value' attribute suitable for input tag if arg isn't null
F3::set('value', function($arg) {
	if ($arg)
		return 'value="' . $arg . '"';
});

// Check if URL is a Facebook URL
F3::set('facebook', function($url) {
	return strpos($url, "facebook.com") !== FALSE;
});

?>