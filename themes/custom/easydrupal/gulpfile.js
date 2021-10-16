'use strict';

var gulp = require('gulp'),
  imagemin = require('gulp-imagemin'),
  spritesmith = require('gulp.spritesmith'),
  sass = require('gulp-sass'),
  sourcemaps = require('gulp-sourcemaps'),
  postcss = require('gulp-postcss'),
  autoprefixer = require('autoprefixer'),
  mqpacker = require('css-mqpacker'),
  uncss = require('gulp-uncss');

gulp.task('sprite', function () {
  var spriteData = gulp.src('images/sprite-src/*.png').pipe(spritesmith({
    imgName: 'sprite.png',
    imgPath: '../images/sprite.png',
    cssName: '_sprite.scss',
    padding: 2
  }));
  spriteData.img.pipe(gulp.dest('images'));
  spriteData.css.pipe(gulp.dest('scss'));
});

gulp.task('imagemin', function () {
  return gulp.src('images/*')
    .pipe(imagemin())
    .pipe(gulp.dest('images'))
});

gulp.task('sass', function () {
  gulp.src('./scss/*')
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
        overrideBrowserslist: ['last 3 versions']
      })]))
    // .pipe(uncss({ // Uncomment uncss from time to time and review diff.
    //   html: [
    //     'https://my-project-makedrupaleasy.devel/user/login',
    //     'https://my-project-makedrupaleasy.devel/admin',
    //     'https://my-project-makedrupaleasy.devel/404error',
    //     'https://my-project-makedrupaleasy.devel/',
    //     'https://my-project-makedrupaleasy.devel/about-me',
    //     'https://my-project-makedrupaleasy.devel/projects',
    //     'https://my-project-makedrupaleasy.devel/articles',
    //     'https://my-project-makedrupaleasy.devel/category/make-drupal-easy',
    //     'https://my-project-makedrupaleasy.devel/version/9x-xx',
    //     'https://my-project-makedrupaleasy.devel/feedback',
    //     'https://my-project-makedrupaleasy.devel/contact',
    //     'https://my-project-makedrupaleasy.devel/projects/risk-lookup',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-any-version-how-make-back-top-functionality-own-theme-five-minutes',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-8-9-resolved-following-reasons-prevent-modules-being-uninstalled-fields-pending',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-8-9-how-create-simple-custom-module-breadcrumbs-example-1',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-7-custom-publishing-options-and-devel-generate-together-forever',
    //     'https://my-project-makedrupaleasy.devel/articles/quickly-way-build-beautiful-chart-using-yahoo-finance-highstock',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-8-9-creating-custom-layout-form-your-module-or-theme',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-7-8-9-how-implement-isotope-and-infinitescroll-using-views-only',
    //     'https://my-project-makedrupaleasy.devel/articles/drupal-8-9-getting-acquainted-drupal-console',
    //     'https://my-project-makedrupaleasy.devel/articles/creating-share-buttons-just-urls',
    //     'https://my-project-makedrupaleasy.devel/articles/managing-dependencies-custom-project-not-hosted-packagist-or-drupalorg',
    //     'https://my-project-makedrupaleasy.devel/articles/67-amazing-facts-about-drupal',
    //     'https://my-project-makedrupaleasy.devel/projects/alpha-dog-training'
    //   ],
    //   ignore: [
    //     '.tabs--primary',
    //     '.nav-tabs',
    //     '.addtoany_list']
    // }))
    .pipe(sourcemaps.write('./'))
    .pipe(gulp.dest('./css'));
});

gulp.task('imagemin', function () {
  gulp.watch(['images/**'], ['imagemin']);
});

gulp.task('watch', function () {
  gulp.watch(['images/sprite-src/*.png', './scss/**/*.scss'], ['sprite', 'sass']);
});
