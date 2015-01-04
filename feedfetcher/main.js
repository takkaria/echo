#!/usr/bin/env node

var debug = false
var action = { type: "all" }
var moment = require('moment')

function parse_options() {
	// Parse options
	var next;

	for (var i in process.argv) {
		var arg = process.argv[i];

		// process command from previous
		if (next == "skip")	
			next = null;
		else if (next == "ical") {
			action = { type: "ical", url: arg };
			next = null;			
		} else if (next == "feed") {
			action = { type: "feed", url: arg };
			next = null;			
		}

		// check content of args
		else if (arg == "node")
			next = "skip";
		else if (arg == "--debug")
			debug = true;
		else if (arg == "--ical")
			next = "ical";
		else if (arg == "--feed")
			next = "feed";
		else if (arg == "--help" || arg == "-h") {
			console.log("feedfetcher here, reporting for duty");
			console.log("args:");
			console.log(" --debug       Turn debugging output on");
			console.log(" --ical <url>  Fetch specified ical feed");
			console.log(" --feed <url>  Fetch specified RSS/Atom feed");
			console.log(" --help        Display this message");
			process.exit();
		}
	}
}

parse_options();

var models = require('./models')(debug)
var fetch = require('./fetch')(models, debug)

Feed = models.Feed

// ============================================================ //

function feedError(error) {
	Feed.find({ where: { id: this.id } }).success(function (feed) {
		feed.updateAttributes({ errors: error.message });
	});
}

function standByYouTransform(data) {
	// Get original URL
	var info = /reproduced.+?following.+?:\s*(http[^\s]+)/.exec(data.description);
	if (info)
		data.url = info[1];

	// Strip plethora of links
	var result = /(.*)(fevent.*)/.exec(data.description);
	if (result)
		data.description = result[1];

	// Strip repetition at the beginning of the summary
	result = new RegExp("^" + data.summary.toUpperCase().trim() + "(.*)").exec(data.description);
	if (result)
		data.description = result[1];
}

function standByYouFilter(data) {
	if (/Freedom Rebels/.test(data.summary))
		return true;
	return false;
}

// make this into a config file sometime
var calendars = [
	{
		url: 'https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics',
		filter: function(data) {
			day = data.start.getDay(); // 0 = Sunday, 1 = Monday, etc.
			if (day == 1 || day == 2) return true; // Filter out private events on Monday and Tuesday

			return false;
		},
		transform: function(data) {
			data.location = "SubRosa, 27 Lloyd St South, Moss Side, Manchester";
		},
	},
	{
		url: 'http://istandbyyou.org.uk/events/' + moment().format("YYYY-MM") +'/?ical=1',
		filter: standByYouFilter,
		transform: standByYouTransform,
	},
	{
		url: 'http://istandbyyou.org.uk/events/' + moment().add('1', 'month').format("YYYY-MM") +'/?ical=1',
		filter: standByYouFilter,
		transform: standByYouTransform,
	}
];

if (action.type == "ical") {
	fetch.ical({ url: action.url });
} else if (action.type == "feed") {
	fetch.feed({ url: action.url, error: console.log });
} else {
	Feed.findAll().success(function (e) {
		e.forEach(function(feed) {
			fetch.feed({
				url: feed.id,
				error: feedError.bind(feed),
			});
		});
	});

	calendars.forEach(function(data) {
		fetch.ical(data);
	})
}
