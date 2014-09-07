var ical = require('ical')
var models = require('./models')

Event = models.Event

function new_event(data) {
	console.log(data);
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

var e = Event.find({ where: { id: 150 }})
	.success(function(event) {
		console.log(event.title);
		console.log(event.startdt);
	});
