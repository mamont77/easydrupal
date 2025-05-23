<?php

namespace Drupal\Tests\fences\Kernel;

/**
 * Test the field output under different configurations using no theme.
 *
 * @group fences
 */
class FieldOutputTestCoreTheme extends FieldOutputTestBase {

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupNoLabelExpectedSingle() {
    return 'lorem ipsum';
  }

  /**
   * {@inheritdoc}
   */
  public static function onlyFieldTagNoLabelExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-hidden field__items">lorem ipsum</article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupWithLabelExpectedSingle() {
    return 'Field Test lorem ipsum';
  }

  /**
   * {@inheritdoc}
   */
  public static function onlyFieldTagWithLabelExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-above field__items">Field Test lorem ipsum</article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldAndLabelTagWithLabelExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-above field__items"><h3 class="field__label">Field Test</h3>lorem ipsum</article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function classesAndTagsWithLabelExpectedSingle() {
    return '<ul class="item-list field field--name-field-test field--type-text field--label-above field__items"><li class="item-list__label field__label">Field Test</li><li class="item-list__item field__item">lorem ipsum</li></ul>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldAndFieldItemTagWithLabelExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-above field__items"><div class="field__label">Field Test</div><h2 class="field__item">lorem ipsum</h2></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldWithLabelExpectedSingle() {
    return '<div class="field field--name-field-test field--type-text field--label-above field__items"><div class="field__label">Field Test</div><div class="field__item">lorem ipsum</div></div>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupNoLabelItemsWrapperOnlyExpectedSingle() {
    return '<article class="items-wrapper field__items">lorem ipsum</article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldTagItemsWrapperNoLabelExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-hidden"><div class="field__items">lorem ipsum</div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupWithLabelAndItemsWrapperExpectedSingle() {
    return 'Field Test<div class="items-wrapper field__items">lorem ipsum</div>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldTagWithLabelAndItemsWrapperExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-above">Field Test<div class="field__items">lorem ipsum</div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldAndLabelTagWithLabelAndItemsWrapperExpectedSingle() {
    return '<article class="field field--name-field-test field--type-text field--label-above"><h3 class="field__label">Field Test</h3><div class="field__items">lorem ipsum</div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldDefaultItemsWrapperNoLabelExpectedSingle() {
    return '<div class="field field--name-field-test field--type-text field--label-hidden field__items"><div class="field__item">lorem ipsum</div></div>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldItemsWrapperAndLabelAllClassesSetExpectedSingle() {
    return '<article class="tag-class tag-class-two field field--name-field-test field--type-text field--label-hidden"><div class="items-wrapper items-wrapper-two field__items"><div class="item-wrapper item-wrapper-two field__item">lorem ipsum</div></div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupNoLabelItemsWrapperOnlyExpectedMultiple() {
    return '<article class="items-wrapper field__items">test value 1test value 2test value 3</article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldFieldItemAndItemsWrapperTagNoLabelExpectedMultiple() {
    return '<article class="field field--name-field-test-multiple field--type-text field--label-hidden"><div class="field__items"><div class="item-class field__item">test value 1</div><div class="item-class field__item">test value 2</div><div class="item-class field__item">test value 3</div></div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldFieldItemItemsWrapperAndLabelTagWithLabelExpectedMultiple() {
    return '<article class="field field--name-field-test-multiple field--type-text field--label-above"><h2 class="label-class field__label">Field Test Multiple</h2><div class="field__items"><div class="item-class field__item">test value 1</div><div class="item-class field__item">test value 2</div><div class="item-class field__item">test value 3</div></div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupWithLabelAndItemsWrapperExpectedMultiple() {
    return '<div class="field field--name-field-test-multiple field--type-text field--label-above"><h2 class="field__label">Field Test Multiple</h2><ul class="items-wrapper field__items"><li class="item-class field__item">test value 1</li><li class="item-class field__item">test value 2</li><li class="item-class field__item">test value 3</li></ul></div>';
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldAndItemsWrapperTagWithLabelExpectedMultiple() {
    return '<article class="field field--name-field-test-multiple field--type-text field--label-above">Field Test Multiple<div class="field__items">test value 1test value 2test value 3</div></article>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupNoLabelItemTagOnlyExpectedMultiple() {
    return '<div class="item-wrapper field__item">test value 1</div><div class="item-wrapper field__item">test value 2</div><div class="item-wrapper field__item">test value 3</div>';
  }

  /**
   * {@inheritdoc}
   */
  public static function noFieldMarkupWithLabelItemTagOnlyExpectedMultiple() {
    return 'Field Test Multiple<div class="field__item">test value 1</div><div class="field__item">test value 2</div><div class="field__item">test value 3</div>';
  }

}
