<?php

namespace Drupal\Tests\twig_field_value\Unit\FieldValue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Controller\ControllerResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\twig_field_value\Twig\Extension\FieldValueExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Field target entity tests from FieldValueExtension.
 */
#[Group('twig_field_value')]
#[RunTestsInSeparateProcesses]
class FieldTargetEntityTest extends UnitTestCase {

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
    $entityRepository->expects($this->any())
      ->method('getTranslationFromContext')
      ->willReturnArgument(0);

    $controllerResolver = $this->createMock(ControllerResolverInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $renderer = $this->createMock(RendererInterface::class);

    $this->extension = new FieldValueExtension($languageManager, $entityRepository, $controllerResolver, $loggerFactory, $renderer);
  }

  /**
   * Returns a mock Content Entity object.
   *
   * @param array $referenced_entities
   *   The referenced entities.
   *
   * @return \Drupal\Core\Field\FieldItemBase
   *   The entity object.
   */
  protected function mockContentEntity(array $referenced_entities) {
    $entities = [];

    // Build the 'entity' objects with a property 'entity' that contains the
    // referenced entity.
    foreach ($referenced_entities as $referenced_entity) {
      $entity = new \stdClass();
      $entity->entity = $referenced_entity;
      $entities[] = $entity;
    }

    $field_item = $this->createMock('Drupal\Core\Entity\ContentEntityBase');
    $field_item->expects($this->any())
      ->method('get')
      ->willReturn($entities);

    return $field_item;
  }

  /**
   * Asserts the twig field_target_entity filter.
   *
   * @param mixed $expected_result
   *   The expected result.
   * @param mixed $render_array
   *   The render array.
   */
  #[DataProvider('providerTestTargetEntity')]
  public function testTargetEntity($expected_result, $render_array) {

    $result = $this->extension->getTargetEntity($render_array);
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
  public static function providerTestTargetEntity(): array {
    return [
      // Invalid render arrays.
      [NULL, NULL],
      [NULL, []],

      // No access.
      [
        NULL,
        [
          '#theme' => 'field',
          '#access' => FALSE,
        ],
      ],

      // No children.
      [
        NULL,
        [
          '#theme' => 'field',
        ],
      ],

      // No #field_name.
      [
        NULL,
        [
          '#theme' => 'field',
          '#no_field_name' => 'nothing',
          '0' => ['target_id' => 1],
        ],
      ],

      // Not visible children.
      [
        NULL,
        [
          '#theme' => 'field',
          '#field_name' => 'reference_field',
          '0' => ['#type' => 'link', '#access' => FALSE],
        ],
      ],

      // No parent object.
      [
        NULL,
        [
          '#theme' => 'field',
          '#field_name' => 'reference_field',
          '0' => ['#type' => 'details', '#title' => 'detail-0'],
        ],
      ],
    ];
  }

  /**
   * Tests that an inaccessible referenced entity returns NULL.
   */
  public function testTargetEntityNoAccess(): void {
    $fieldItemNoAccess = $this->mockEntityReferenceFieldItem($this->mockReferencedEntity(FALSE));
    $entityNoAccess = $this->createMock(ContentEntityInterface::class);
    $entityNoAccess->expects($this->any())
      ->method('get')
      ->with('reference_field')
      ->willReturn([0 => $fieldItemNoAccess]);

    $result = $this->extension->getTargetEntity([
      '#theme' => 'field',
      '#field_name' => 'reference_field',
      '#object' => $entityNoAccess,
    ]);
    $this->assertNull($result);
  }

  /**
   * Tests that a single accessible referenced entity is returned.
   */
  public function testTargetEntitySingleEntity(): void {
    $referencedEntity = $this->mockReferencedEntity(TRUE);
    $fieldItem = $this->mockEntityReferenceFieldItem($referencedEntity);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->expects($this->any())
      ->method('get')
      ->with('reference_field')
      ->willReturn([0 => $fieldItem]);

    $result = $this->extension->getTargetEntity([
      '#theme' => 'field',
      '#field_name' => 'reference_field',
      '#object' => $entity,
      '0' => ['#type' => 'details', '#title' => 'detail-0'],
    ]);
    $this->assertSame($referencedEntity, $result);
  }

  /**
   * Tests that multiple accessible referenced entities are returned as array.
   */
  public function testTargetEntityMultipleEntities(): void {
    $referencedEntity1 = $this->mockReferencedEntity(TRUE);
    $fieldItem1 = $this->mockEntityReferenceFieldItem($referencedEntity1);
    $referencedEntity2 = $this->mockReferencedEntity(TRUE);
    $fieldItem2 = $this->mockEntityReferenceFieldItem($referencedEntity2);
    $entityMultiple = $this->createMock(ContentEntityInterface::class);
    $entityMultiple->expects($this->any())
      ->method('get')
      ->with('reference_field')
      ->willReturn([0 => $fieldItem1, 1 => $fieldItem2]);

    $result = $this->extension->getTargetEntity([
      '#theme' => 'field',
      '#field_name' => 'reference_field',
      '#object' => $entityMultiple,
      '0' => ['#type' => 'details', '#title' => 'detail-0'],
      '1' => ['#type' => 'details', '#title' => 'detail-1'],
    ]);
    $this->assertSame([$referencedEntity1, $referencedEntity2], $result);
  }

  /**
   * Mocks an entity reference field item.
   *
   * @param \Drupal\Core\Entity\EntityInterface $referencedEntity
   *   The referenced entity.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The mocked object.
   */
  protected function mockEntityReferenceFieldItem(EntityInterface $referencedEntity): FieldItemInterface {
    $fieldItem = $this->createMock(FieldItemInterface::class);
    $fieldItem->expects($this->any())
      ->method('__isset')
      ->with('entity')
      ->willReturn(TRUE);
    $fieldItem->expects($this->any())
      ->method('__get')
      ->with('entity')
      ->willReturn($referencedEntity);

    return $fieldItem;
  }

  /**
   * Mocks a referenced entity.
   *
   * @param bool $access
   *   Whether the entity is accessible for view.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The mocked object.
   */
  protected function mockReferencedEntity(bool $access): EntityInterface {
    $referencedEntity = $this->createMock(EntityInterface::class);
    $referencedEntity->expects($this->any())
      ->method('access')
      ->with('view')
      ->willReturn($access);

    return $referencedEntity;
  }

}
