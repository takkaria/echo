<?php

// Group events by day
$f3->set('group_events', function() {
	global $f3;
	$events = $f3->get('events');
	$bydate = array();
	foreach ($events as $e) {
		$dt = clone $e->startdt;
		$dtstamp = $dt->modify("today")->format("Y-m-d");
		$bydate[$dtstamp][] = $e;
	}

	$final = array();
	foreach ($bydate as $dtstamp => $list) {
		if (new DateTime($dtstamp) < new DateTime("today")) {
			$final['Ongoing'] = $list;
		} else {
			$final[$dtstamp] = $list;
		}
	}
	return $final;
});

// Format a nice prettily for the events listing
$f3->set('formatdate', function($date) {
	if ($date == 'Ongoing')
		return $date;

	$today = new DateTime("today"); // This gets the beginning of the day
	$event = new DateTime($date);
	$format = 'l j F';	// This should be in the templates but for some reason F3 was screwing up with it

	$diff = intval($today->diff($event)->format('%R%a'));

	if ($diff < -35) { // 5 weeks
		$n = intval(-$diff/31);
		if ($n == 1)
			return "a month ago";
		else
			return $n . " months ago";
	} else if ($diff < -7) { // 1 week
		$float = -$diff/7.0;
		$n = intval($float);
		if ($n == 1 && $float - $n > 0.5)
			return "a week and a bit ago";
		else if ($n == 1)
			return "a week ago";
		else
			return $n . " weeks ago";
	} else if ($diff < -1)
		return -$diff . " days ago";
	else if ($diff == -1)
		return "a day ago";
	else if ($diff == 0)
		return "Today";
	else if ($diff == 1)
		return "Tomorrow";
	else
		return $event->format($format);
});

// Output 'value' attribute suitable for input tag if arg isn't null
$f3->set('value', function($arg) {
	if ($arg)
		return 'value="' . $arg . '"';
});

// Check if URL is a Facebook URL
$f3->set('facebook', function($url) {
	return strpos($url, "facebook.com") !== FALSE;
});

// Convert commas into brs
$f3->set('comma2br', function($text) {
	return preg_replace('/, /', '<br>', $text);
});

// Convert commas into brs
$f3->set('get_venues', function() {
	return Events::$db->exec("SELECT name FROM venues");
});

?>
