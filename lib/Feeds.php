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

			$post['time'] = strftime('%H:%M', $ts);
			$post['date'] = strftime('%a %e %B', $ts);
			$post['feed'] = [
				'url' => $post['feed_id'],
				'title' => $post['title:1'],
				'site' => $post['site_url']
			];
		}

		return $results;
	}

	static function purge($months) {
		Feeds::$db->exec("DELETE FROM posts WHERE date < date('now', '-".intval($months)." months')");
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
			':id' => $feed->feed_url,
			':site' => $feed->get_link(),
			':title' => $feed->get_title()
		);

		$result = Feeds::$db->exec("INSERT OR IGNORE INTO feeds " .
				"(id, site_url, title) VALUES (:id, :site, :title)",
				$values);

		return $result == 0 ? FALSE : TRUE;
	}

	static function delete($url) {
		$values = array(":id" => $url);
		Feeds::$db->exec("DELETE FROM feeds WHERE id=:id", $values);
		Feeds::$db->exec("DELETE FROM posts WHERE feed_id=:id", $values);
	}

	static function update($feed, $title, $site_url) {
		$values = array(
			":id" => $feed,
			":site_url" => $site_url,
			":title" => $title);

		Feeds::$db->exec("UPDATE feeds SET site_url=:site_url, title=:title WHERE id=:id", $values);
	}
}

?>
