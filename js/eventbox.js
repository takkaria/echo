function show_event(baseurl, id, e) {
	var show = function() {
		var info = $("#eventinfo");
		info.css('top', e.pageY + "px");

		/* Don't overflow the page */
		if (e.pageX + info.outerWidth() > $(window).width())
			info.css('left', $(window).width() - info.outerWidth() - 10);
		else
			info.css('left', e.pageX + "px");

		$(".eventbox .close").click(function() {
			$("#eventinfo").hide();
		});

		info.show();
	};

	$("#eventinfo").load(baseurl + "/event/" + id + " .eventbox", show);
}