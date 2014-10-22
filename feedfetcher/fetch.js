var ical = require('ical')
var FeedParser = require('feedparser')
var request = require('request')
var htmlStrip = require('htmlstrip-native').html_strip

var Event, Post, Feed
var debug = false

// = iCal ===================================================== //

function fetchICal(params) {
	var url = params.url;
	var filter = params.filter;
	var transform = params.transform;

	function saveEvent(event) {
		var item = this;

		if (event != null) return;   /* Don't duplicate IDs */
		if (transform) transform(item);

		if (debug)
			console.log("Adding new event: " + item.summary);

		Event.build({
			title: item.summary,
			startdt: item.start,
			enddt: item.end,
			location: item.location,
			blurb: item.description,
			state: 'imported',
			importid: item.uid
		}).save();
	}

	if (debug)
		console.log("Fetching iCal " + url);

	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {

			if (!data.hasOwnProperty(k)) continue;
			if (!data[k].start || data[k].start < new Date()) continue;
			if (filter && filter(data[k])) continue;

			var item = data[k]; // bind locally

			Event.find({ where: { importid: data[k].uid } })
				 .success(saveEvent.bind(data[k]));
		}
	});
}


// = Atom/RSS ================================================= //

// 'Safe' exec - returns an array no matter what, so you can index into it
RegExp.prototype.sexec = function(str) {
	return this.exec(str) || [ ];
};

function monthToInt(s) {
	var first3 = s.slice(0, 3).toLowerCase();
	var a = {
		jan: 1,  feb: 2,  mar: 3,  apr: 4,
		may: 5,  jun: 6,  jul: 7,  aug: 8,
		sep: 9,  oct: 10, nov: 11, dec: 12
	};

	return a[first3] || null;
}

function findDate(base, text) {

	var time = /\d?\d[\.:]\d\d([ap]m)?/.sexec(text)[0] ||
			/\d?\d([ap]m)/.sexec(text)[0];

	var day = /(\d?\d)(th|rd|nd)/.sexec(text)[1];

	var month = /(January|February|March|May|April|June|July|August|September|October|November|December)/.sexec(text)[0] ||
			/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/.sexec(text)[0];

	if (time && day && month) {
		var d = new Date();

		// Months in JS are 0-11, not 1-12
		d.setMonth(monthToInt(month) - 1);

		// Assume year in the future
		if (d.getMonth() < base.getMonth())
			d.setYear(base.getFullYear() + 1);
		else
			d.setYear(base.getFullYear());

		// 'day' is in form 23rd, parseInt will just look at numbers
		d.setDate(parseInt(day));

		var hours = parseInt(time);
		if (/pm/.test(time)) hours += 12;

		var minutes = parseInt(/[\.:](\d\d)/.sexec(time)[1]) || 0;

		d.setHours(hours, minutes, 0, 0);

		return d;
	}

	return null;
}

function addPost(data) {
	// https://github.com/danmactough/node-feedparser#what-is-the-parsed-output-produced-by-feedparser
	// may want to re-add summary, content or image parsing at some point

	if (debug)
		console.log("Adding new post: " + data.title);

	// Build the post
	Post.build({
		id: data.guid,
		title: data.title,
		link: data.link,
		date: data.pubDate,
		feed_url: data.meta.xmlurl,
	}).save();

	// Check if it's like an event
	var date = findDate(data.pubDate, data.description);
	if (!date) return;

	var now = new Date();
	if (date.getTime() < now.getTime() ||
			date.getTime() > (now.getTime() + 7.88923e9)) // 1.578e10 == 3 months in ms
		return;

	Event.find({ where: { importid: data.guid } })
		 .success(function(evt) {
			if (evt != null) return;  // don't import twice

			if (debug)
				console.log("Adding new event: " + data.title);

			Event.build({
				title: data.title,
				startdt: date,
				url: data.link,
				blurb: htmlStrip(data.description, {
					include_script: false,
					include_style: false,
				}),
				state: 'imported',
				importid: data.guid
			}).save();
		 });
}

function fetchFeed(params) {
	var url = params.url;
	var onerror = params.error;

	var req = request(url)
	  , feedparser = new FeedParser();

	// Set error handlers
	req.on('error', onerror);
	feedparser.on('error', onerror);

	req.on('response', function (res) {
		var stream = this;
		if (res.statusCode != 200) return this.emit('error', new Error('Bad status code'));

		stream.pipe(feedparser);
	});

	if (debug)
		console.log("Fetching feed " + url);

	feedparser.on('readable', function() {
		// This is where the action is!
		var stream = this;
		var data;

		while (data = stream.read()) {
			var item = data;	// bind locally
			item.meta.xmlurl = url;	// this doesn't always get saved by the parser

			if (!item.title) return;	// Ignore some items

			if (!item.guid)
				throw new Error("Feed item with no ID");

			Post.find({ where: { id: item.guid } })
				.success(function(post) {
					if (post != null) return;	// Don't duplicate posts
					addPost(item);
				});
		}
	});
}


// = Exports ================================================== //

module.exports = function(models, is_debug) {
	if (is_debug) debug = true;

	Event = models.Event
	Post = models.Post
	Feed = models.Feed

	return {
		ical: fetchICal,
		feed: fetchFeed
	}
}


// = Testrunner =============================================== //

function main() {
	fs = require('fs')
	
	text = fs.readFileSync('tests.json', 'utf8');
	tests = JSON.parse(text);
	
	Date.prototype.toShortISOString = function() {
		function pad(number) {
			if (number < 10)
				return '0' + number;
			else
				return number;
		}
		
		return this.getUTCFullYear() +
			'-' + pad( this.getUTCMonth() + 1 ) +
			'-' + pad( this.getUTCDate() ) +
			'T' + pad( this.getUTCHours() ) +
			':' + pad( this.getUTCMinutes() );
	};
	
	var data = {
		total: 0,
		passed: 0,
	};
	
	console.log("Starting tests...");
	
	for (key in tests) {
		if (!tests.hasOwnProperty(key)) {
			// The current property is not a direct property of p
			continue;
		}
		
		data.total++;
		
		var test = tests[key];
		var base = new Date(test.today);

		var date = findDate(base, test.content);
	
		if (date)
			date = date.toShortISOString();

		if (date == test.result) {
			data.passed++;
		} else {
			console.log("Test '" + key + "' failed:");
			console.log("- content  '" + test.content + "'");
			console.log("- expected '" + test.result + "'");
			console.log("- got      '" + date + "'");
		}
	}
	
	console.log("Tests finished. " + data.passed + "/" + data.total + " passed.");
}

if (require.main == module)
	main()
