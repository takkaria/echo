var echo = {
	registered: false,

	register_clickoff: function() {
		if (echo.registered) return;

		echo.registered = true;
		$(document).click(function(e) {
			var box = $("#eventinfo");
			if (box && box.offset() && (
						e.pageX < box.offset().left ||
						e.pageX > box.offset().left+box.outerWidth() ||
						e.pageY < box.offset().top ||
						e.pageY > box.offset().top+box.outerHeight()
					)) {
				box.detach();
			}

			return true;
		});
	},

	show_event: function(id, ev) {
		// Clean up previous boxes
		$("#eventinfo").detach();

		// Register click-off handler
		echo.register_clickoff();

		// Create us a new one
		var html = '<div id="eventinfo" class="eventbox"></div>';
		$('body').append(html);

		// Request the info
		$("#eventinfo").hide().load("/event/" + id, function() {
			var info = $("#eventinfo");
			info.css('top', ev.pageY + "px");

			/* Don't overflow the page */
			if (ev.pageX + info.outerWidth() > $(window).width())
				info.css('left', $(window).width() - info.outerWidth() - 10);
			else
				info.css('left', ev.pageX + "px");

			/* Don't overflow the heightwise either */
			if (ev.pageY + info.outerHeight() > $(document).height())
				info.css('top', $(document).height() - info.outerHeight() - 10);
			else
				info.css('top', ev.pageY + "px");

			$("#eventinfo .close").click(function() {
				$("#eventinfo").detach();
			});

			info.show();
		});
	}
};

$(function() {

	$("body").on("click", "button.ajax", function(e) {
		var url = this.form.action;
		var method = this.form.method;

		$.ajax(url, {
			type: method,
			error: function(jqXHR, textstatus, error) {
				alert("Error " + textstatus + ": " + error);
			},
			success: function() {
				alert("Successful.");
				document.location = document.location;
			}
		})

		return false;
	});

});