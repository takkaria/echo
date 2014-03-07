<?php

class User
{
	static public $db;

	static function init($db) {
		User::$db = $db;
	}

	/** Utilities **/
	static function notify_all($event) {
		global $options;
		global $f3;

		$f3->set("event", $event);
		$f3->set("domain", $options['web']['domain']);
		$f3->set('admin', FALSE);

		$r = User::$db->exec("SELECT email FROM users WHERE notify=1");
		foreach ($r as $user) {
			$email = $user['email'];

			// To send HTML mail, the Content-type header must be set
			$headers = 'MIME-Version: 1.0' . "\r\n";
			$headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
			$headers .= "From: " . $options['general']['email'] . "\r\n";
			$headers .= "Reply-To: " . $event->email . "\r\n";

			$result = mail($email,
				"New Echo event: " . $event->title,
				Template::instance()->render('event_notify_email.txt', 'text/html'),
				$headers);

			var_dump($result);
		}
	}

	/** Getters **/

	static function find_pwreset($key) {
		$r = User::$db->exec(
			"SELECT email FROM users WHERE pwreset=:key",
			array(':key' => $key));

		if (isset($r[0]))
			return $r[0]['email'];
		else
			return null;
	}

	/** Predicates **/

	static function isbanned($email) {
		$r = User::$db->exec(
			"SELECT banned FROM users WHERE email=:email AND banned = 1",
			array(':email' => $email));

		return isset($r[0]);
	}

	static function rights($email) {
		$r = User::$db->exec(
			"SELECT rights FROM users WHERE email=:email",
			array(':email' => $email));

		if (isset($r[0]))
			return $r[0]['rights'];
		else
			return null;
	}

	/** Setters **/

	static function set_notify($user, $notify) {
		$r = User::$db->exec(
			"UPDATE users SET notify=:notify WHERE email=:user",
			array(
				':notify' => $notify,
				':user' => $user));

		return $r ? true : false;
	}

	static function set_rights($user, $rights) {
		$r = User::$db->exec(
			"UPDATE users SET rights=:rights WHERE email=:user",
			array(
				':rights' => $rights,
				':user' => $user));
		return $r ? true : false;
	}

	static function reset_password($user) {
		$pwreset = md5(base64_encode(openssl_random_pseudo_bytes(24)));
		$r = User::$db->exec(
			"UPDATE users SET pwreset=:pwreset, salt=NULL, digest=NULL WHERE email=:user",
			array(
				':pwreset' => $pwreset,
				':user' => $user
				));

		/* Email out */

		global $options;
		global $f3;

		$f3->set("pwreset", $pwreset);
		$f3->set("domain", $options['web']['domain']);
		$message = Template::instance()->render('password_mail.txt','text/plain');

		$subject = $options['general']['name'] . ": Please reset your password";
		$headers = "From: " . $options['general']['email'];
		mail($user, $subject, $message, $headers);
	}

	static function new_password($user, $pw) {
		$salt = base64_encode(openssl_random_pseudo_bytes(32));
		$r = User::$db->exec(
			"UPDATE users SET salt=:salt, digest=:digest WHERE email=:user",
			array(
				':salt' => $salt,
				':digest' => hash("sha256", $salt . $pw),
				':user' => $user
				));
	}

	/** Instantiators **/

	static function new_user($user, $rights) {
		$r = User::$db->exec("INSERT INTO users (email,rights) VALUES (:user,:rights)",
			array(':user' => $user, ':rights' => $rights));
		User::reset_password($user);
	}
}

?>
