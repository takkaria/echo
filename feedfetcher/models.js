var sequelize = require('sequelize');

var events = new sequelize('', '', '', {
	dialect: 'sqlite',
	storage: '../db/events.sqlite'
});

var feeds = new sequelize('', '', '', {
	dialect: 'sqlite',
	storage: '../db/feeds.sqlite'
});

exports.Event = events.define('event', {
	id: { type: sequelize.INTEGER, primaryKey: true },
	title: { type: sequelize.TEXT },
	startdt: { type:  sequelize.DATE },
	enddt: { type: sequelize.DATE },
	location: { type: sequelize.TEXT },
	blurb: { type: sequelize.TEXT },
	url: { type: sequelize.TEXT },
	type: { type: sequelize.TEXT },
	cost: { type: sequelize.TEXT },
	state: {
		type: sequelize.ENUM,
		values: [ 'submitted', 'approved', 'imported' ]
	},
	email: { type: sequelize.TEXT },
	key: { type: sequelize.TEXT },
	importid: { type: sequelize.TEXT },
}, {
	timestamps: false,
	createdAt: false,
	underscored: true
});

exports.Post = feeds.define('post', {
	id: { type: sequelize.TEXT, primaryKey: true },
	title: { type: sequelize.TEXT },
	link: { type: sequelize.TEXT },
	title: { type: sequelize.TEXT },
	date: { type: sequelize.DATE },
	summary: { type: sequelize.TEXT },
	image: { type: sequelize.TEXT },
	feed_url: { type: sequelize.TEXT },
	eventish: { type: sequelize.BOOLEAN },
	hidden: { type: sequelize.INTEGER }
});

exports.Feed = feeds.define('feed', {
	feed_url: { type: sequelize.TEXT, primaryKey: true },
	site_url: { type: sequelize.TEXT },
	title: { type: sequelize.TEXT },
	error: { type: sequelize.TEXT },
});
