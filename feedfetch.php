<?php

// XXX need to build an admin interface to add/remove feeds

if (php_sapi_name() != 'cli')
	exit(1);

require_once 'lib/fatfree/lib/base.php';
require_once 'lib/simplepie_1.3.compiled.php';
require_once 'lib/simple_html_dom.php';

function do_summary_magic($summary) {
	/* Strip html entities first */
	$summary = strip_tags(html_entity_decode($summary, ENT_QUOTES, 'UTF-8'));

	// apparently we need to strip out any unfinished sentences...
	// Inside the M60 (wordpress) abbreviates like:
	// "borrower a new copy. As citizen demands change [...]"
	// So we need to strip any ending bits like "[...]"...
	$summary = preg_replace("/ ?\[\.\.\.\]/i", "", $summary);

	// And now find the bit from the beginning until the last full stop
	$summary = preg_replace("/^(.*\.)(.*)/", "$1", $summary);

	return $summary;
}

function summary_from_content($content) {
	$content = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));

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

	$content = substr($content, 0, 500);
	$content = do_summary_magic($content);

	return $content;
}

function find_image($content) {
	// find an image
	$html = str_get_html($content);
	$img = $html->find('img', 0);

	// Filter out annoying 'Comment:' images or blogspot trackers
	if ($img && !preg_match('/(comments|tracker)/', $img)) {
		return $img->src;
	}
}

function fetch_feed($url) {
	/* Fetch */
	$feed = new SimplePie();
	$feed->set_feed_url($url);
	$feed->enable_cache();
	$feed->set_cache_location('cache');
	$feed->init();

	foreach ($feed->get_items() as $post) {
		$dbstore = array(
			':feed_url' => $url,
			':id' => $post->get_id(),
			':title' => $post->get_title(),
			':link' => $post->get_permalink(),
			':date' => $post->get_gmdate("Y-m-d H:i"),
			':image' => find_image($post->get_content(true))
		);

		$summary = $post->get_description(true);
		if ($summary)
			$summary = do_summary_magic($summary);
		else
			$summary = summary_from_content($post->get_content(true));

		$dbstore[':summary'] = $summary;

		// XXX Try not to cut off URLs mid-flow
		// XXX Consider not adding title-less posts

		DB::sql('INSERT OR IGNORE INTO POSTS ( feed_url, id, title, link, date, image, summary ) VALUES ( :feed_url, :id, :title, :link, :date, :image, :summary );', $dbstore);
	}

}

/* Connect to DB */
F3::set('DB', new DB("sqlite:feeds.sqlite"));

if ($argc == 2) {
	/* Fetch the provided feed and dump it */
	fetch_feed($argv[1]);
} else {
	/* Fetch feeds */
	DB::sql("SELECT * FROM feeds");
	$results = F3::get('DB->result');
	foreach ($results as $feed_info)
		fetch_feed($feed_info['feed_url']);
}

exit(0);

?>



