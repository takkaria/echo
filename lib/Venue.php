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
			DB::sql("SELECT * FROM venues WHERE id=:id", array(":id" => $id));
		else
			DB::sql("SELECT * FROM venues WHERE name=:name",
					array(":name" => $id));

		// Get the first result
		$r = F3::get('DB->result');

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
}

?>
