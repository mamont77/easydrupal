/**
 * @file
 * Uses autocomplete and contextual features.
 */
// cspell:ignore blacklist
(function ($, Drupal) {
  // Not reported in a comment: Drupal.contextual.views
  $('#edit-test').attr('data-autocomplete-first-character-blacklist', '$');
  Drupal.autocomplete.options.firstCharacterBlacklist = '$';
  Drupal.testContextual = Drupal.contextual.views.concat(
    Drupal.contextual.regionViews,
  );

  // Not reported: the new names and other contextual properties.
  $('#edit-test').attr('data-autocomplete-first-character-denylist', '$');
  Drupal.autocomplete.options.firstCharacterDenyList = '$';
  Drupal.testContextualInstances = Drupal.contextual.instances;
})(jQuery, Drupal);
