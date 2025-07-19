/**
 * @file
 * easyDrupal project functionality.
 */
(function ($, Drupal, once) {

  'use strict';

  /**
   * Slick for Project Slider.
   *
   * @type {{attach: Drupal.behaviors.slick.attach}}
   */
  Drupal.behaviors.slick = {
    attach: function (context, settings) {
      once('projectSlider', '.field--name-field-project-images-media', context)
        .forEach(function (projectSlider) {
          $(projectSlider).slick({
            // slide: '.media',
            slidesToShow: 1,
            slidesToScroll: 1,
            // mobileFirst: true,
            // adaptiveHeight: true,
            // centerMode: true,
            // centerPadding: '10%',
            dots: false,
            arrows: true,
            prevArrow: '<button type="button" data-role="none" class="slick-prev" aria-label="Previous" role="button"><span class="fa-solid fa-chevron-left"></span> Previous</button>',
            nextArrow: '<button type="button" data-role="none" class="slick-next" aria-label="Next" role="button">Next <span class="fa-solid fa-chevron-right"></span></button>',
          });
        });
    }
  };

})(jQuery, Drupal, once);
