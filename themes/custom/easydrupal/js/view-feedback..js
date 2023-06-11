/**
 * @file
 * easyDrupal functionality.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Makes Feedback more good.
   *
   * @param $root_element_prefix
   */
  $.makeFeedbackMoreBeautifulStep1 = function ($root_element_prefix) {
    let $feedback_projects_urls = $root_element_prefix.find('.field-name-field-project-title');

    // Add commas after each project in the feedback section.
    $feedback_projects_urls.find('a:not(:last-child)').after(', ');
  };

  /**
   * Makes Feedback more good.
   *
   * @param $root_element_prefix
   */
  $.makeFeedbackMoreBeautifulStep2 = function ($root_element_prefix) {
    $root_element_prefix.each(function () {
      let $feedback_titles = $(this).find('.field-name-node-title'),
        $feedback_projects_about = $(this).find('.about'),
        $feedback_projects_wrapper = $(this).find('.project-info'),
        $feedback_projects_blockquotes = $(this).find('blockquote');

      // Make titles the same height in the feedback section.
      $.equalHeight($feedback_titles);
      $.equalHeight($feedback_projects_about);
      $.equalHeight($feedback_projects_wrapper);
      $.equalHeight($feedback_projects_blockquotes);
    });
  };

  /**
   * Use usual version for mobile.
   *
   * @param $root_element_prefix
   */
  $.revertFeedbackMoreBeautiful = function ($root_element_prefix) {
    $root_element_prefix.find('.field-name-node-title').height('auto');
    $root_element_prefix.find('.about').height('auto');
    $root_element_prefix.find('.project-info').height('auto');
    $root_element_prefix.find('blockquote').css('height', '');
  };

  /**
   * Feedback Page Tweaks.
   *
   * @type {{attach: Drupal.behaviors.feedbackPageTweaks.attach}}
   */
  Drupal.behaviors.feedbackPageTweaks = {
    attach: function (context, settings) {
      if ($('.view-feedback ', context).length === 0 && $('.section.feedback ', context).length === 0) {
        return;
      }

      let $feedback = $('.view-feedback .row, .section.feedback', context);

      $.makeFeedbackMoreBeautifulStep1($feedback);

      if ($.getPageSize()[0] >= 768) {
        $.makeFeedbackMoreBeautifulStep2($feedback);
      }
      $(window).resize(function () {
        if ($.getPageSize()[0] >= 768) {
          $.makeFeedbackMoreBeautifulStep2($feedback);
        }
        else {
          $.revertFeedbackMoreBeautiful($feedback);
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
