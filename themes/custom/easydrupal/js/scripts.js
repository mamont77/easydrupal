/**
 * @file
 * easyDrupal functionality.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   *
   * @param group
   */
  $.equalHeight = function (group) {
    var tallest = 0;
    group.removeAttr('style').each(function () {
      var thisHeight = $(this).height();
      if (thisHeight > tallest) {
        tallest = thisHeight;
      }
    });
    group.height(tallest);
  };

  /**
   *
   * @returns {*[]}
   */
  $.getPageSize = function () {
    var xScroll, yScroll, windowWidth, windowHeight, pageWidth, pageHeight;

    if (window.innerHeight && window.scrollMaxY) {
      xScroll = document.body.scrollWidth;
      yScroll = window.innerHeight + window.scrollMaxY;
    } else if (document.body.scrollHeight > document.body.offsetHeight) { // all but Explorer Mac
      xScroll = document.body.scrollWidth;
      yScroll = document.body.scrollHeight;
    } else if (document.documentElement && document.documentElement.scrollHeight > document.documentElement.offsetHeight) { // Explorer 6 strict mode
      xScroll = document.documentElement.scrollWidth;
      yScroll = document.documentElement.scrollHeight;
    } else { // Explorer Mac...would also work in Mozilla and Safari
      xScroll = document.body.offsetWidth;
      yScroll = document.body.offsetHeight;
    }

    if (self.innerHeight) { // all except Explorer
      windowWidth = self.innerWidth;
      windowHeight = self.innerHeight;
    } else if (document.documentElement && document.documentElement.clientHeight) { // Explorer 6 Strict Mode
      windowWidth = document.documentElement.clientWidth;
      windowHeight = document.documentElement.clientHeight;
    } else if (document.body) { // other Explorers
      windowWidth = document.body.clientWidth;
      windowHeight = document.body.clientHeight;
    }

    // for small pages with total height less then height of the viewport
    if (yScroll < windowHeight) {
      pageHeight = windowHeight;
    } else {
      pageHeight = yScroll;
    }

    // for small pages with total width less then width of the viewport
    if (xScroll < windowWidth) {
      pageWidth = windowWidth;
    } else {
      pageWidth = xScroll;
    }

    return [pageWidth, pageHeight, windowWidth, windowHeight];
  };

  /**
   *
   * @param root_element_prefix
   * @param context
   */
  $.makeFeedbackMoreBeautifulStep1 = function (root_element_prefix, context) {
    var $feedback_projects_urls = $(root_element_prefix + ' .field-name-field-project-title', context);

    // Add commas after each project in the feedback section.
    $feedback_projects_urls.find('a:not(:last-child)').after(', ');
  };

  /**
   *
   * @param root_element_prefix
   * @param context
   */
  $.makeFeedbackMoreBeautifulStep2 = function (root_element_prefix, context) {
    $(root_element_prefix).each(function () {
      var $feedback_titles = $(this).find('.field-name-node-title'),
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
   *
   * @param root_element_prefix
   */
  $.revertFeedbackMoreBeautiful = function (root_element_prefix) {
    $(root_element_prefix).find('.field-name-node-title').height('auto');
    $(root_element_prefix).find('.about').height('auto');
    $(root_element_prefix).find('.project-info').height('auto');
    $(root_element_prefix).find('blockquote').css('height', '');
  };


  /**
   * Open navnar by hover.
   *
   * @type {{attach: attach}}
   */
  Drupal.behaviors.showNavbarByHover = {
    attach: function (context, settings) {
      $('ul.nav li.dropdown', context).hover(function () {
        $(this).find('.dropdown-menu').stop(true, true).fadeIn(100);
      }, function () {
        $(this).find('.dropdown-menu').stop(true, true).fadeOut(100);
      });

      // $('.dropdown', context).hover(function () {
      //   var dropdownMenu = $(this).children('.dropdown-menu');
      //   // if (dropdownMenu.is(':visible')) {
      //     dropdownMenu.parent().toggleClass('open');
      //   // }
      // });

    }
  };

  /**
   *
   * @type {{attach: Drupal.behaviors.feedbackPageTweeks.attach}}
   */
  Drupal.behaviors.feedbackPageTweeks = {
    attach: function (context, settings) {
      if ($('.view-feedback ', context).length === 0 && $('.section.feedback ', context).length === 0) {
        return;
      }

      $.makeFeedbackMoreBeautifulStep1('.view-feedback .row, .section.feedback', context);

      if ($.getPageSize()[0] >= 768) {
        $.makeFeedbackMoreBeautifulStep2('.view-feedback .row, .section.feedback', context);
      }
      $(window).resize(function () {
        if ($.getPageSize()[0] >= 768) {
          $.makeFeedbackMoreBeautifulStep2('.view-feedback .row, .section.feedback', context);
        } else {
          $.revertFeedbackMoreBeautiful('.view-feedback .row, .section.feedback', context);
        }
      });
    }
  };

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
        } else {
          revertMobileHTMLVersion();
        }
      });

    }
  };

  Drupal.behaviors.OtherTweaks = {
    attach: function (context, settings) {
      /*!
       * IE10 viewport hack for Surface/desktop Windows 8 bug
       * Copyright 2014-2015 Twitter, Inc.
       * Licensed under MIT (https://github.com/twbs/bootstrap/blob/master/LICENSE)
       */

      // See the Getting Started docs for more information:
      // http://getbootstrap.com/getting-started/#support-ie10-width
      if (navigator.userAgent.match(/IEMobile\/10\.0/)) {
        var msViewportStyle = document.createElement('style');
        msViewportStyle.appendChild(
          document.createTextNode(
            '@-ms-viewport{width:auto!important}'
          )
        );
        document.querySelector('head').appendChild(msViewportStyle)
      }

      // $('footer', context).after('<a class="upper" href="#main-content" title="' + Drupal.t('Back to top') + '"></a>');

      $('.upper').on('click', function (event) {
        event.preventDefault();
        $('html, body', context).animate({scrollTop: 0}, 1000);
        return false;
      });

      $(window).scroll(function () {
        if ($(this).scrollTop() > 100) {
          $('.upper').fadeIn();
        } else {
          $('.upper').fadeOut();
        }
      });
    }
  }

})(jQuery, Drupal, drupalSettings);