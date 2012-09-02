/ {
	connect to db
	get events (in next 7 days & approved)
	display using event.tmpl
}

/event_add GET {
	display form
}

/event_add POST {
	validate fields
	if not valid:
		throw back to user

	else:
		user = user record from email

		if user.banned:
			reject outright
		if user.approved:
			validate straight away
		if not user.validated:
			send email asking to confirm email address
}

/admin {

}
