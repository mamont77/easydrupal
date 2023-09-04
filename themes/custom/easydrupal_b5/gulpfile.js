'use strict';

const publicPath = "./dist";
const resourcesPath = "./assets";

var gulp = require('gulp'),
  imagemin = require('gulp-imagemin'),
  spritesmith = require('gulp.spritesmith'),
  sass = require('gulp-sass'),
  sourcemaps = require('gulp-sourcemaps'),
  postcss = require('gulp-postcss'),
  autoprefixer = require('autoprefixer'),
  mqpacker = require('css-mqpacker');

gulp.task('sprite', async function () {
  var spriteData = gulp.src(resourcesPath + 'images/sprite-src/*.png').pipe(spritesmith({
    imgName: 'sprite.png',
    imgPath: '../images/sprite.png',
    cssName: '_sprite.scss',
    padding: 2
  }));
  spriteData.img.pipe(gulp.dest('images'));
  spriteData.css.pipe(gulp.dest('scss'));
});

gulp.task('imagemin', async function () {
  return gulp.src(resourcesPath + '/images/*')
    .pipe(imagemin())
    .pipe(gulp.dest(publicPath + '/images'))
});

gulp.task('sass', async function () {
  return gulp.src([resourcesPath + '/scss/*.scss'])
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'expanded',
      precision: 10
    }).on('error', sass.logError))
    .pipe(postcss([
      mqpacker({
        sort: true
      }),
      autoprefixer({
        overrideBrowserslist: ['last 2 versions']
      })]))
    .pipe(sourcemaps.write('../sourcemaps'))
    .pipe(gulp.dest(publicPath + '/css'));
});

gulp.task('watch', function () {
  gulp.watch(['images/sprite-src/*.png', resourcesPath + '/scss/**/*.scss'], ['sprite', 'imagemin', 'sass']);
});
