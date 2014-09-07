var sequelize = require('sequelize')

var events = new sequelize('', '', '', {
	dialect: 'sqlite',
	storage: '../db/events.sqlite'
})

var Event = events.define('event', {
	title: sequelize.TEXT,
	startdt: sequelize.DATE,
	enddt: sequelize.DATE
}, {
	timestamps: false,
	underscored: true
})

exports.Event = Event