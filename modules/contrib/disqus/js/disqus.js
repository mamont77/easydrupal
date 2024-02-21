/**
 * @file
 * JavaScript for the Disqus Drupal module.
 */

// The Disqus global variables.
var disqus_shortname = '';
var disqus_url = '';
var disqus_title = '';
var disqus_identifier = '';
var disqus_lazy_load = 0;
var disqus_def_name = '';
var disqus_def_email = '';
var disqus_config;

(function ($) {

"use strict";

/**
 * JS load helper functions.
 */
Drupal.disqus = {
  loadCommentScript: function (shortname) {
    // Make the AJAX call to get the Disqus comments.
    this.loadScript(shortname, 'embed.js')
  },
  loadCountScript: function (shortname) {
    // Make the AJAX call to get the number of comments.
    this.loadScript(shortname, 'count.js');
  },
  loadScript: function (shortname, scriptName) {
    $.ajax({
      type: 'GET',
      url: '//' + shortname + '.disqus.com/' + scriptName,
      dataType: 'script',
      cache: false
    });
  }
};

/**
 * Drupal Disqus behavior.
 */
Drupal.behaviors.disqus = {
  attach: function (context, settings) {
    // Load the Disqus comments.
    if (settings.disqus || false) {

      // Ensure that the Disqus comments are only loaded once.
      $(once('disqus', 'body', context)).each(function () {

        // Setup the global JavaScript variables for Disqus.
        disqus_shortname = settings.disqus.domain;
        disqus_url = settings.disqus.url;
        disqus_title = settings.disqus.title;
        disqus_identifier = settings.disqus.identifier;
        disqus_def_name = settings.disqus.name || '';
        disqus_def_email = settings.disqus.email || '';

        // Language and SSO settings are passed in through disqus_config().
        disqus_config = function () {
          if (settings.disqus.language || false) {
            this.language = settings.disqus.language;
          }
          if (settings.disqus.remote_auth_s3 || false) {
            this.page.remote_auth_s3 = settings.disqus.remote_auth_s3;
          }
          if (settings.disqus.api_key || false) {
            this.page.api_key = settings.disqus.api_key;
          }
          if (settings.disqus.sso || false) {
            this.sso = settings.disqus.sso;
          }
          if (settings.disqus.callbacks || false) {
            for (var key in settings.disqus.callbacks) {
              for (var i = 0; i < settings.disqus.callbacks[key].length; i++) {
                var callback = settings.disqus.callbacks[key][i].split('.');
                var fn = window;
                for (var j = 0; j < callback.length; j++) {
                  fn = fn[callback[j]];
                }
                if (typeof fn === 'function') {
                  this.callbacks[key].push(fn);
                }
              }
            }
          }
        };

        // Lazy load the Disqus comments using IntersectionObserver.
        if (settings.disqus.lazy_load || false) {
          // Ensure browser supports IntersectionObserver.
          if ('IntersectionObserver' in window) {
            const options = {
              rootMargin: '200px',
            };
            const disqusComments = document.querySelector('#disqus_thread');
            const observer = new IntersectionObserver((entries) => {
              entries.forEach(entry => {
                if (entry.isIntersecting) {
                  Drupal.disqus.loadCommentScript(disqus_shortname);
                  observer.disconnect();
                }
              });
            }, options);
            observer.observe(disqusComments);
          }
          else {
            // If IntersectionObserver not available, load it directly.
            Drupal.disqus.loadCommentScript(disqus_shortname);
          }
        }
        else {
          Drupal.disqus.loadCommentScript(disqus_shortname);
        }
      });
    }

    // Load the comment numbers JavaScript.
    if (settings.disqusComments || false) {
      // Ensure that comment numbers JavaScript is only loaded once.
      $(once('disqusComments', 'body', context)).each(function () {
        disqus_shortname = settings.disqusComments;
        Drupal.disqus.loadCountScript(settings.disqusComments);
      });
    }
  }
};

})(jQuery);
