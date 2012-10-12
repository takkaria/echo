<?php

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

			// XXX error-check

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

?>
