<?php

require_once 'lib_autolink.php';
require_once 'lib_parsedown.php';

function parse_time($value) {
	$normalise = function($time) {
		// Check if the time is set to 7am or before; if so, make it pm
		if ($time["hour"] <= 7)
			$time["hour"] += 12;
		return $time;
	};

	$formats = [
		[ "format" => "H:ia" ],
		[ "format" => "Ha" ],
		[ "format" => "H:i",   "filter" => $normalise ],
		[ "format" => "H" ,    "filter" => $normalise ]
	];

	foreach ($formats as $f) {
		$time = date_parse_from_format($f['format'], $value);
		if ($time['error_count'] == 0) {
			if (isset($f['filter'])) {
				$function = $f['filter'];
				$time = $function($time);
			}
			break;
		}
	}

	return $time;
}

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
	public $state;

	function __construct($id = NULL) {
		if (!$id)
			return;

		// Get the first result
		$r = Events::$db->exec("SELECT * FROM events WHERE id=:id", array(":id" => $id));
		if (sizeof($r) != 1)
			throw new Exception("Invalid record.");

		$r = $r[0];
		$this->id = $r['id'];
		$this->title = $r['title'];
		$this->startdt =
				DateTime::createFromFormat("Y-m-j H:i+", $r['startdt']);
		$this->enddt =
				DateTime::createFromFormat("Y-m-j H:i", $r['enddt']);
		$this->location = new Venue($r['location']);
		$this->blurb = $r['blurb'];
		$this->url = $r['url'];
		$this->cost = $r['cost'];
		$this->film = $r['type'] == "film" ? TRUE : FALSE;

		$this->state = $r['state'];
		$this->email = $r['email'];
	}

	public function multiday() {
		if (!$this->enddt) return FALSE;
		$interval = $this->startdt->diff($this->enddt);
		return $interval->d >= 1;
	}
	
	public function blurb_as_html() {
		return Parsedown::instance()->parse(autolink($this->blurb, 40));
	}
	
	public function parse_form_data($method = "post") {
		if ($method == "post") $method = $_POST;

		$messages = array();
		$self = $this;
		$set_end = false;

		$handlers = array(
			"title" => function($value) use(&$self, &$messages) {
				if (strlen($value) > 140)
					$messages[] = "Title too long.";

				$self->title = $value;
			},
	
			"date1" => function($value) use(&$self, &$messages) {
				$self->startdt = DateTime::createFromFormat("l j F Y", $value);
				if (!$self->startdt)
					$self->startdt = DateTime::createFromFormat("Y-m-d", $value);
				if (!$self->startdt)
					$messages[] = "Invalid start date.";
			},
	
			"time1" => function($value) use(&$self, &$messages) {
				$time = parse_time($value);

				if ($time['error_count'] > 0)
					$messages[] = "Invalid start time.";
				if ($self->startdt)
					$self->startdt->setTime($time['hour'], $time['minute']);
			},

			"set_end" => function($value) use (&$self, &$set_end) {
				$set_end = $value ? true : false;
			},

			"date2" => function($value) use(&$self, &$messages, &$set_end) {
				if ($value && $set_end) {
					$self->enddt = DateTime::createFromFormat("l j F Y", $value);
					if (!$self->enddt)
						$messages[] = "Invalid end date.";
				} else {
					$self->enddt = NULL;
				}
			},

			"time2" => function($value) use(&$self, &$messages, &$set_end) {
				if ($value && $set_end) {
					$time = parse_time($value);

					if ($time['error_count'] > 0)
						$messages[] = "Invalid end time.";
					else if ($self->enddt)
						$self->enddt->setTime($time['hour'], $time['minute']);
				} else {
					$self->enddt = NULL;
				}
			},

			"email" => function($value) use(&$self, &$messages) {
				if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
					$messages[] = "Invalid email address";
				}
				$self->email = $value;
			},

			"url" => function($url) use(&$self, &$messages) {
				if (!$url || $url == "") {
					$self->url = NULL;
				} else {
					if (!preg_match('/^[a-zA-Z]+\:/', $url))
						$url = "http://" . $url;
					if (!filter_var($url, FILTER_VALIDATE_URL))
						$messages[] = "Invalid web address";
				}
				$self->url = $url;
			},

			"location" => function($value) use(&$self, &$messages) {
				global $f3;
				$value = $f3->scrub($value);
				if (strlen($value) < 3)
					$messages[] = "Location too short.";

				$self->location = new Venue($value);
			},

			"blurb" => function($blurb) use(&$self, &$messages) {
				global $f3;
				$self->blurb = $f3->scrub($blurb);
			},
			
			"film" => function($film) use(&$self, &$messages) {
				$self->film = $film ? TRUE : FALSE;
			}
		);

		foreach ($handlers as $name => $handler) {
			$value = isset($method[$name]) ? $method[$name] : NULL;
			$handler($value);
		}

		if (isset($method['date2']) != isset($method['time2'])) {
			$messages[] = "Only one of end date / end time given.";
		}

		if (isset($method['free']) && $method['free'] == 'free') {
			$this->cost = NULL;
		} else {
			global $f3;
			$this->cost = $f3->scrub($method['cost']);
		}

		return $messages;
	}

	public function approve() {
		$this->state = "approved";
		$this->save();
		$this->send_approve_mail();
	}

	public function unapprove() {
		$this->state = "submitted";
		$this->save();
	}

	public function send_confirm_mail() {
		global $options;
		global $f3;

		$f3->set("domain", $options['web']['domain']);
		$template = new Template;
		$message = wordwrap($template->render('event_confirm_mail.txt','text/plain'));

		$subject = $options['general']['name'] . ": Event submitted";
		$headers = "From: " . $options['general']['email'];
		mail($this->email, $subject, $message, $headers);
	}

	public function send_approve_mail() {
		global $options;
		global $f3;

		$f3->set("title", $this->title);
		$f3->set("domain", $options['web']['domain']);
		$template = new Template;
		$message = $template->render('event_approve_mail.txt', 'text/plain');

		$subject = $options['general']['name'] . ": Event approved!";
		$headers = "From: " . $options['general']['email'];
		mail($this->email, $subject, $message, $headers);
	}

	public function save() {
		$e = new DB\SQL\Mapper(Events::$db, 'events');

		$get_id = true;
		if ($this->id) {
			$e->load(array('id=?', $this->id));
			$get_id = false;
		}

		$e->id = $this->id;
		$e->title = $this->title;
		$e->startdt = $this->startdt->format("Y-m-d H:i");
		if ($this->enddt)
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
		$e->state = $this->state;

		$e->save();

		if ($get_id)
			$this->id = $e->get('_id');
	}
	
	public function set_form_data() {
		global $f3;
		$f3->mset([
			'title' => $this->title,
			'location' => $this->location,
			'date1' => $this->startdt ? $this->startdt->format("l j F Y") : NULL,
			'time1' => $this->startdt ? $this->startdt->format("g:ia") : NULL,
			'date2' => $this->enddt ? $this->enddt->format("l j F Y") : NULL,
			'time2' => $this->enddt ? $this->enddt->format("g:ia") : NULL,
			'blurb' => $this->blurb,
			'url' => $this->url,
			'free' => $this->cost ? FALSE : TRUE,
			'cost' => $this->cost,
			'film' => $this->film,
			'email' => $this->email,
		]);
	}
}

class Events {
	static public $db;

	static function init($db) {
		Events::$db = $db;
	}

	static function load($where, $limit = NULL) {
		$events = array();
		$statement = "SELECT id FROM events WHERE " . $where;
		$statement .= " ORDER BY startdt";
		if ($limit)
			$statement .= " LIMIT ".$limit;

		$r = Events::$db->exec($statement);
		foreach ($r as $row) {
			$events[] = new Event($row['id']);
		}

		return $events;
	}


	static function json($array) {
		$events = [];

		foreach ($array as $event) {
			$insert = [
				'id' => $event->id,
				'title' => $event->title,
				'start' => $event->startdt->format('U'),
			];
			if ($event->enddt)
				$insert['end'] = $event->enddt->format('U');
			if ($event->url)
				$insert['url'] = $event->url;
			if (isset($_SESSION['admin']) && $event->state == 'imported')
				$insert['title'] .= " *";

			$events[] = $insert;
		}

		return json_encode($events);
	}

	static function purge($m) {
		Events::$db->exec("DELETE FROM events WHERE startdt < date('now', '-".$m." months')");
	}

	static function delete($id) {
		Events::$db->exec("DELETE FROM events WHERE id=:id", array(':id' => $id));
	}

	static function sql($cmds,$args=NULL,$ttl=0) {
		return Events::$db->exec($cmds, $args, $ttl);
	}
}

?>
