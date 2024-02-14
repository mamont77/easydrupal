'use strict';

const gulp = require('gulp'),
  sass = require('gulp-sass')(require('sass')),
  sourcemaps = require('gulp-sourcemaps'),
  postcss = require('gulp-postcss'),
  autoprefixer = require('autoprefixer');

const publicPath = './dist',
  resourcesPath = './assets';

async function compileSass() {
  return gulp.src([
    resourcesPath + '/scss/*.scss',
  ])
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'expanded',
      precision: 10,
    }).on('error', sass.logError))
    .pipe(postcss([
      autoprefixer({
        overrideBrowserslist: ['last 2 versions'],
        grid: true,
        remove: false,
      }),
    ]))
    .pipe(sourcemaps.write('../sourcemaps'))
    .pipe(gulp.dest(publicPath + '/css'));
}

async function deploy() {
  return gulp.src([
    resourcesPath + '/scss/*.scss',
  ])
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'expanded',
      precision: 10,
    }).on('error', sass.logError))
    .pipe(postcss([
      autoprefixer({
        overrideBrowserslist: ['last 2 versions'],
        grid: true,
        remove: false,
      }),
    ]))
    .pipe(sourcemaps.write('../sourcemaps'))
    .pipe(gulp.dest(publicPath + '/css'));
}

async function imageminTask() {
  const imagemin = (await import('gulp-imagemin')).default;
  return gulp.src(resourcesPath + 'images/*')
    .pipe(imagemin())
    .pipe(gulp.dest(publicPath + '/images'));
}

async function watch() {
  gulp.watch([
    resourcesPath + '/scss/*.scss',
  ], compileSass);
  gulp.watch([resourcesPath + 'images/**'], imageminTask);
}

// Define tasks
gulp.task('sass', compileSass);
gulp.task('deploy', deploy);
gulp.task('imagemin', imageminTask);
gulp.task('watch', watch);

exports.default = watch;
