/**
 * @file
 * easyDrupal functionality.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Equal height.
   *
   * @param container
   */
  $.equalHeight = function (container) {
    var currentTallest = 0,
      currentRowStart = 0,
      rowDivs = [],
      $el;

    $(container).each(function () {
      $el = $(this);
      $($el).height('auto');
      var topPosition = $el.position().top;

      if (currentRowStart !== topPosition) {
        for (var currentDiv = 0; currentDiv < rowDivs.length; currentDiv++) {
          rowDivs[currentDiv].height(currentTallest);
        }
        rowDivs.length = 0; // Empty the array.
        currentRowStart = topPosition;
        currentTallest = $el.height();
        rowDivs.push($el);
      }
      else {
        rowDivs.push($el);
        currentTallest = (currentTallest < $el.height()) ? ($el.height()) : (currentTallest);
      }
      for (currentDiv = 0; currentDiv < rowDivs.length; currentDiv++) {
        rowDivs[currentDiv].height(currentTallest);
      }
    });
  };

  /**
   * Get page size.
   *
   * @returns {*[]}
   */
  $.getPageSize = function () {
    var xScroll, yScroll, windowWidth, windowHeight, pageWidth, pageHeight;

    if (window.innerHeight && window.scrollMaxY) {
      xScroll = document.body.scrollWidth;
      yScroll = window.innerHeight + window.scrollMaxY;
    }
    else if (document.body.scrollHeight > document.body.offsetHeight) { // all but Explorer Mac
      xScroll = document.body.scrollWidth;
      yScroll = document.body.scrollHeight;
    }
    else if (document.documentElement && document.documentElement.scrollHeight > document.documentElement.offsetHeight) { // Explorer 6 strict mode
      xScroll = document.documentElement.scrollWidth;
      yScroll = document.documentElement.scrollHeight;
    }
    else { // Explorer Mac...would also work in Mozilla and Safari
      xScroll = document.body.offsetWidth;
      yScroll = document.body.offsetHeight;
    }

    if (self.innerHeight) { // all except Explorer
      windowWidth = self.innerWidth;
      windowHeight = self.innerHeight;
    }
    else if (document.documentElement && document.documentElement.clientHeight) { // Explorer 6 Strict Mode
      windowWidth = document.documentElement.clientWidth;
      windowHeight = document.documentElement.clientHeight;
    }
    else if (document.body) { // other Explorers
      windowWidth = document.body.clientWidth;
      windowHeight = document.body.clientHeight;
    }

    // for small pages with total height less then height of the viewport
    if (yScroll < windowHeight) {
      pageHeight = windowHeight;
    }
    else {
      pageHeight = yScroll;
    }

    // for small pages with total width less then width of the viewport
    if (xScroll < windowWidth) {
      pageWidth = windowWidth;
    }
    else {
      pageWidth = xScroll;
    }

    return [pageWidth, pageHeight, windowWidth, windowHeight];
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

    }
  };

  /**
   * Other Tweaks.
   *
   * @type {{attach: Drupal.behaviors.OtherTweaks.attach}}
   */
  Drupal.behaviors.OtherTweaks = {
    attach: function (context, settings) {
      let $upper = $('.upper', context);

      $(once('upperBehavior', '.upper', context)).each(function () {
        $(this).on('click', function() {
          event.preventDefault();
          $('html, body', context).animate({scrollTop: 0}, 1000);
          return false;
        });
      });

      $(window).scroll(function () {
        if ($(this).scrollTop() > 300) {
          $upper.fadeIn();
        }
        else {
          $upper.fadeOut();
        }
      });
    }
  }

})(jQuery, Drupal, drupalSettings);
