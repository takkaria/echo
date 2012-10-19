<?php

class Venue {
	public $name;

	function __construct($name = NULL) {
		$this->name = $name;
	}

	function short() {
		return $this->name;
	}

	function db_form() {
		return $this->name;
	}
}

?>