/**
 * @file
 * easyDrupal landing page functionality.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Common functionality for Landing Page.
   *
   * @type {{attach: Drupal.behaviors.landingPageTweeks.attach}}
   */
  Drupal.behaviors.landingPageTweeks = {
    attach: function (context, settings) {
      var $context = $(context);

      if ($('.path-frontpage', context).length === 0) {
        return;
      }

      var $alert = $('.alert', context);
      if ($alert.length > 0) {
        $('.contact .title-wrapper', context).after($alert);
      }

      /* ==============================================
       Smooth Scroll To Anchor.
       =============================================== */
      // jQuery for page scrolling feature - requires jQuery Easing plugin.
      $('.navbar-nav a').bind('click', function (event) {
        var $anchor = $(this),
          header_offset = 78;
        if ($anchor.attr('href') !== '') {
          if ($('.toolbar-lining').length > 0) {
            header_offset += 39; // Add the height of toolbar.
          }
          $('html, body').stop().animate({
            scrollTop: $($anchor.attr('href')).offset().top - header_offset
          }, 1500, 'easeInOutExpo');
          if ($('.navbar-toggle', context).not('.collapsed').length > 0) {
            $('.navbar-toggle', context).click();
          }
          event.preventDefault();
        }
      });

      $context.find('.section.projects .field-name-field-landing-page-background img').once('projectSectionBehavior').each(function () {
        $(this).attr({
          'width': '493',
          'height': '299'
        });
      });

      $context.find('.section.articles .field-name-field-image img').once('projectSectionBehavior').each(function () {
        $(this).attr({
          'width': '200',
          'height': '133'
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
