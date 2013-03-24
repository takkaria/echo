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
	
	public function parse_form_data() {
		$messages = array();
		$self = $this;

		$handlers = array(
			"title" => function($value) use(&$self, &$messages) {
				if (strlen($value) > 140)
					$messages[] = "Title too long.";

				$self->title = $value;
			},
	
			"date1" => function($value) use(&$self, &$messages) {
				$self->startdt = DateTime::createFromFormat("l j F", $value);
				if (!$self->startdt)
					$messages[] = "Invalid start date.";
			},
	
			"time1" => function($value) use(&$self, &$messages) {
				$time = date_parse_from_format("H:i", $value);
				if ($time['error_count'] > 0)
					$messages[] = "Invalid start time.";
				if ($self->startdt)
					$self->startdt->setTime($time['hour'], $time['minute']);
			},

			"date2" => function($value) use(&$self, &$messages) {
				if ($value) {
					$self->enddt = DateTime::createFromFormat("l j F", $value);
					if (!$self->enddt)
						$messages[] = "Invalid end date.";
				}
			},

			"time2" => function($value) use(&$self, &$messages) {
				if ($value) {
					$time = date_parse_from_format("H:i", $value);
					if ($time['error_count'] > 0) {
						$messages[] = "Invalid end time.";
					} else if ($self->enddt) {
						$self->enddt->setTime($time['hour'], $time['minute']);
					}
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
					if (!preg_match('/^[a-zA-Z]+:/', $url))
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
				$blurb = $f3->scrub($blurb);
				if (strlen($blurb) < 20)
					$messages[] = "Description too short.";
				$self->blurb = $blurb;
			},
			
			"film" => function($film) use(&$self, &$messages) {
				$self->film = $film ? TRUE : FALSE;
			}
		);

		foreach ($handlers as $name => $handler) {
			$value = isset($_POST[$name]) ? $_POST[$name] : NULL;
			$handler($value);
		}

		if (isset($_POST['date2']) != isset($_POST['time2'])) {
			$messages[] = "Only one of end date / end time given.";
		}

		if (isset($_POST['free']) && $_POST['free'] == 'free') {
			$this->cost = NULL;
		} else {
			$this->cost = F3::scrub($_POST['cost']);
		}

		return $messages;
	}

	public function generate_key() {
		$this->key = md5($this->email . rand());
	}

	public function send_confirm_mail() {
		global $options;
		global $f3;

		$f3->set("approved_id", $this->key);
		$f3->set("domain", $options['web']['domain']);
		$template = new Template;
		$message = $template->render('event_confirm_mail.txt','text/plain');

		$subject = $options['general']['name'] . ": Please confirm your event";
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

		if ($this->id)
			$e->load(array('id=?', $this->id));

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
	
	public function set_form_data() {
		global $f3;
		$f3->mset(array(
			'title' => $this->title,
			'location' => $this->location,
			'date1' => $this->startdt->format("l j F"),
			'time1' => $this->startdt->format("H:i"),
			'date2' => $this->enddt ? $this->enddt->format("l j F") : NULL,
			'time2' => $this->enddt ? $this->enddt->format("H:i") : NULL,
			'blurb' => $this->blurb,
			'url' => $this->url,
			'free' => $this->cost ? FALSE : TRUE,
			'cost' => $this->cost,
			'film' => $this->film,
			'email' => $this->email,
		));
	}
}

class Events {
	static public $db;

	static function init($db) {
		Events::$db = $db;
	}

	static function load($where) {
		$events = array();
		$r = Events::$db->exec("SELECT id FROM events WHERE " . $where .
				" ORDER BY startdt");
		foreach ($r as $row) {
			$events[] = new Event($row['id']);
		}

		return $events;
	}

	static function purge($months) {
		Events::$db->exec("DELETE FROM events WHERE startdt < date('now', '-".$m." months')");
	}

	static function validate($key) {
		$result = Events::$db->exec(
				"UPDATE events" .
				" SET key=NULL, state=:state" .
				" WHERE key=:key",
				array(':state' => "validated", ':key' => $key));
		return $result ? TRUE : FALSE;
	}

	static function delete($id) {
		Events::$db->sql("DELETE FROM events WHERE id=:id", array(':id' => $id));
	}

	static function sql($cmds,$args=NULL,$ttl=0) {
		return Events::$db->exec($cmds, $args, $ttl);
	}
}

?>
