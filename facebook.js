var d = document;
var dq = d.querySelector;
var dqa = d.querySelectorAll;

makeUrl = function(base, params) {
	var url = base + "?";
	for (var prop in params)
		url += prop + "=" + encodeURI(params[prop]) + "&";
	return url;
};

// See more
d.querySelector(".see_more_link").click();

var datetime = /(.*)T(.*)/.exec(d.querySelector("[itemprop=startDate]").getAttribute("content"));

// Make the actual URL.
d.location.href = makeUrl("https://echomanchester.net/event/add", {
	title: dq("#event_header_info a").innerText,
	location: dqa("._5xhk")[1].innerText + ", " + dqa("._5xhp")[1].innerText,
	blurb: dq("#event_description").innerText,
	url: d.location.href,
	date1 = datetime[1],
	time1 = datetime[0],
});
