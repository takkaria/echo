module.exports = function(grunt) {

	// Project configuration.
	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),
	
		concat: {
			dist: {
				src: [ 'src/js/*.js' ],
				dest: 'js/<%= pkg.name %>.js'
			}
		},

		uglify: {
			options: {
				banner: '/*! <%= pkg.name %> <%= grunt.template.today("dd-mm-yyyy") %> */\n',
			},
			dist: {
				files: { 'js/<%= pkg.name %>.min.js': ['<%= concat.dist.dest %>'] }
			}
		},

		sass: {
			dist: {
				options: {
					'style': 'compact',
				},
				files: {
					'css/echo.css': 'scss/echo.scss'
				}
			}
		},

		watch: {
			scripts: {
				files: ['<%= concat.dist.src %>'],
				tasks: ['concat', 'uglify']
			},
			scss: {
				files: ['<%= sass.dist.files %>'],
				tasks: ['sass']
			}
		}
	});

	// Load the plugin that provides the "uglify" task.
	grunt.loadNpmTasks('grunt-contrib-uglify');
	grunt.loadNpmTasks('grunt-contrib-watch');
	grunt.loadNpmTasks('grunt-contrib-concat');
	grunt.loadNpmTasks('grunt-contrib-sass');

	// Default task(s).
	grunt.registerTask('default', ['concat', 'uglify', 'sass']);
};