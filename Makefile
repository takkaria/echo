build:
	sass scss/echo.scss css/echo.css

watch:
	sass --watch scss:css -t compact &
