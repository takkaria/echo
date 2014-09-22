var ical = require('ical')
var models = require('./models')

Event = models.Event

function calendar(params) {
	var url = params.url;
	var filter = params.filter;
	var action = params.action;

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

	e.save()
}

calendar({
	url: 'https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics',
	filter: function(data) {
		day = data.start.getDay() // 0 = Sunday, 1 = Monday, etc.
		if (day == 1 || day == 2) return true; // Filter out private events on Monday and Tuesday

		return false;
	},
	action: add_event
})

// ============================================================ \\

var FeedParser = require('feedparser')
var request = require('request');

function add_post(e) {
	// https://github.com/danmactough/node-feedparser#what-is-the-parsed-output-produced-by-feedparser

//	console.log(e);
}

function feed_error() {
}

function feed(params) {
	var url = params.url;

	var req = request(url)
	  , feedparser = new FeedParser();

	// Set error handlers
	req.on('error', feed_error);
	feedparser.on('error', feed_error);

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
			add_post(item);
		}
	});
}

/* feed({
	url: 'http://manchestersocialcentre.org.uk/feed/'
}) */
