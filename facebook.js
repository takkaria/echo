var makeUrl = function(base, params) {
	var url = base + "?";
	for (var prop in params)
		url += prop + "=" + encodeURIComponent(params[prop]) + "&";
	return url;
};

var d = document;

// See more
var c = d.querySelector(".see_more_link");
if (c)
	c.click();

var d = new Date(/(.*)T(.*)/.exec(d.querySelector("[itemprop=startDate]").getAttribute("content")));

// Make the actual URL.
d.location.href = makeUrl("https://echomanchester.net/event/add", {
	title: d.querySelector("#event_header_info a").innerText,
	location: d.querySelectorAll("._5xhk")[1].innerText + ", " + d.querySelectorAll("._5xhp")[1].innerText,
	blurb: d.querySelector("#event_description").innerText,
	url: d.location.href,
	date1: d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(),
	time1: d.getHours() + ":" + d.getMinutes(),
});
