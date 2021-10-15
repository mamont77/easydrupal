/**
 * @file
 * easyDrupal functionality.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   *
   * @type {{attach: Drupal.behaviors.articlePageTweeks.attach}}
   */
  Drupal.behaviors.articlePageTweeks = {
    attach: function (context, settings) {

      $('.page-header', context).prepend($('.page-node-type-article .date', context));

      var $region_sidebar_second = $('aside.col-sm-3', context),
        $region_sidebar_second_content = $('.region-sidebar-second', context),
        $field_name_field_comments = $('.field--name-field-comments', context);

      var makeMobileHTMLVersion = function () {
        $region_sidebar_second_content.insertBefore($field_name_field_comments);
      };

      var revertMobileHTMLVersion = function () {
        $region_sidebar_second_content.appendTo($region_sidebar_second);
      };

      if ($.getPageSize()[0] <= 767) {
        makeMobileHTMLVersion();
      }
      $(window).resize(function () {
        if ($.getPageSize()[0] <= 767) {
          makeMobileHTMLVersion();
        }
        else {
          revertMobileHTMLVersion();
        }
      });

    }
  };

})(jQuery, Drupal, drupalSettings);
