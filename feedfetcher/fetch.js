var ical = require('ical');
var FeedParser = require('feedparser')
var request = require('request');

exports.ical = function(params) {
	var url = params.url;
	var filter = params.filter;
	var action = params.action;
	var transform = params.transform;

	ical.fromURL(url, {}, function(err, data) {
		for (var k in data) {

			if (!data.hasOwnProperty(k)) continue;
			if (!data[k].start || data[k].start < new Date()) continue;
			if (filter && filter(data[k])) continue;

			var item = data[k]; // bind locally

			Event.find({ where: { importid: data[k].uid } })
				 .success(function(event) {
					if (event != null) return;   /* Don't duplicate IDs */
					if (transform) transform(item);
					action(item);
				});
		}
	});
}

exports.feed = function(params) {
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
		var stream = this;
		var data;

		while (data = stream.read()) {
			var item = data;	// bind locally

			if (!item.guid)
				throw new Error("Feed item with no ID");

			Post.find({ where: { id: item.guid } })
				.success(function(post) {
					if (post != null) return;	/* Don't duplicate posts */
					action(item);
				});
		}
	});
}
