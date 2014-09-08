var ical = require('ical')
var models = require('./models')

Event = models.Event

function new_event(data) {

	if (!data.start || data.start < new Date()) return;

	Event.find({ where: { importid: data.uid } }).success(function() {

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

	});
}

function fetch_feed(url) {
	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {
			if (!data.hasOwnProperty(k)) continue;
			new_event(data[k]);
		}
	})
}

fetch_feed('https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics')
