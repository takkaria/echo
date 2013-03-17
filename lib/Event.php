<?php

class Event {
	public $id;
	public $title;
	public $startdt;
	public $enddt;
	public $location;
	public $blurb;
	public $url;
	public $cost;
	public $film;

	public $email;
	public $key;
	public $state;

	function __construct($id = NULL) {
		if ($id) {
			DB::sql("SELECT * FROM events WHERE id=" . $id . " ORDER BY date");
			$r = F3::get('DB->result');

			// XXX error-check

			// Get the first result
			$r = $r[0];

			$this->id = $r['id'];
			$this->title = $r['title'];
			$this->startdt =
					DateTime::createFromFormat("Y-m-j H:i", $r['startdt']);
			$this->enddt =
					DateTime::createFromFormat("Y-m-j H:i", $r['enddt']);
			$this->location = new Venue($r['location']);
			$this->blurb = $r['blurb'];
			$this->url = $r['url'];
			$this->cost = $r['cost'];
			$this->film = $r['type'] == "film" ? TRUE : FALSE;

			$this->state = $r['state'];
			$this->email = $r['email'];
			$this->key = $r['key'];
		}
	}

	static function load($where) {
		$events = array();

		DB::sql("SELECT id FROM events WHERE " . $where . " ORDER BY startdt");
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

			$self->title = $value;
		});
	
		F3::input('date1', function($value) use(&$self, &$messages) {
			$self->startdt = DateTime::createFromFormat("l j F", $value);
			if (!$self->startdt)
				$messages[] = "Invalid start date.";
		});
	
		F3::input('time1', function($value) use(&$self, &$messages) {
			$time = date_parse_from_format("H:i", $value);
			if ($time['error_count'] > 0)
				$messages[] = "Invalid start time.";
			if ($self->startdt)
				$self->startdt->setTime($time['hour'], $time['minute']);
		});

		if (isset($_POST['date2'])) {
			F3::input('date2', function($value) use(&$self, &$messages) {
				$self->enddt = DateTime::createFromFormat("l j F", $value);
				if (!$self->enddt)
					$messages[] = "Invalid end date.";
			});
		}	

		if (isset($_POST['time2'])) {
			F3::input('time2', function($value) use(&$self, &$messages) {
				$time = date_parse_from_format("H:i", $value);
				if ($time['error_count'] > 0) {
					$messages[] = "Invalid end time.";
				} else if ($self->enddt) {
					$self->enddt->setTime($time['hour'], $time['minute']);
				}
			});
		}

		if (isset($_POST['date2']) != isset($_POST['time2'])) {
			$messages[] = "Only one of end date / end time given.";
		}

		F3::input('email', function($value) use(&$self, &$messages) {
			if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$messages[] = "Invalid email address";
			}
			$self->email = $value;
		});

		F3::input('url', function($url) use(&$self, &$messages) {
			if (!$url || $url == "") {
				$self->url = NULL;
			} else {
				if (!preg_match('/^[a-zA-Z+]:/', $url))
					$url = "http://" . $url;
				if (!filter_var($url, FILTER_VALIDATE_URL))
					$messages[] = "Invalid web address";
			}
			$self->url = $url;
		});

		F3::input('location', function($value) use(&$self, &$messages) {
			$value = F3::scrub($value);
			if (strlen($value) < 3)
				$messages[] = "Location too short.";

			$self->location = new Venue($value);
		});

		F3::input('blurb', function($blurb) use(&$self, &$messages) {
			$blurb = F3::scrub($blurb);
			if (strlen($blurb) < 20)
				$messages[] = "Description too short.";
			$self->blurb = $blurb;
		});

		if (isset($_POST['free']) && $_POST['free'] == 'free') {
			$this->cost = NULL;
		} else {
			$this->cost = F3::scrub($_POST['cost']);
		}

		$this->film = isset($_POST['film']) ? TRUE : FALSE;

		return $messages;
	}

	public function generate_key() {
		$this->key = md5($this->email . rand());
	}

	public function send_confirm_mail() {
		global $options;

		F3::set("approved_id", $this->key);
		F3::set("domain", $options['web']['domain']);
		$message = Template::serve('event_confirm_mail.txt');

		$subject = $options['general']['name'] . ": Please confirm your event";
		$headers = "From: " . $options['general']['email'];
		mail($this->email, $subject, $message, $headers);
	}

	public function send_approve_mail() {
		global $options;

		F3::set("title", $this->title);
		F3::set("domain", $options['web']['domain']);
		$message = Template::serve('event_approve_mail.txt');

		$subject = $options['general']['name'] . ": Event approved!";
		$headers = "From: " . $options['general']['email'];
		mail($this->email, $subject, $message, $headers);
	}

	public function save() {
		$e = new Axon('events');

		if ($this->id)
			$e->load('id=' . $this->id);

		$e->id = $this->id;
		$e->title = $this->title;
		$e->startdt = $this->startdt->format("Y-m-d H:i");
		if ($e->enddt)
			$e->enddt = $this->enddt->format("Y-m-d H:i");
		else
			$e->enddt = NULL;
		$e->location = $this->location->dbname();
		$e->blurb = $this->blurb;
		$e->url = $this->url;
		$e->cost = $this->cost;
		if ($this->film)
			$e->type = "film";

		$e->email = $this->email;
		$e->key = $this->key;
		$e->state = $this->state;

		$e->save();
	}
}

function set_event_data_from_POST() {
	F3::set('title', F3::scrub($_POST['title']));
	F3::set('location', F3::scrub($_POST['location']));
	F3::set('date1', F3::scrub($_POST['date1']));
	F3::set('time1', F3::scrub($_POST['time1']));
	if (isset($_POST['date2']))
		F3::set('date2', F3::scrub($_POST['date2']));
	if (isset($_POST['time2']))
		F3::set('time2', F3::scrub($_POST['time2']));
	F3::set('blurb', F3::scrub($_POST['blurb']));
	F3::set('url', F3::scrub($_POST['url']));
	F3::set('free', isset($_POST['free']) ? TRUE : FALSE);
	F3::set('cost', isset($_POST['cost']) ? F3::scrub($_POST['cost']) : "");
	F3::set('film', isset($_POST['film']) ? TRUE : FALSE);
	F3::set('email', F3::scrub($_POST['email']));
}

function set_event_data_from_Event($event) {
	F3::set('title', $event->title);
	F3::set('location', $event->location);
	F3::set('date1', $event->startdt->format("l j F"));
	F3::set('time1', $event->startdt->format("H:i"));
	if ($event->enddt) {
		F3::set('date2', $event->enddt->format("l j F"));
		F3::set('time2', $event->enddt->format("H:i"));
	}
	F3::set('blurb', $event->blurb);
	F3::set('url', $event->url);
	F3::set('free', $event->cost ? FALSE : TRUE);
	F3::set('cost', $event->cost);
	F3::set('film', $event->film);
	F3::set('email', $event->email);
}

?>
