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
		Feeds::$db->exec("DELETE FROM posts WHERE date < date('now', '-".$months." months')");
	}

	static function getlist() {
		return Feeds::$db->exec("SELECT * FROM feeds");
	}

	static function add($url) {
		require_once 'lib/simplepie_1.3.compiled.php';

		$feed = new SimplePie();
		$feed->set_feed_url($_POST['url']);
		$feed->enable_cache(false);
		$feed->init();
		$feed->handle_content_type();

		$values = array(
			':feed' => $feed->feed_url,
			':site' => $feed->get_link(),
			':title' => $feed->get_title()
		);

		$result = Feeds::$db->exec("INSERT OR IGNORE INTO feeds " .
				"(feed_url, site_url, title) VALUES (:feed, :site, :title)",
				$values);

		return $result == 0 ? FALSE : TRUE;
	}

	static function delete($url) {
		$values = array(":feed_url" => $url);
		Feeds::$db->exec("DELETE FROM feeds WHERE feed_url=:feed_url", $values);
		Feeds::$db->exec("DELETE FROM posts WHERE feed_url=:feed_url", $values);
	}

	static function update($feed_url, $title, $site_url) {
		$values = array(
			":feed_url" => $feed_url,
			":site_url" => $title,
			":title" => $site_url);

		Feeds::$db->exec("UPDATE feeds SET site_url=:site_url, title=:title WHERE feed_url=:feed_url", $values);
	}
}

?>
