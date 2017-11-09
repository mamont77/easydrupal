module.exports = function (grunt) {

  'use strict';

  // Time how long tasks take. Can help when optimizing build times.
  require('time-grunt')(grunt);

  // Automatically load required grunt tasks.
  require('jit-grunt')(grunt, {
    useminPrepare: 'grunt-usemin',
    sprite: 'grunt-spritesmith'
  });

  // Configurable.
  var config = {};

  // Project configuration.
  grunt.initConfig({

    // Config.
    config: config,

    // Create sprite.
    sprite: {
      development: {
        src: 'images/sprite-src/*.png',
        dest: 'images/sprite.png',
        destCss: 'sass/_sprite.scss',
        padding: 2
      }
    },

    // Optimize images.
    imagemin: {
      dynamic: {
        files: [{
          expand: true,
          cwd: 'images/',
          src: ['**/*.{png,jpg,gif}'],
          dest: 'images/'
        }]
      }
    },

    // Compiles Sass to CSS and generates necessary files if requested.
    sass: {
      development: {
        options: {
          style: 'expanded',
          unixNewlines: true,
          sourcemap: 'file'
        },
        files: [{
          expand: true,
          cwd: 'sass',
          src: ['*.scss'],
          dest: 'css',
          ext: '.css'
        }]
      }
    },
    autoprefixer: {
      dist: {
        options: {
          map: true,
          browsers: [
            'last 3 versions'
          ]
        },
        files: {
          'css/style.css': 'css/style.css',
          'css/landing-page.css': 'css/landing-page.css'
        }
      }
    },

    // uglify: {
    //   options: {
    //     mangle: false
    //   },
    //   my_target: {
    //     options: {
    //       sourceMap: true,
    //       sourceMapName: 'js/sourcemap.map.js'
    //     },
    //     files: {
    //       'js/scripts.min.js': ['js/scripts.js']
    //     }
    //   }
    // },

    // Watches files for changes and runs tasks based on the changed files.
    watch: {
      sass: {
        files: ['sass/*.scss'],
        tasks: ['sass:development', 'autoprefixer']
      },
      sprite: {
        files: ['images/sprite-src/*.png'],
        tasks: ['sprite']
      },
      gruntfile: {
        files: ['Gruntfile.js']
      },
      js: {
        files: ['js/*.js'],
        // tasks: ['uglify'],
        options: {
          livereload: true
        }
      }
    }
  });

  // Force load tasks which can not be loaded by 'jit-grunt' plugin.
  grunt.loadNpmTasks('grunt-notify');

  grunt.registerTask('default', ['watch']);
};
