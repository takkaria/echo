module.exports = function(grunt) {

	// Project configuration.
	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),
	
		concat: {
			main: {
				src: [ 'js/src/jquery*.js', 'js/src/*.js' ],
				dest: 'js/<%= pkg.name %>.js',
			}
		},

		uglify: {
			options: {
				banner: '/*! <%= pkg.name %> <%= grunt.template.today("dd-mm-yyyy") %> */\n',
			},
			dist: {
				files: { 'js/<%= pkg.name %>.js': 'js/<%= pkg.name %>.js' }
			}
		},

		sass: {
			main: {
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
				tasks: ['concat']
			},
			scss: {
				files: ['<%= sass.main.files %>'],
				tasks: ['sass']
			}
		}
	});

	// Load the plugin that provides the "uglify" task.
	grunt.loadNpmTasks('grunt-contrib-uglify');
	grunt.loadNpmTasks('grunt-contrib-watch');
	grunt.loadNpmTasks('grunt-contrib-concat');
	grunt.loadNpmTasks('grunt-contrib-sass');
	grunt.loadNpmTasks('grunt-contrib-copy');

	// Default task(s).
	grunt.registerTask('default', ['concat', 'sass']);
	grunt.registerTask('dist', ['concat', 'uglify', 'sass']);
};
