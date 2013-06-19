<?php

$debug = false;
if (php_sapi_name() != 'cli')
	exit(1);

date_default_timezone_set('Europe/London');

$f3 = require 'lib/fatfree/base.php';
require_once 'lib/simplepie_1.3.compiled.php';
require_once 'lib/simple_html_dom.php';

function deentity($text) {
	// First nuke non-breaking spaces
	$text = preg_replace("/&nbsp;/", " ", $text);

	// Then strip tags and entities
	return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
}

function trim_summary($summary) {

	// Just for the Salford Star
	$summary = preg_replace("/Star date: [^\n]*/", "", $summary);

	// Get rid of anything after 'The post <a' (ACI)
	$summary = preg_replace("/The post \<a.*/", "", $summary);

	// Remove tags, one way or another
	try {
		$summary = str_get_html($summary);
		$summary = $summary->plaintext;
	} catch (Exception $e) {
		$summary = strip_tags($summary);
	};

	// apparently we need to strip out any unfinished sentences...
	// Inside the M60 (wordpress) abbreviates like:
	// "borrower a new copy. As citizen demands change [...]"
	// So we need to strip any ending bits like "[...]"...
	$summary = preg_replace("/ ?\[\.\.\.\]/i", "", $summary);

	// Reduce to a reasonable size
	$summary = substr($summary, 0, 500);

	// And now find the bit from the beginning until the last full stop
	$summary = preg_replace("/^(.*\.)(.*)/", "$1", $summary);

	return $summary;
}

function summary_from_content($content) {
	// strategy: try and grab the first paragraph
	// failing that, grab the chunk of text up until the first double-<br>
	$html = str_get_html($content);
	$p = $html->find('p', 0);
	if ($p) {
		$content = $p->plaintext;
	} else {
		$content = preg_replace("/(.*?)(\<br\>\<br\>.*)/i", "$1", $content);
		$html = str_get_html($content);
		$content = $html->plaintext;
	}

	$content = trim_summary($content);

	// Check for cut-off URLs
	// strategy: find the last space in the text
	// and see if the text after is 'http'
	if (preg_match('/^ (www|http)/', strrchr($content, " "))) {
		// Then just capture up until the last space and re-do the trimming
		$content = preg_replace('/(.*) .*/', "$1", $content);
		$content = trim_summary($content);
	}

	return $content;
}

function find_image($content) {
	if (!$content) return;

	// find an image
	$html = str_get_html($content);
	$img = $html->find('img', 0);

	// Filter out annoying 'Comment:' images or blogspot trackers
	if ($img && !preg_match('/(comments|tracker)/', $img)) {
		return $img->src;
	}
}

function fetch_feed($db, $url) {
	global $debug;

	/* Fetch */
	$feed = new SimplePie();
	$feed->set_feed_url($url);
	$feed->enable_cache();
	$feed->set_cache_location('cache');
	$feed->init();
	$db->exec('UPDATE feeds SET errors=:errors WHERE feed_url=:url', array(':url' => $url, 'errors' => $feed->error()));

	foreach ($feed->get_items() as $post) {
		if (!$post->get_title())
			break;

		$title = $post->get_title();

		$title2 = preg_replace("/Salford Star - /i", "", $title);
		if ($title != $title2)
			$title = ucwords(strtolower($title2));

		$summary = $post->get_description(true);
		if ($summary)
			$summary = trim_summary(deentity($summary));
		else
			$summary = summary_from_content(deentity($post->get_content(true)));

		$dbstore = array(
			':feed_url' => $url,
			':id' => $post->get_id(),
			':title' => $title,
			':link' => $post->get_permalink(),
			':date' => $post->get_gmdate("Y-m-d H:i"),
			':image' => find_image($post->get_content(true)),
			':summary' => $summary
		);

		if ($debug) {
			echo $title . "\n";
			echo $summary . "\n";
			echo "\n=====================\n";
		}

		$db->exec('INSERT OR IGNORE INTO POSTS ( feed_url, id, title, link, date, image, summary ) VALUES ( :feed_url, :id, :title, :link, :date, :image, :summary );', $dbstore);
	}

}

/* Connect to DB */
$options = parse_ini_file('doormat.ini', true);
$db = new DB\SQL("sqlite:" . $options['db']['feeds']);

if ($argc == 2) {
	$debug = true;
	/* Fetch the provided feed and dump it */
	fetch_feed($db, $argv[1]);
} else {
	/* Fetch feeds */
	$results = $db->exec("SELECT * FROM feeds");
	foreach ($results as $feed_info)
		fetch_feed($db, $feed_info['feed_url']);
}

exit(0);

?>
