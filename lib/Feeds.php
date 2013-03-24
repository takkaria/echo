<?php

class Feeds
{
	static public $db;

	static function init($db) {
		Feeds::$db = $db;
	}

	static function load($where) {
		$results = Feeds::$db->exec("SELECT * FROM post_info WHERE " . $where);

		foreach ($results as &$post) {
			$ts = strtotime($post['date']);

			$post['id'] = $post['id'];
			$post['time'] = strftime('%H:%M', $ts);
			$post['date'] = strftime('%a %e %B', $ts);
			$post['feed'] = array();
			$post['feed']['url'] = $post['feed_url'];
			$post['feed']['title'] = $post['title:1'];
			$post['feed']['site'] = $post['site_url'];
		}

		return $results;
	}

	static function purge($months) {
		DB::sql("DELETE FROM posts WHERE date < date('now', '-".$months." months')");
	}
}

?>
