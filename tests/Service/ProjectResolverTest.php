<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\ProjectResolver;

final class ProjectResolverTest extends TestCase
{
    private string $configurationFile;

    protected function setUp(): void
    {
        $this->configurationFile = tempnam(sys_get_temp_dir(), 'crowdin-cli-test');
        file_put_contents($this->configurationFile, json_encode([
            'projects' => [
                'typo3-cms' => [
                    'id' => 368815,
                    'extensionKey' => 'typo3-cms',
                    'languages' => [['id' => 'de', 'name' => 'German']],
                ],
                'typo3-extension-news' => [
                    'id' => 368353,
                    'extensionKey' => 'news',
                    'languages' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        unlink($this->configurationFile);
    }

    private function subject(): ProjectResolver
    {
        return new ProjectResolver(new ConfigurationReader($this->configurationFile));
    }

    public function testResolveById(): void
    {
        $project = $this->subject()->resolve('368353');

        self::assertSame('typo3-extension-news', $project->identifier);
        self::assertSame('news', $project->extensionKey);
    }

    public function testResolveByIdentifier(): void
    {
        $project = $this->subject()->resolve('typo3-cms');

        self::assertSame(368815, $project->id);
        self::assertSame('de', $project->languages[0]->id);
    }

    public function testResolveUnknownProjectThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown project "999"');

        $this->subject()->resolve('999');
    }
}
