Echo README
===========

# Please note that this is unmaintained and badly written! It's been rewritten using node, see takkaria/echojs.

To set this up you will need to:

## Dependencies

PHP 5.4
PHP's SQLite module

## Installation

Initialise the databases.  Create the databases from SQL:

```
mkdir db
sqlite3 db/events.sqlite < sql-events.txt
sqlite3 db/feeds.sqlite < sql-feeds.txt
```

Make sure they are writable by the web server.  This might mean doing something like:

```
chgrp -R www-data db
chmod -R g+w db
```

Move the right files into place.

The following need to be in a web-accessible folder:
	* css
	* images
	* js
	* index.php

The following do not:
	* lib
	* feedfetch.php
	* templates

Set up the config file.  An initial config file is in the package.  It should be called 'echo.ini'.

Set up a temp directory for F3:

```
mkdir temp
chgrp -R www-data temp
chmod -R g+w temp
```

Set up web rewrite rules.

Install the Node modules:

- htmlstrip-native
- sequelize
- sequelize-sqlite (?)
- sqlite3
- ical
- feedparser
- request


To develop, you will need sass, to build CSS files from the source SCSS files.
