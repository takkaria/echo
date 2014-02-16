<?php

// Group events by day
$f3->set('group_events', function($ongoing = TRUE) {
	global $f3;
	$events = $f3->get('events');
	$bydate = [];
	foreach ($events as $e) {
		$dt = clone $e->startdt;
		$dtstamp = $dt->modify("today")->format("Y-m-d");
		$bydate[$dtstamp][] = $e;
	}

	$final = [];
	foreach ($bydate as $dtstamp => $list) {
		if ($ongoing && new DateTime($dtstamp) < new DateTime("today")) {
			$final['Ongoing'] = $list;
		} else {
			$final[$dtstamp] = $list;
		}
	}
	return $final;
});

// Format some text appropriately for iCal text fields
$f3->set('icalendarise', function($text) {
	$text = preg_replace("/[\s]+/x", " ", $text);
	return preg_replace("/([,;])/", "\\\\\\1", $text);
});

// Format a nice prettily for the events listing
$f3->set('formatdate', function($date, $relativise = TRUE) {
	if ($date == 'Ongoing')
		return $date;

	$today = new DateTime("today"); // This gets the beginning of the day
	$event = new DateTime($date);
	$format = 'l j F';	// This should be in the templates but for some reason F3 was screwing up with it

	$diff = intval($today->diff($event)->format('%R%a'));

	if (!$relativise)
		return $event->format($format);

	if ($diff < -35) { // 5 weeks
		$n = intval(-$diff/31);
		if ($n == 1)
			return "a month ago";
		else
			return $n . " months ago";
	} else if ($diff < -7) { // 1 week
		$float = -$diff/7.0;
		$n = intval($float);
		if ($n == 1 && $float - $n > 0.5)
			return "a week and a bit ago";
		else if ($n == 1)
			return "a week ago";
		else
			return $n . " weeks ago";
	} else if ($diff < -1)
		return -$diff . " days ago";
	else if ($diff == -1)
		return "a day ago";
	else if ($diff == 0)
		return "Today";
	else if ($diff == 1)
		return "Tomorrow";
	else if ($diff < 5)
		return $event->format('l');
	else
		return $event->format($format);
});

// Return how many days away a given date is
$f3->set('daysaway', function($date) {
	if ($date == 'Ongoing')
		return 0;

	$today = new DateTime("today"); // This gets the beginning of the day
	$event = new DateTime($date);

	return intval($today->diff($event)->format('%R%a'));
});

// Output 'value' attribute suitable for input tag if arg isn't null
$f3->set('value', function($arg) {
	if ($arg)
		return 'value="' . $arg . '"';
});

// Check if URL is a Facebook URL
$f3->set('facebook', function($url) {
	return strpos($url, "facebook.com") !== FALSE;
});

// Convert commas into brs
$f3->set('comma2br', function($text) {
	return preg_replace('/, /', '<br>', $text);
});

// Convert commas into brs
$f3->set('get_venues', function() {
	return Events::$db->exec("SELECT name FROM venues");
});

// Turn newlines to paragraphs
$f3->set('nl2p', function($string) {
	$paragraphs = '';

	foreach (explode("\n", $string) as $line) {
		if (trim($line))
		$paragraphs .= '<p>' . $line . '</p>';
	}

	return autolink($paragraphs, 40);
});


/// Autolink stuff, taken from https://github.com/iamcal/lib_autolink

	#
	# A PHP auto-linking library
	#
	# https://github.com/iamcal/lib_autolink
	#
	# By Cal Henderson <cal@iamcal.com>
	# This code is licensed under the MIT license
	#

	####################################################################

	#
	# These are global options. You can set them before calling the autolinking
	# functions to change the output.
	#

	$GLOBALS['autolink_options'] = array(

		# Should http:// be visibly stripped from the front
		# of URLs?
		'strip_protocols' => true,

	);

	####################################################################

	function autolink($text, $limit=30, $tagfill='', $auto_title = true){

		$text = autolink_do($text, '![a-z][a-z-]+://!i',	$limit, $tagfill, $auto_title);
		$text = autolink_do($text, '!(mailto|skype):!i',	$limit, $tagfill, $auto_title);
		$text = autolink_do($text, '!www\\.!i',			$limit, $tagfill, $auto_title, 'http://');
		return $text;
	}

	####################################################################

	function autolink_do($text, $sub, $limit, $tagfill, $auto_title, $force_prefix=null){

		$text_l = StrToLower($text);
		$cursor = 0;
		$loop = 1;
		$buffer = '';

		while (($cursor < strlen($text)) && $loop){

			$ok = 1;
			$matched = preg_match($sub, $text_l, $m, PREG_OFFSET_CAPTURE, $cursor);

			if (!$matched){

				$loop = 0;
				$ok = 0;

			}else{

				$pos = $m[0][1];
				$sub_len = strlen($m[0][0]);

				$pre_hit = substr($text, $cursor, $pos-$cursor);
				$hit = substr($text, $pos, $sub_len);
				$pre = substr($text, 0, $pos);
				$post = substr($text, $pos + $sub_len);

				$fail_text = $pre_hit.$hit;
				$fail_len = strlen($fail_text);

				#
				# substring found - first check to see if we're inside a link tag already...
				#

				$bits = preg_split("!</a>!i", $pre);
				$last_bit = array_pop($bits);
				if (preg_match("!<a\s!i", $last_bit)){

					#echo "fail 1 at $cursor<br />\n";

					$ok = 0;
					$cursor += $fail_len;
					$buffer .= $fail_text;
				}
			}

			#
			# looks like a nice spot to autolink from - check the pre
			# to see if there was whitespace before this match
			#

			if ($ok){

				if ($pre){
					if (!preg_match('![\s\(\[\{>]$!s', $pre)){

						#echo "fail 2 at $cursor ($pre)<br />\n";

						$ok = 0;
						$cursor += $fail_len;
						$buffer .= $fail_text;
					}
				}
			}

			#
			# we want to autolink here - find the extent of the url
			#

			if ($ok){
				if (preg_match('/^([a-z0-9\-\.\/\-_%~!?=,:;&+*#@\(\)\$]+)/i', $post, $matches)){

					$url = $hit.$matches[1];

					$cursor += strlen($url) + strlen($pre_hit);
					$buffer .= $pre_hit;

					$url = html_entity_decode($url);


					#
					# remove trailing punctuation from url
					#

					while (preg_match('|[.,!;:?]$|', $url)){
						$url = substr($url, 0, strlen($url)-1);
						$cursor--;
					}
					foreach (array('()', '[]', '{}') as $pair){
						$o = substr($pair, 0, 1);
						$c = substr($pair, 1, 1);
						if (preg_match("!^(\\$c|^)[^\\$o]+\\$c$!", $url)){
							$url = substr($url, 0, strlen($url)-1);
							$cursor--;
						}
					}


					#
					# nice-i-fy url here
					#

					$link_url = $url;
					$display_url = $url;

					if ($force_prefix) $link_url = $force_prefix.$link_url;

					if ($GLOBALS['autolink_options']['strip_protocols']){
						if (preg_match('!^(http|https)://!i', $display_url, $m)){
							$display_url = substr($display_url, strlen($m[1])+3);
						}
					}

					if (substr($display_url, -1) == "/")
						$display_url = substr($display_url, 0, -1);

					$display_url = autolink_label($display_url, $limit);


					#
					# add the url
					#
					
					if ($display_url != $link_url && !preg_match('@title=@msi',$tagfill) && $auto_title) {

						$display_quoted = preg_quote($display_url, '!');

						if (!preg_match("!^(http|https)://{$display_quoted}$!i", $link_url)){

							$tagfill .= ' title="'.$link_url.'"';
						}
					}

					$link_url_enc = HtmlSpecialChars($link_url);
					$display_url_enc = HtmlSpecialChars($display_url);

					$buffer .= "<a href=\"{$link_url_enc}\"$tagfill>{$display_url_enc}</a>";
				
				}else{
					#echo "fail 3 at $cursor<br />\n";

					$ok = 0;
					$cursor += $fail_len;
					$buffer .= $fail_text;
				}
			}

		}

		#
		# add everything from the cursor to the end onto the buffer.
		#

		$buffer .= substr($text, $cursor);

		return $buffer;
	}

	####################################################################

	function autolink_label($text, $limit){

		if (!$limit){ return $text; }

		if (strlen($text) > $limit){
			return substr($text, 0, $limit-3).'...';
		}

		return $text;
	}

	####################################################################

	function autolink_email($text, $tagfill=''){

		$atom = '[^()<>@,;:\\\\".\\[\\]\\x00-\\x20\\x7f]+'; # from RFC822

		#die($atom);

		$text_l = StrToLower($text);
		$cursor = 0;
		$loop = 1;
		$buffer = '';

		while(($cursor < strlen($text)) && $loop){

			#
			# find an '@' symbol
			#

			$ok = 1;
			$pos = strpos($text_l, '@', $cursor);

			if ($pos === false){

				$loop = 0;
				$ok = 0;

			}else{

				$pre = substr($text, $cursor, $pos-$cursor);
				$hit = substr($text, $pos, 1);
				$post = substr($text, $pos + 1);

				$fail_text = $pre.$hit;
				$fail_len = strlen($fail_text);

				#die("$pre::$hit::$post::$fail_text");

				#
				# substring found - first check to see if we're inside a link tag already...
				#

				$bits = preg_split("!</a>!i", $pre);
				$last_bit = array_pop($bits);
				if (preg_match("!<a\s!i", $last_bit)){

					#echo "fail 1 at $cursor<br />\n";

					$ok = 0;
					$cursor += $fail_len;
					$buffer .= $fail_text;
				}
			}

			#
			# check backwards
			#

			if ($ok){
				if (preg_match("!($atom(\.$atom)*)\$!", $pre, $matches)){

					# move matched part of address into $hit

					$len = strlen($matches[1]);
					$plen = strlen($pre);

					$hit = substr($pre, $plen-$len).$hit;
					$pre = substr($pre, 0, $plen-$len);

				}else{

					#echo "fail 2 at $cursor ($pre)<br />\n";

					$ok = 0;
					$cursor += $fail_len;
					$buffer .= $fail_text;
				}
			}

			#
			# check forwards
			#

			if ($ok){
				if (preg_match("!^($atom(\.$atom)*)!", $post, $matches)){

					# move matched part of address into $hit

					$len = strlen($matches[1]);

					$hit .= substr($post, 0, $len);
					$post = substr($post, $len);

				}else{
					#echo "fail 3 at $cursor ($post)<br />\n";

					$ok = 0;
					$cursor += $fail_len;
					$buffer .= $fail_text;
				}
			}

			#
			# commit
			#

			if ($ok) {

				$cursor += strlen($pre) + strlen($hit);
				$buffer .= $pre;
				$buffer .= "<a href=\"mailto:$hit\"$tagfill>$hit</a>";

			}

		}

		#
		# add everything from the cursor to the end onto the buffer.
		#

		$buffer .= substr($text, $cursor);

		return $buffer;
	}

	####################################################################

?>
