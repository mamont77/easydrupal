<?php

namespace Drupal\Tests\upgrade_status\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\upgrade_status\FileFinder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading the directories of projects.
 *
 * @group upgrade_status
 */
#[Group('upgrade_status')]
final class FileFinderTest extends UnitTestCase {

  /**
   * The directory with the test files.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/upgrade_status_file_finder_' . bin2hex(random_bytes(6));
    $files = [
      'b.txt',
      'a.php',
      '.hidden.php',
      'src/C.php',
      'src/deep/d.inc',
      '.git/config.php',
      'node_modules/package/index.php',
    ];
    foreach ($files as $file) {
      $path = $this->directory . '/' . $file;
      if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, TRUE);
      }
      touch($path);
    }
    symlink($this->directory . '/src', $this->directory . '/linked');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unlink($this->directory . '/linked');
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->directory);
    parent::tearDown();
  }

  /**
   * Tests the listings of one directory.
   */
  public function testListings(): void {
    $finder = new FileFinder();
    $root = $this->directory;
    foreach ([FALSE, TRUE] as $keep) {
      $finder->keepListings($keep);
      $all = ['.git', '.hidden.php', 'a.php', 'b.txt', 'linked', 'node_modules', 'src'];
      $this->assertSame($all, $finder->getNames($root));
      $this->assertEqualsCanonicalizing($all, $finder->getNames($root, FALSE));
      // The other methods leave out hidden names, like glob() does.
      $this->assertSame(["$root/a.php", "$root/b.txt", "$root/linked", "$root/node_modules", "$root/src"], $finder->getPaths($root));
      $this->assertSame(["$root/a.php", "$root/b.txt"], $finder->getFiles($root));
      $this->assertSame(["$root/a.php"], $finder->getFiles($root, '.php'));
      $this->assertSame(["$root/linked", "$root/node_modules", "$root/src"], $finder->getDirectories($root, TRUE));
      $this->assertEqualsCanonicalizing(["$root/linked", "$root/node_modules", "$root/src"], $finder->getDirectories($root));
      $this->assertTrue($finder->isDirectory("$root/src"));
      $this->assertTrue($finder->isDirectory("$root/linked"));
      $this->assertFalse($finder->isDirectory("$root/a.php"));
      $this->assertSame([], $finder->getNames("$root/missing"));
    }
  }

  /**
   * Tests finding files in a directory and the directories below it.
   */
  public function testFindFiles(): void {
    $finder = new FileFinder();
    $root = $this->directory;
    $is_php = static fn (string $name): bool => str_ends_with($name, '.php');

    // Linked directories are not followed, so src/C.php is found once.
    $this->assertEqualsCanonicalizing(
      [
        "$root/.git/config.php",
        "$root/.hidden.php",
        "$root/a.php",
        "$root/node_modules/package/index.php",
        "$root/src/C.php",
      ],
      $finder->findFiles($root, $is_php),
    );
    $this->assertEqualsCanonicalizing(
      ["$root/.git/config.php", "$root/.hidden.php", "$root/a.php", "$root/src/C.php"],
      $finder->findFiles($root, $is_php, ['node_modules']),
    );
    // Only directories are left out by this argument. The callback decides
    // about hidden files.
    $this->assertEqualsCanonicalizing(
      ["$root/.hidden.php", "$root/a.php", "$root/src/C.php"],
      $finder->findFiles($root, $is_php, ['node_modules'], FALSE),
    );
    $this->assertSame(["$root/src/deep/d.inc"], $finder->findFiles($root, static fn (string $name): bool => str_ends_with($name, '.inc')));
  }

  /**
   * Tests that listings are only kept when asked for.
   */
  public function testKeepListings(): void {
    $finder = new FileFinder();
    $source = $this->directory . '/src';

    // Without keeping, a new file is found right away.
    $this->assertSame(['C.php', 'deep'], $finder->getNames($source));
    touch("$source/E.php");
    $this->assertSame(['C.php', 'E.php', 'deep'], $finder->getNames($source));

    // With keeping, the directory is not read again.
    $finder->keepListings(TRUE);
    $this->assertSame(['C.php', 'E.php', 'deep'], $finder->getNames($source));
    $this->assertFalse($finder->isDirectory("$source/more"));
    touch("$source/F.php");
    mkdir("$source/more");
    $this->assertSame(['C.php', 'E.php', 'deep'], $finder->getNames($source));
    $this->assertEqualsCanonicalizing(['C.php', 'E.php', 'deep'], $finder->getNames($source, FALSE));
    $this->assertSame(["$source/C.php", "$source/E.php"], $finder->getFiles($source, '.php'));
    $this->assertFalse($finder->isDirectory("$source/more"));

    // Starting again forgets the listings.
    $finder->keepListings(TRUE);
    $this->assertSame(['C.php', 'E.php', 'F.php', 'deep', 'more'], $finder->getNames($source));
    unlink("$source/F.php");
    $finder->keepListings(FALSE);
    $this->assertSame(['C.php', 'E.php', 'deep', 'more'], $finder->getNames($source));
    $this->assertTrue($finder->isDirectory("$source/more"));
  }

}
