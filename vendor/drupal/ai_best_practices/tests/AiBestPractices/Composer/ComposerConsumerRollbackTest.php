<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices\Composer;

use Drupal\AiBestPractices\Composer\ComposerConsumerRollback;
use Drupal\AiBestPractices\Composer\ComposerProjectSynchronizer;
use Drupal\AiBestPractices\Console\AiBestPracticesApplication;
use PHPUnit\Framework\TestCase;

final class ComposerConsumerRollbackTest extends TestCase
{
    public function testStripsLegacyAbpAllowPluginsAndPrunesEmptyContainers(): void
    {
        $in = [
            'name' => 'test/consumer',
            'scripts' => [
                'abp' => AiBestPracticesApplication::COMPOSER_SCRIPT_CALLBACK,
            ],
            'config' => [
                'allow-plugins' => [
                    ComposerProjectSynchronizer::PACKAGE_NAME => true,
                ],
            ],
        ];
        $out = ComposerConsumerRollback::stripPluginFromComposerData($in);
        $this->assertArrayNotHasKey('scripts', $out);
        $this->assertArrayNotHasKey('config', $out);
        $this->assertSame('test/consumer', $out['name']);
    }

    public function testRemovesDrupalAiScriptButKeepsOtherScripts(): void
    {
        $in = [
            'scripts' => [
                AiBestPracticesApplication::COMPOSER_SCRIPT_NAME => AiBestPracticesApplication::COMPOSER_SCRIPT_CALLBACK,
                'test' => 'phpunit',
            ],
        ];
        $out = ComposerConsumerRollback::stripPluginFromComposerData($in);
        $this->assertArrayHasKey('scripts', $out);
        $this->assertArrayNotHasKey(AiBestPracticesApplication::COMPOSER_SCRIPT_NAME, $out['scripts']);
        $this->assertArrayNotHasKey('abp', $out['scripts']);
        $this->assertSame('phpunit', $out['scripts']['test']);
    }

    public function testRemovesBothScriptKeysWhenPresent(): void
    {
        $cb = AiBestPracticesApplication::COMPOSER_SCRIPT_CALLBACK;
        $in = [
            'scripts' => [
                'abp' => $cb,
                AiBestPracticesApplication::COMPOSER_SCRIPT_NAME => $cb,
                'test' => 'phpunit',
            ],
        ];
        $out = ComposerConsumerRollback::stripPluginFromComposerData($in);
        $this->assertArrayHasKey('scripts', $out);
        $this->assertArrayNotHasKey('abp', $out['scripts']);
        $this->assertArrayNotHasKey(AiBestPracticesApplication::COMPOSER_SCRIPT_NAME, $out['scripts']);
        $this->assertSame('phpunit', $out['scripts']['test']);
    }

    public function testRemovesAllowPluginsEntryButKeepsOtherPluginsAndConfig(): void
    {
        $in = [
            'config' => [
                'sort-packages' => true,
                'allow-plugins' => [
                    ComposerProjectSynchronizer::PACKAGE_NAME => true,
                    'symfony/runtime' => true,
                ],
            ],
        ];
        $out = ComposerConsumerRollback::stripPluginFromComposerData($in);
        $this->assertArrayHasKey('config', $out);
        $this->assertTrue($out['config']['sort-packages']);
        $this->assertArrayHasKey('allow-plugins', $out['config']);
        $this->assertArrayNotHasKey(ComposerProjectSynchronizer::PACKAGE_NAME, $out['config']['allow-plugins']);
        $this->assertTrue($out['config']['allow-plugins']['symfony/runtime']);
    }

    public function testPrunesAllowPluginsWhenLastEntryRemoved(): void
    {
        $in = [
            'config' => [
                'allow-plugins' => [
                    ComposerProjectSynchronizer::PACKAGE_NAME => true,
                ],
            ],
        ];
        $out = ComposerConsumerRollback::stripPluginFromComposerData($in);
        $this->assertArrayNotHasKey('config', $out);
    }
}
