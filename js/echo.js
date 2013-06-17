$(function() {

$("input.ajax").click(function(e) {
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