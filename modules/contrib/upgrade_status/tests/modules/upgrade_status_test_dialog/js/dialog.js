/**
 * @file
 * Uses the dialogClass dialog option. This comment is not reported: dialogClass:
 */
(function ($, Drupal) {
  // Not reported: settings.dialogClass = 'test-dialog';
  const dialogClass = 'not-an-option';
  const selector = '.dialogClass';
  const url = 'https://example.com/dialogClass';

  $('<div></div>').dialog({
    dialogClass: 'test-dialog',
    classes: { 'ui-dialog': dialogClass },
  });

  function settings(dialogSettings) {
    dialogSettings.dialogClass = 'test-dialog';
    // eslint-disable-next-line prettier/prettier, dot-notation
    return { 'dialogClass': dialogSettings['dialogClass'], selector, url };
  }

  Drupal.testDialogSettings = settings;
})(jQuery, Drupal);
