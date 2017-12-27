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
        destCss: 'scss/_sprite.scss',
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
          cwd: 'scss',
          src: ['*.scss'],
          dest: 'css',
          ext: '.css'
        }]
      }
    },
    postcss: {
      options: {
        map: {
          inline: false
        },
        processors: [
          require('autoprefixer')({browsers: 'last 3 versions'})
        ]
      },
      dist: {
        src: ['css/landing-page.css', 'css/style.css']
      }
    },
    // Watches files for changes and runs tasks based on the changed files.
    watch: {
      sprite: {
        files: ['images/sprite-src/*.png'],
        tasks: ['sprite']
      },
      sass: {
        files: ['scss/*.scss'],
        tasks: ['sass:development']
      },
      postcss: {
        files: ['css/*.css'],
        tasks: ['postcss']
      },
      gruntfile: {
        files: ['Gruntfile.js']
      },
      js: {
        files: ['js/*.js'],
        options: {
          livereload: true
        }
      }
    }
  });

  // Force load tasks which can not be loaded by 'jit-grunt' plugin.
  grunt.loadNpmTasks('grunt-notify');
  grunt.loadNpmTasks('grunt-postcss');

  grunt.registerTask('default', ['watch']);
};
