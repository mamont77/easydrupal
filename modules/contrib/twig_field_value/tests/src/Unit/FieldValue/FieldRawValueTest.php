<?php

namespace Drupal\Tests\twig_field_value\Unit\FieldValue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Controller\ControllerResolverInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\twig_field_value\Twig\Extension\FieldValueExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Field raw tests from FieldValueExtension.
 */
#[Group('twig_field_value')]
#[RunTestsInSeparateProcesses]
class FieldRawValueTest extends UnitTestCase {

  /**
   * The Twig extension under test.
   *
   * @var \Drupal\twig_field_value\Twig\Extension\FieldValueExtension
   */
  protected $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {

    parent::setUp();
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $controllerResolver = $this->createMock(ControllerResolverInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $renderer = $this->createMock(RendererInterface::class);

    $this->extension = new FieldValueExtension($languageManager, $entityRepository, $controllerResolver, $loggerFactory, $renderer);
  }

  /**
   * Returns a mock FieldItem.
   *
   * @param mixed $values
   *   The values.
   *
   * @return \Drupal\Core\Field\FieldItemBase
   *   The entity object.
   */
  public function mockFieldItem($values) {
    $field_item = $this->createMock('Drupal\Core\Field\FieldItemBase');
    $field_item->expects($this->any())
      ->method('getValue')
      ->willReturn($values);

    return $field_item;
  }

  /**
   * Asserts the twig field_raw filter.
   *
   * @param mixed $expected_result
   *   The expected result.
   * @param mixed $render_array
   *   The render array.
   * @param string $key
   *   The key.
   */
  #[DataProvider('providerTestRawValues')]
  public function testRawValues($expected_result, $render_array, $key) {

    $result = $this->extension->getRawValues($render_array, $key);
    $this->assertSame($expected_result, $result);
  }

  /**
   * Provides data and expected results for the test method.
   *
   * This only tests invalid render arrays formats. Valid render arrays are
   * covered by functional tests.
   *
   * @return array
   *   Data and expected results.
   */
  public static function providerTestRawValues(): array {
    return [
      // Invalid render arrays.
      [NULL, NULL, ''],
      [NULL, [], ''],
      [
        NULL,
        ['#theme' => 'field', '#no_items' => []],
        '',
      ],
      [
        NULL,
        ['#theme' => 'field', '#items' => []],
        '',
      ],
    ];
  }

  /**
   * Tests field_raw with an empty FieldItem (cannot be in a static provider).
   */
  public function testRawValuesWithEmptyFieldItem(): void {
    $renderArray = [
      '#theme' => 'field',
      '#items' => $this->mockFieldItem(NULL),
    ];
    $this->assertNull($this->extension->getRawValues($renderArray, ''));
  }

}
