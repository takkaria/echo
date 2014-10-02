var makeUrl = function(base, params) {
	var url = base + "?";
	for (var prop in params)
		url += prop + "=" + encodeURIComponent(params[prop]) + "&";
	return url;
};

d = document;
d.qs = d.querySelector;
d.qsa = d.querySelectorAll;

// See more
var c = d.qs(".see_more_link");
if (c)
	c.click();

var dt = new Date(d.qs("[itemprop=startDate]").getAttribute("content"));

// Make the actual URL.
d.location.href = makeUrl("https://echomanchester.net/event/add", {
	title: d.qs("#event_header_info a").innerText,
	location: d.qsa("._5xhk")[1].innerText + ", " + d.qsa("._5xhp")[1].innerText,
	blurb: d.qs("#event_description").innerText,
	url: d.location.href,
	date1: dt.getFullYear() + "-" + (dt.getMonth() + 1) + "-" + dt.getDate(),
	time1: dt.getHours() + ":" + dt.getMinutes(),
});
