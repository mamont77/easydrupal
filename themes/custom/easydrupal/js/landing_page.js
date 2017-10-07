/**
 * @file
 * easyDrupal landing page functionality.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   *
   * @type {{attach: Drupal.behaviors.fullPage.attach}}
   */
  // Drupal.behaviors.fullPage = {
  //   attach: function (context, settings) {
  //
  //     $('.path-frontpage .region-content', context).fullpage({
  //       //Navigation.
  //       menu: '#block-landingpagemenu .menu',
  //       // lockAnchors: false,
  //       anchors: ['about', 'projects', 'articles', 'feedback', 'contact'],
  //       navigation: false,
  //       // navigationPosition: 'right',
  //       // navigationTooltips: [Drupal.t('About'), Drupal.t('Projects'), Drupal.t('Articles'), Drupal.t('FeedBack'), Drupal.t('Contact Me')],
  //       // showActiveTooltip: false,
  //       slidesNavigation: false,
  //       // slidesNavPosition: 'bottom',
  //
  //       //Scrolling.
  //       css3: true,
  //       // scrollingSpeed: 700,
  //       // autoScrolling: true,
  //       // fitToSection: true,
  //       // fitToSectionDelay: 1000,
  //       // scrollBar: false,
  //       // easing: 'easeInOutCubic',
  //       // easingcss3: 'ease',
  //       // loopBottom: false,
  //       // loopTop: false,
  //       // loopHorizontal: true,
  //       continuousVertical: false,
  //       // normalScrollElements: '.about .field--name-body, .articles .field-name-body',
  //       // scrollOverflow: true,
  //       // scrollOverflowOptions: null,
  //       // touchSensitivity: 15,
  //       // normalScrollElementTouchThreshold: 5,
  //
  //       //Accessibility.
  //       keyboardScrolling: true,
  //       animateAnchor: true,
  //       recordHistory: true,
  //
  //       //Design.
  //       // controlArrows: true,
  //       // verticalCentered: true,
  //       sectionsColor: ['#383337', '#3D3D3D', '#383C4B', '#383337', '#3D3D3D'],
  //       paddingTop: '3em',
  //       paddingBottom: '3em',
  //       // fixedElements: 'header, footer',
  //       responsiveWidth: 768,
  //       // responsiveHeight: 960,
  //
  //       //Custom selectors.
  //       // sectionSelector: '.section',
  //       // slideSelector: '.slide',
  //
  //       //Events.
  //       onLeave: function (index, nextIndex, direction) {
  //       },
  //       afterLoad: function (anchorLink, index) {
  //         if ($('.navbar-collapse.collapse.in').length > 0) {
  //           $('.navbar-toggle').click();
  //         }
  //       },
  //       afterRender: function () {
  //         // var $projectsSectionTitle = $('.projects .section-title', context),
  //         //  $projects = $('.projects .slide', context);
  //         // // $navBar = $('#fp-nav', context);
  //         //
  //         // // Set a background for the project section and move the title into slides.
  //         // $projects.find('.fp-tableCell').wrapInner('<div class="slide-inner"></div>').prepend($projectsSectionTitle);
  //         // $projects.each(function () {
  //         //  $(this).css('background-image', 'url(' + $(this).find('img').attr('src') + ')');
  //         // });
  //
  //         // Add the animated line after a title.
  //         var $sections = $('.region-content .section', context);
  //         $sections.each(function () {
  //           $(this).find('.field-label-above').wrap('<div class="title-wrapper"></div>').parent().append('<div class="line"></div>');
  //           if ($(this).hasClass('projects')) {
  //             $(this).find('.fp-tableCell').append('<div class="read-more"><a href="/projects">' + Drupal.t('Look others projects >') + '</a></div>');
  //           }
  //           if ($(this).hasClass('articles')) {
  //             $(this).find('.fp-tableCell').append('<div class="read-more"><a href="/articles">' + Drupal.t('Read more articles >') + '</a></div>');
  //           }
  //           if ($(this).hasClass('feedback')) {
  //             $(this).find('.fp-tableCell').append('<div class="read-more"><a href="/feedback">' + Drupal.t('Look more feedback >') + '</a></div>');
  //           }
  //         });
  //       },
  //       afterResize: function () {
  //       },
  //       afterSlideLoad: function (anchorLink, index, slideAnchor, slideIndex) {
  //
  //       },
  //       onSlideLeave: function (anchorLink, index, slideIndex, direction, nextSlideIndex) {
  //       }
  //     });
  //   }
  // };

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
        if ($anchor.attr('href') != '') {
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

      // $(window).bind("load resize scroll", function (e) {
      //   var y = $(window).scrollTop();
      //
      //   $('.feedback', context).filter(function () {
      //     return $(this).offset().top < (y + $(window).height()) &&
      //       $(this).offset().top + $(this).height() > y;
      //   }).css('background-position', '50% ' + parseInt(-y / 6) + 'px');
      // });

      var $projects = $('.section.projects .col-sm-6', context),
        $articles = $('.section.articles .item', context),
        $feedback = $('.section.feedback .item', context),
        $contact = $('.section.contact .contact-form', context);

      // if ($.getPageSize()[0] <= 767) {
      //   $.equalHeight($articles.find('h3'));
      // }
      // $(window).resize(function () {
      //   if ($.getPageSize()[0] <= 767) {
      //     $.equalHeight($articles.find('h3'));
      //   }
      // });

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
