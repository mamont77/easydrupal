<?php

namespace Drupal\Tests\config_partial_export\Unit;

use Drupal\config_partial_export\Form\ConfigPartialExportForm;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for ConfigPartialExportForm::validateForm().
 *
 * ValidateForm() reads getUserInput() from the form state and calls
 * setErrorByName() if no items are selected. All Drupal service dependencies
 * are mocked — none of them are exercised by validateForm() itself.
 *
 * Validation logic summary:
 * @code
 *   error if: (empty($change_list) || $count === 0) && empty($addSystemSiteInfo)
 * @endcode
 *
 * @group config_partial_export
 * @coversDefaultClass \Drupal\config_partial_export\Form\ConfigPartialExportForm
 */
class ConfigPartialExportFormValidationTest extends UnitTestCase {

  /**
   * The form instance under test.
   *
   * @var \Drupal\config_partial_export\Form\ConfigPartialExportForm
   */
  protected ConfigPartialExportForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->form = new ConfigPartialExportForm(
      $this->createMock(StorageInterface::class),
      $this->createMock(StorageInterface::class),
      $this->createMock(ConfigManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(StateInterface::class),
    );
    // StringTranslationTrait requires a translation service; $this->t() used
    // inside validateForm() when building the error message.
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a FormStateInterface mock with a pre-configured getUserInput().
   *
   * @param array $change_list
   *   Associative array of config_name => truthy/falsy value, matching the
   *   'change_list' tableselect structure produced by the form.
   * @param mixed $add_system_site_info
   *   Value of the addSystemSiteInfo checkbox (truthy = checked).
   *
   * @return \Drupal\Core\Form\FormStateInterface|\PHPUnit\Framework\MockObject\MockObject
   *   A FormStateInterface mock whose getUserInput() is pre-wired.
   */
  private function makeFormState(array $change_list, $add_system_site_info): FormStateInterface {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getUserInput')->willReturn([
      'change_list' => $change_list,
      'addSystemSiteInfo' => $add_system_site_info,
    ]);
    return $formState;
  }

  /**
   * Validation passes when at least one change_list item is truthy.
   *
   * $count = 1 → condition evaluates to FALSE → no error.
   *
   * @covers ::validateForm
   */
  public function testValidationPassesWithOneSelectedItem(): void {
    $formState = $this->makeFormState(
      ['system.site' => 'system.site', 'system.logging' => 0],
      '',
    );
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Validation fails when change_list is empty and the system site flag is off.
   *
   * Empty([]) === TRUE, $count = 0 → both sides of the && are TRUE → error.
   *
   * @covers ::validateForm
   */
  public function testValidationFailsWithEmptyChangeList(): void {
    $formState = $this->makeFormState([], '');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('', $this->anything());

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Validation fails when every change_list checkbox is unchecked (falsy).
   *
   * The array is non-empty so empty() returns FALSE, but $count remains 0
   * because no item is truthy → !$count = TRUE → error.
   *
   * @covers ::validateForm
   */
  public function testValidationFailsWhenAllItemsAreUnchecked(): void {
    $formState = $this->makeFormState(
      ['system.site' => 0, 'system.logging' => 0, 'system.performance' => 0],
      '',
    );
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('', $this->anything());

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Validation passes when only the system.site flag is checked.
   *
   * Empty($addSystemSiteInfo) === FALSE when truthy → the && short-circuits
   * to FALSE → no error, even with an empty change_list.
   *
   * @covers ::validateForm
   */
  public function testValidationPassesWhenOnlySystemSiteFlagIsSet(): void {
    $formState = $this->makeFormState([], '1');
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Validation passes with a mix of truthy and falsy items.
   *
   * $count counts only truthy values. One truthy item is enough.
   *
   * @covers ::validateForm
   */
  public function testValidationPassesWithMixedTruthyFalsyItems(): void {
    $formState = $this->makeFormState(
      [
    // Truthy.
        'system.site'        => 'system.site',
    // Falsy.
        'system.logging'     => 0,
    // Truthy.
        'system.performance' => 'system.performance',
      ],
      '',
    );
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Validation passes when both items are selected AND the flag is set.
   *
   * Redundant but confirms both truthy paths together do not cause
   * false errors.
   *
   * @covers ::validateForm
   */
  public function testValidationPassesWithSelectionAndSystemSiteFlag(): void {
    $formState = $this->makeFormState(
      ['system.site' => 'system.site'],
      '1',
    );
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * Verifies that the error is set on the correct form element name.
   *
   * The first argument to setErrorByName() must be '' (empty string), which
   * attaches the error to the form as a whole rather than a specific element.
   *
   * @covers ::validateForm
   */
  public function testErrorIsAttachedToFormRoot(): void {
    $formState = $this->makeFormState([], '');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with(
        $this->identicalTo(''),
        $this->anything(),
      );

    $form = [];
    $this->form->validateForm($form, $formState);
  }

}
