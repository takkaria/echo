var ical = require('ical')
var models = require('./models')

Event = models.Event

function ical(params) {
	var url = params.url;
	var filter = params.filter;
	var action = params.action;

	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {
			if (!data.hasOwnProperty(k)) continue;
			if (!filter || !filter(data[k])) continue;
			if (!data.start || data.start < new Date()) continue;

			Event.find({ where: { importid: data.uid } }).success(function() {
				action(data[k]);
			});
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
	})

	console.log(e.values)
//	e.save()
}

ical({
	url: 'https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics',
	filter: function(data) {
		day = data.start.getDay() // 0 = Sunday, 1 = Monday, etc.
		if (day == 1 || day == 2) return false; // Filter out private events on Monday and Tuesday

		return true;
	},
	action: add_event
})

// ============================================================ \\

var FeedParser = require('feedparser')
var request = require('request');

function add_post() {
	// https://github.com/danmactough/node-feedparser#what-is-the-parsed-output-produced-by-feedparser
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

feed({
	url: 'http://manchestersocialcentre.org.uk/feed/'
})
