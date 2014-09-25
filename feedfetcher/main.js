var html_strip = require('htmlstrip-native');

var models = require('./models')
var fetch = require('./fetch')

Event = models.Event

// 'Safe' exec - returns an array no matter what, so you can index into it
RegExp.prototype.sexec = function(str) {
	return this.exec(str) || [ ];
};

function add_event(data) {
	Event.build({
		title: data.summary,
		startdt: data.start,
		enddt: data.end,
		location: data.location,
		blurb: data.description,
		state: 'imported',
		importid: data.uid
	}).save();
};

fetch.ical({
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
});

// ============================================================ //

Post = models.Post;
Feed = models.Feed;

function monthToInt(s) {
	var first3 = s.slice(0, 3).toLowerCase();
	var a = {
		jan: 1,  feb: 2,  mar: 3,  apr: 4,
		may: 5,  jun: 6,  jul: 7,  aug: 8,
		sep: 9,  oct: 10, nov: 11, dec: 12
	};

	return a[first3] || null;
}

function find_date(base, text) {

	var time = /\d?\d[\.:]\d\d([ap]m)?/.sexec(text)[0] || 
			/\d?\d([ap]m)/.sexec(text)[0];

	var day = /(\d?\d)(th|rd|nd)/.sexec(text)[1];

	var month = /(January|February|March|May|April|June|July|August|September|October|November|December)/.sexec(text)[0] ||
			/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/.sexec(text)[0];

	if (time && day && month) {
		var d = new Date();

		d.setMonth(monthToInt(month) - 1);
		if (d.getMonth() < base.getMonth())
			d.setYear(base.getYear() + 1);
		else
			d.setYear(base.getYear());

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

	if (!data.guid)
		throw new Error("Feed item with no ID");

	// Build the post
	var p = Post.build({
		id: data.guid,
		title: data.title,
		link: data.link,
		date: data.pubDate,
		feed_url: data.meta.xmlurl,
	});

	// Check if it's like an event
	var date = find_date(data.pubDate, data.description);
	if (!date) return;

	var now = new Date();
	if (date.getTime() < now.getTime() ||
			date.getTime() > (now.getTime() + 7.88923e9)) // 1.578e10 == 3 months in ms
		return;

	Event.find({ where: { importid: data.guid } })
		 .success(function(evt) {
			if (evt != null) return;

			Event.build({
				title: data.title,
				startdt: date,
				url: data.link,
				blurb: html_strip.html_strip(data.description, {
					include_script: false,
					include_style: false,
				}),
				state: 'imported',
				importid: data.guid
			}).save();
		 });
}

/* fetch.feed({
	url: 'http://manchestersocialcentre.org.uk/feed/',
	action: add_post,
	error: console.log,
}) */

Feed.findAll().success(function (e) {
	e.forEach(function(feed) {
		fetch.feed({
			url: feed.feed_url,
			action: add_post,
			error: function(error) {
				Feed.find({ where: { feed_url: feed.feed_url } }).success(function (feed) {
					feed.updateAttributes({ errors: error.message });
				});
			},
		});
	});
});
