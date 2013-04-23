<?php

class User
{
	static public $db;

	static function init($db) {
		User::$db = $db;
	}

	static function isbanned($email) {
		$r = User::$db->exec("SELECT banned FROM users" .
				" WHERE email=:email AND banned = 1",
				array(':email' => $email));
		return isset($r[0]);
	}
}

?>
