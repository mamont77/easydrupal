<?php

namespace Drupal\Tests\upgrade_status\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\upgrade_status\JavaScriptDeprecationAnalyzer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests replacing the comments and strings of JavaScript and Twig code.
 *
 * @group upgrade_status
 */
#[Group('upgrade_status')]
final class JavaScriptCommentsTest extends UnitTestCase {

  /**
   * Tests which parts of the code are comments and strings.
   */
  public function testCommentsAndStrings(): void {
    $cases = [
      'line comment' => ["a(); // b\nc();", "a(); C\nc();"],
      'block comment' => ["a(); /* b\n c */ d();", 'a(); C d();'],
      'strings' => ['a("b", \'c\', `d`);', 'a(S, S, S);'],
      'comment start in a string' => ['a("/* b"); /* c */', 'a(S); C'],
      // A comment start without an end is code.
      'no end' => ['a(); /* b', 'a(); /* b'],
      'no end after a comment' => ['/* a */ b /* c /* d', 'C b /* c /* d'],
      'only starts' => ["/*\n/*\n/*\n", "/*\n/*\n/*\n"],
      // The slash of a comment end can also be the slash of a start.
      'end and start share a slash' => ['/* a */* b', 'C* b'],
      'start, then a slash' => ['a /*/ b', 'a /*/ b'],
      // Regular expression literals are not recognized, so this is a start
      // without an end, and the code after it stays.
      'regular expression' => ["a.replace(/\\/*$/, 'b'); c();", 'a.replace(/\\/*$/, S); c();'],
      'null character' => ["a(\"\0\"); /* b */ /* c", 'a(S); C /* c'],
    ];
    $replace = static fn (array $match): string => $match[0][0] === '/' ? 'C' : 'S';
    foreach ($cases as $label => [$code, $expected]) {
      $this->assertSame($expected, JavaScriptDeprecationAnalyzer::replaceCommentsAndStrings($code, $replace), $label);
    }
  }

  /**
   * Tests code with many comment starts without an end.
   *
   * Without the PCRE JIT, this took 3.5 seconds for 20000 lines, and four
   * times more for twice the lines.
   */
  public function testManyCommentStarts(): void {
    $code = str_repeat("/*\n", 20000);
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    try {
      $start = microtime(TRUE);
      $result = JavaScriptDeprecationAnalyzer::replaceCommentsAndStrings($code, static fn (array $match): string => '');
      $time = microtime(TRUE) - $start;
    }
    finally {
      ini_set('pcre.jit', (string) $jit);
    }
    $this->assertSame($code, $result);
    $this->assertLessThan(1, $time);
  }

  /**
   * Tests which parts of Twig code are comments.
   */
  public function testTwigComments(): void {
    $cases = [
      'comment' => ["a {# b\n c #} d", 'a C d'],
      'two comments' => ['{# a #} b {# c #}', 'C b C'],
      'no comment' => ['a {{ b }}', 'a {{ b }}'],
      // A comment start without an end is code.
      'no end' => ['a {# b', 'a {# b'],
      'no end after a comment' => ['{# a #} b {# c {# d', 'C b {# c {# d'],
      'only an end' => ['a #} b', 'a #} b'],
      // The number sign of a start is not the number sign of an end.
      'start, then a brace' => ['a {#} b', 'a {#} b'],
      'start, then a brace and an end' => ['a {#} b #} c', 'a C c'],
    ];
    foreach ($cases as $label => [$code, $expected]) {
      $this->assertSame($expected, JavaScriptDeprecationAnalyzer::replaceTwigComments($code, static fn (array $match): string => 'C'), $label);
    }
  }

  /**
   * Tests Twig code with many comment starts without an end.
   *
   * Without the PCRE JIT, this took 4.8 seconds for 8000 starts in 210 KB, and
   * four times more for twice the size.
   */
  public function testManyTwigCommentStarts(): void {
    $code = '{# a #}' . str_repeat("{# open\n<div>{{ x }}</div>\n", 8000);
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    try {
      $start = microtime(TRUE);
      $result = JavaScriptDeprecationAnalyzer::replaceTwigComments($code, static fn (array $match): string => '');
      $time = microtime(TRUE) - $start;
    }
    finally {
      ini_set('pcre.jit', (string) $jit);
    }
    $this->assertSame(substr($code, 7), $result);
    $this->assertLessThan(1, $time);
  }

}
