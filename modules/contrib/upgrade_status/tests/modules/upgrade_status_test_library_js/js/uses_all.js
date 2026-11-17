/* eslint-disable */
(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.upgradeStatusTest = {
    attach(context) {
      once('upgradeStatusTest', 'body', context).forEach(() => {
        $(drupalSettings.path.baseUrl);
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
