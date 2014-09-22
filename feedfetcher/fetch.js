var ical = require('ical')
var models = require('./models')

Event = models.Event
Post = models.Post

// 'Safe' exec - returns an array no matter what, so you can index into it
RegExp.prototype.sexec = function(str) {
	return this.exec(str) || [ ];
};

function calendar(params) {
	var url = params.url;
	var filter = params.filter;
	var action = params.action;
	var transform = params.transform;

	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {

			console.log("ID: " + k);

			if (!data.hasOwnProperty(k)) continue;
			if (!data[k].start || data[k].start < new Date()) continue;
			if (filter && filter(data[k])) continue;

			/* Wrapper function to deal with JS's scoping rules */
			function succeed(data) {
				return function(evt) {
					if (evt != null) return;   /* Don't duplicate IDs */
					if (transform) transform(data);
					action(data);
				}
			}

			Event.find({ where: { importid: data[k].uid } })
				 .success(succeed(data[k]));
		}
	})
}

function add_event(data) {
	var e = Event.build({
		title: data.summary,
		startdt: data.start,
		enddt: data.end,
		location: data.location,
		blurb: data.description,
		state: 'imported',
		importid: data.uid
	});

	e.save();
}

/*
calendar({
	url: 'https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics',
	filter: function(data) {
		day = data.start.getDay() // 0 = Sunday, 1 = Monday, etc.
		if (day == 1 || day == 2) return true; // Filter out private events on Monday and Tuesday

		return false;
	},
	transform: function(data) {
		data.location = "Subrosa";
	},
	action: add_event
})
*/

// ============================================================ \\

var FeedParser = require('feedparser')
var request = require('request');

function feed(params) {
	var url = params.url;
	var action = params.action;
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

	feedparser.on('readable', function() {
		// This is where the action is!
		var stream = this
		  , meta = this.meta // **NOTE** the "meta" is always available in the context of the feedparser instance
		  , item
		
		while (item = stream.read()) {
			// Filter some items
			if (!item.title) continue;
			action(item);
		}
	});
}

function monthToInt(s) {
	var first3 = s.slice(0, 3).toLowerCase();
	var a = {
		jan: 1,  feb: 2,  mar: 3,  apr: 4,
		may: 5,  jun: 6,  jul: 7,  aug: 8,
		sep: 9,  oct: 10, nov: 11, dec: 12
	};

	return a[first3] || null;
}

function find_date(text) {

	var time = /\d?\d[\.:]\d\d([ap]m)?/.sexec(text)[0] || 
			/\d?\d([ap]m)/.sexec(text)[0];

	var day = /(\d?\d)(th|rd|nd)/.sexec(text)[1];

	var month = /(January|February|March|May|April|June|July|August|September|October|November|December)/.sexec(text)[0] ||
			/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/.sexec(text)[0];

	if (time && day && month) {
		var d = new Date();
		d.setMonth(monthToInt(month) - 1);
		d.setDate(parseInt(day));

		var t = parseInt(time);
		if (/pm/.test(time)) t += 12;

		var m = parseInt(/[\.:](\d\d)/.sexec(time)[1]) || 0;

		d.setHours(t, m, 0, 0);

		return d;
	}
}

function add_post(data) {
	// https://github.com/danmactough/node-feedparser#what-is-the-parsed-output-produced-by-feedparser
	// may want to re-add summary, content or image parsing at some point

	// Build the post
	var p = Post.build({
		id: data.guid,
		title: data.title,
		link: data.link,
		date: data.pubDate,
		feed_url: data.meta.xmlurl,
	});

	// Check if it's like an event
	var date = find_date(data.description);
	if (!date) return;

	console.log(date);

	var e = Event.build({
		title: data.title,
		startdt: null,
		url: data.link,
		blurb: data.description,
		state: 'imported',
		importid: "???" // XXX work out some way to sort this out
	});
}

function feed_error() {
	// UPDATE feeds SET errors=:errors WHERE feed_url=:url
}

feed({
	url: 'http://manchestersocialcentre.org.uk/feed/',
	action: add_post,
	error: feed_error,
})
