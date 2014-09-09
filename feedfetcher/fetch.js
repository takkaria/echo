var ical = require('ical')
var models = require('./models')

Event = models.Event

function fetch_feed(params) {
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

fetch_feed({
	url: 'https://www.google.com/calendar/ical/7etn2k6kvovrugd1hapue7ghrc%40group.calendar.google.com/public/basic.ics',
	filter: function(data) {
		day = data.start.getDay() // 0 = Sunday, 1 = Monday, etc.
		if (day == 1 || day == 2) return false; // Filter out private events on Monday and Tuesday

		return true;
	},
	action: add_event
})
