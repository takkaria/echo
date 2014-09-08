var ical = require('ical')
var models = require('./models')

Event = models.Event

function new_event(data) {
	// Ignore if already imported
	if (Event.count({ where: { importid: data.id } }))
		return;

	var e = Event.build({
		title: data.title,
		startdt: data.start.getDate(),
		enddt: data.end.getDate(),
		location: data.location,
		blurb: data.summary,
		state: 'imported',
		importid: data.id
	});

	e.save();	
}

function fetch_feed(url) {
	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {
			if (!data.hasOwnProperty(k)) continue;
			new_event(data[k]);
		}
	});
}

// fetch_feed('https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics')

var e = Event.find(150)
	.success(function(event) {
		console.log(event.title);
		console.log(event.startdt);
	});
