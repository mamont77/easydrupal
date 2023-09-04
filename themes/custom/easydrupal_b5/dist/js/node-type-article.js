/**
 * @file
 * easyDrupal functionality.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   *
   * @type {{attach: Drupal.behaviors.articlePageTweaks.attach}}
   */
  Drupal.behaviors.articlePageTweaks = {
    attach: function (context, settings) {

      $('.page-header', context).prepend($('.page-node-type-article .date', context).show());

      let $region_sidebar_second = $('aside.col-sm-3', context),
        $region_sidebar_second_content = $('.region-sidebar-second', context),
        $field_name_field_comments = $('.field--name-field-comments', context);

      let makeMobileHTMLVersion = function () {
        $region_sidebar_second_content.insertBefore($field_name_field_comments);
      };

      let revertMobileHTMLVersion = function () {
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
