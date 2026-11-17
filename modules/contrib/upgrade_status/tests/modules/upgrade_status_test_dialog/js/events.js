/**
 * @file
 * Uses dialog events. Not reported: $(window).on('dialog:aftercreate', ...)
 */
(function ($, Drupal) {
  $(window).on('dialog:aftercreate', (event, dialog, $element) => {
    $element.addClass('test-dialog');
  });
  $(document).one('click dialog:beforeclose', () => {});
  $(window).on({
    'dialog:afterclose': function (event, dialog, $element) {},
    resize: () => {},
  });
  $(window).on(
    'dialog:beforecreate',
    (event, dialog, $element, settings) => settings && $element.addClass('a'),
  );

  // Not reported: native listeners, other events and removing handlers.
  window.addEventListener('dialog:aftercreate', (event) => event.dialog);
  window.removeEventListener('dialog:afterclose', () => {});
  $(window).on('dialog:close', () => {});
  $(window).off('dialog:aftercreate');

  const dialog = document.querySelector('.ui-dialog-content');
  $(dialog).trigger('dialogButtonsChange');
  dialog.dispatchEvent(new CustomEvent('dialogButtonsChange'));
  Drupal.testDialogEvents = dialog;
})(jQuery, Drupal);
