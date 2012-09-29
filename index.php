<?php
require_once 'lib/fatfree/lib/base.php';

F3::set('DEBUG', 3);

date_default_timezone_set('UTC');
$options = parse_ini_file('echo.ini', true);

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

class Event {
	public $id;
	public $title;
	public $datetime;
	public $location;
	public $blurb;
	public $url;
	public $free;
	public $film;

	public $email;
	public $key;
	public $state;

	function __construct($id = NULL) {
		if ($id) {
			DB::sql("SELECT * FROM events WHERE id=" . $id . " ORDER BY date");
			$r = F3::get('DB->result');

			// Get the first result
			$r = $r[0];

			$this->id = $r['id'];
			$this->title = $r['title'];
			$this->datetime =
					DateTime::createFromFormat("Y-m-j H:i", $r['date']);
			$this->location = $r['location'];
			$this->blurb = $r['blurb'];
			$this->url = $r['url'];
			$this->free = $r['free'] ? TRUE : FALSE;
			$this->film = $r['type'] == "film" ? TRUE : FALSE;

			$this->state = $r['state'];
			$this->email = $r['email'];
			$this->key = $r['key'];
		}
	}

	static function load($where) {
		$events = array();

		DB::sql("SELECT id FROM events WHERE " . $where . " ORDER BY date");
		$r = F3::get('DB->result');
		foreach ($r as $row) {
			$events[] = new Event($row['id']);
		}

		return $events;
	}
	
	public function parse_form_data() {
		$messages = array();
		$self = $this;

		F3::input('title', function($value) use(&$self, &$messages) {
			if (strlen($value) < 3)
				$messages[] = "Title too short.";
			else if (strlen($value) > 140)
				$messages[] = "Title too long.";

			// XXX de-capitalise

			$self->title = $value;
		});
	
		F3::input('date', function($value) use(&$self, &$messages, &$date) {
			$self->datetime = DateTime::createFromFormat("Y-m-j", $value);
			if (!$self->datetime)
				$messages[] = "Invalid date.";
		});
	
		F3::input('time', function($value) use(&$self, &$messages, &$date) {
			$time = date_parse_from_format("H:i", $value);
			if ($time['error_count'] > 0)
				$messages[] = "Invalid time.";
			if ($self->datetime)
				$self->datetime->setTime($time['hour'], $time['minute']);
		});
	
		F3::input('email', function($value) use(&$self, &$messages) {
			if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$messages[] = "Invalid email address";
			}
			$self->email = $value;
		});
	
		/* XXX need to make sure location and blurb are provided */
		/* XXX validate these */
		$this->location = $_POST['location'];
		$this->blurb = $_POST['blurb'];
		$this->url = $_POST['url'];

		$this->free = isset($_POST['free']) ? TRUE : FALSE;
		$this->film = isset($_POST['film']) ? TRUE : FALSE;

		return $messages;
	}

	public function generate_key() {
		$this->key = md5($this->email . rand());
	}

	public function send_confirm_mail() {
		global $options;

		F3::set("approved_id", $this->key);
		$message = Template::serve('templates/event_confirm_mail.txt');
	
		$subject = $options['general']['name'] . ": Please confirm your event";
		$headers = "From: " . $options['general']['email'];
		mail($this->email, $subject, $message, $headers);
	}

	public function save() {
		$e = new Axon('events');

		if ($this->id)
			$e->load('id=' . $this->id);

		$e->id = $this->id;
		$e->title = $this->title;
		$e->date = $this->datetime->format("Y-m-d H:i");
		$e->location = $this->location;
		$e->blurb = $this->blurb;
		$e->url = $this->url;
		$e->free = $this->free ? 1 : 0;
		if ($this->film)
			$e->type = "film";

		$e->email = $this->email;
		$e->key = $this->key;
		$e->state = $this->state;

		return $e->save();
	}
}

function set_event_data_from_POST() {
	F3::set('title', F3::scrub($_POST['title']));
	F3::set('location', F3::scrub($_POST['location']));
	F3::set('date', F3::scrub($_POST['date']));
	F3::set('time', F3::scrub($_POST['time']));
	F3::set('blurb', F3::scrub($_POST['blurb']));
	F3::set('url', F3::scrub($_POST['url']));
	F3::set('free', isset($_POST['free']) ? TRUE : FALSE);
	F3::set('film', isset($_POST['film']) ? TRUE : FALSE);
	F3::set('email', F3::scrub($_POST['email']));
}

function set_event_data_from_Event($event) {
	F3::set('title', $event->title);
	F3::set('location', $event->location);
	F3::set('date', $event->datetime->format("Y-m-d"));
	F3::set('time', $event->datetime->format("H:i"));
	F3::set('blurb', $event->blurb);
	F3::set('url', $event->url);
	F3::set('free', $event->free);
	F3::set('film', $event->film);
	F3::set('email', $event->email);
}

/* ---------- */

F3::set('group_events', function() {
	$events = F3::get('events');
	$sorted = array();
	foreach ($events as $e) {
		$dt = clone $e->datetime;
		$sorted[$dt->modify("today")->format("Y-m-d")][] = $e;
	}
	return $sorted;
});

F3::set('formatdate', function($date) {
	$today = new DateTime("today"); // This gets the beginning of the day
	$event = new DateTime($date);
	$format = 'l j F';	// This should be in the templates but for some reason F3 was screwing up with it

	$diff = intval($today->diff($event)->format('%a'));

	if ($diff == 0)
		return "Today";
	if ($diff == 1)
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

/* ---------- */

F3::set('DB', new DB("sqlite:" . $options['db']['events']));
F3::set('feeds', new DB("sqlite:" . $options['db']['feeds']));
if (isset($_GET['msg']))
	F3::set('message', strip_tags($_GET['msg']));
F3::set('baseurl', $options['web']['echo_root']);

F3::route('GET /', function() {

	/* Events */
	$where = "date >= date('now', 'start of day') AND " .
			"date <= date('now', 'start of day', '+14 days') AND " .
			"state <> 'submitted'";
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

F3::route('GET /event/@id', function() {
	admin_check();

	$event = new Event(intval(F3::get('PARAMS.id')));
	set_event_data_from_Event($event);
	echo Template::serve("templates/event_add.html");
});

F3::route('POST /event/@id', function() {
	admin_check();
	spam_check();

	$id = intval(F3::get('PARAMS.id'));

	$event = new Event($id);
	$messages = $event->parse_form_data();

	if (count($messages) > 0) {
		set_event_data_from_POST();
		F3::set('messages', $messages);
		echo Template::serve("templates/event_add.html");
	} else {
		/* Load event, modify it, then save it */
		$event->save();
		reroute("/event/" . $id . "?msg=Event%20saved.");
	}
});

F3::route('GET /events', function() {

	$when = F3::get('PARAMS.when');

	// 'when' will look like ''

	/* Events */
	$where = "date >= date('now', 'start of month', '+1 month') AND " .
			"date <= date('now', '+2 months', '-1 day') AND " .
			"approved == 0";
	$results = Event::load($where);
	F3::set('events', $results);
	echo Template::serve("templates/events.html");
});

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
