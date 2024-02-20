/**
 * @file
 * easyDrupal global functionality.
 */
(function ($, Drupal, once) {

  'use strict';

  /**
   * Other Tweaks.
   *
   * @type {{attach: Drupal.behaviors.OtherTweaks.attach}}
   */
  Drupal.behaviors.OtherTweaks = {
    attach: function (context, settings) {
      let $upper = $('.upper', context);

      $(once('upperBehavior', '.upper', context)).each(function () {
        $(this).on('click', function () {
          event.preventDefault();
          $('html, body', context).animate({scrollTop: 0}, 1000);
          return false;
        });
      });

      $(window).scroll(function () {
        if ($(this).scrollTop() > 300) {
          $upper.fadeIn();
          // $upper.show(50);
        }
        else {
          $upper.fadeOut();
          // $upper.hide(50);
        }
      });
    }
  }

})(jQuery, Drupal, once);
