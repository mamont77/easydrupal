/**
 * @file
 * easyDrupal landing page functionality.
 */
(function ($, Drupal, once) {

  'use strict';

  /**
   * Common functionality for Landing Page.
   *
   * @type {{attach: Drupal.behaviors.landingPageTweeks.attach}}
   */
  Drupal.behaviors.landingPageTweeks = {
    attach: function (context, settings) {
      let $context = $(context);

      $('.navbar-nav a').bind('click', function (event) {
        let $anchor = $(this),
          header_offset = 78;
        if ($anchor.attr('href') !== '') {
          if ($('.toolbar-lining').length > 0) {
            header_offset += 39; // Add the height of toolbar.
          }
          $('html, body', context).stop().animate({
            scrollTop: $($anchor.attr('href')).offset().top - header_offset
          }, 1500);
          if ($('.navbar-toggle', context).not('.collapsed').length > 0) {
            $('.navbar-toggle', context).click();
          }
          event.preventDefault();
        }
      });

      // $(once('projectSectionBehavior', '.section.projects .field-name-field-landing-page-backgro-media img', context)).each(function () {
      //   $(this).attr({
      //     'width': '493',
      //     'height': '299'
      //   });
      // });
      //
      // $(once('articleSectionBehavior', '.section.articles .field-name-field-image-media img', context)).each(function () {
      //   $(this).attr({
      //     'width': '200',
      //     'height': '133'
      //   });
      // });
      //
      //
      // $(once('clientSectionBehavior', '.section.clients .field-name-field-image-media img', context)).each(function () {
      //   $(this).attr({
      //     'width': '212',
      //     'height': '80'
      //   });
      // });

      // @todo: Feedback images 150x150.
    }
  };

})(jQuery, Drupal, once);
