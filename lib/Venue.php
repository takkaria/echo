<?php

class Venue {
	public $in_db = FALSE;

	public $id;
	public $name;
	public $address;
	public $postcode;
	public $info;

	function __construct($venue = NULL) {
		$i = intval($venue);
		if ($i != 0)
			$this->lookup($i);
		else if (is_string($venue) && !$this->lookup($venue))
			$this->name = $venue;
	}

	function lookup($id) {
		if (is_int($id))
			$r = Events::$db->exec("SELECT * FROM venues WHERE id=:id", array(":id" => $id));
		else
			$r = Events::$db->exec("SELECT * FROM venues WHERE name=:name",
					array(":name" => $id));

		// Get first result
		if (isset($r[0])) {
			$r = $r[0];
	
			$this->in_db = TRUE;
			$this->id = $r['id'];
			$this->name = $r['name'];
			$this->address = $r['address'];
			$this->postcode = $r['postcode'];
			$this->info = $r['info'];

			return TRUE;
		} else {
			return FALSE;
		}
	}

	function __toString() {
		return $this->name;
	}

	function short() {
		return $this->name;
	}

	function dbname() {
		if ($this->in_db)
			return $this->id;
		else
			return $this->name;
	}

	function parse_form_data() {
		var_dump($_POST);

		$this->name = F3::scrub($_POST['name']);
		$this->address = F3::scrub($_POST['address']);
		$this->postcode = F3::scrub($_POST['postcode']);
		$this->info = F3::scrub($_POST['info']);

		return array();
	}

	public function save() {
		$v = new Axon('venues');

		if ($this->id)
			$v->load('id=' . $this->id);

		$v->name = $this->name;
		$v->address = $this->address;
		$v->postcode = $this->postcode;
		$v->info = $this->info;

		$v->save();
	}
}

function set_venue_data_from_POST() {
	F3::set('name', F3::scrub($_POST['name']));
	F3::set('address', F3::scrub($_POST['address']));
	F3::set('postcode', F3::scrub($_POST['postcode']));
	F3::set('info', F3::scrub($_POST['info']));
}

?>
