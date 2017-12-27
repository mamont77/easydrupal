/**
 * @file
 * easyDrupal landing page functionality.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   *
   * @type {{attach: Drupal.behaviors.landingPageTweeks.attach}}
   */
  Drupal.behaviors.landingPageTweeks = {
    attach: function (context, settings) {
      if ($('.path-frontpage', context).length === 0) {
        return;
      }

      var $alert = $('.alert', context);
      if ($alert.length > 0) {
        $('.contact .title-wrapper', context).after($alert);
      }

      /* ==============================================
       Smooth Scroll To Anchor
       =============================================== */
      //jQuery for page scrolling feature - requires jQuery Easing plugin
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

      var $projects = $('.section.projects .col-sm-6', context),
        $articles = $('.section.articles .item', context),
        $feedback = $('.section.feedback .item', context),
        $contact = $('.section.contact .contact-form', context);

      $projects.eq(0).addClass('fadeInLeft wow').attr('data-wow-delay', ".1s");
      $projects.eq(1).addClass('fadeInRight wow').attr('data-wow-delay', ".3s");

      $articles.eq(0).addClass('fadeInDown wow').attr('data-wow-delay', ".1s");
      $articles.eq(1).addClass('fadeInDown wow').attr('data-wow-delay', ".3s");

      $feedback.eq(0).addClass('fadeInLeft wow').attr('data-wow-delay', ".1s");
      $feedback.eq(1).addClass('fadeInDown wow').attr('data-wow-delay', ".3s");
      $feedback.eq(2).addClass('fadeInRight wow').attr('data-wow-delay', ".5s");

      $contact.addClass('fadeInUp wow').attr('data-wow-delay', ".1s");

      /* ==============================================
       WOW plugin triggers animate.css on scroll
       =============================================== */
      var wow = new WOW(
        {
          animateClass: 'animated',
          offset: 50,
          mobile: true
        }
      );
      wow.init();

    }
  };

})(jQuery, Drupal, drupalSettings);
