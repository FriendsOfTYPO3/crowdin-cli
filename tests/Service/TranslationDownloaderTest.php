<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use CrowdinApiClient\Api\TranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Http\Client\CrowdinHttpClientInterface;
use CrowdinApiClient\Model\DownloadFile;
use CrowdinApiClient\Model\TranslationProjectBuild;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use TYPO3\CrowdinCli\Service\TranslationDownloader;

#[AllowMockObjectsWithoutExpectations]
final class TranslationDownloaderTest extends TestCase
{
    private const string URL = 'https://crowdin-tmp.example.com/build.zip?signature=abc';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crowdin-cli-download-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    private function build(string $status): TranslationProjectBuild
    {
        return new TranslationProjectBuild(['id' => 42, 'projectId' => 368815, 'status' => $status, 'progress' => 0]);
    }

    /**
     * @param array<string, string> $files
     */
    private static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crowdin-test-zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $content = (string)file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function translationApi(string $builtStatus = 'finished'): TranslationApi&MockObject
    {
        $api = $this->createMock(TranslationApi::class);
        $api->method('buildProject')->willReturn($this->build($builtStatus));
        $api->method('getProjectBuildStatus')->willReturn($this->build('finished'));
        $api->method('downloadProjectBuild')->willReturn(new DownloadFile(['url' => self::URL]));

        return $api;
    }

    private function subject(TranslationApi $translations, string $archive, int $maxPolls = 3): TranslationDownloader
    {
        $http = $this->createStub(CrowdinHttpClientInterface::class);
        $http->method('request')->willReturnCallback(static fn(): StreamInterface => Utils::streamFor($archive));
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['translation', $translations]]);
        $crowdin->method('getClient')->willReturn($http);

        return new TranslationDownloader($crowdin, 0, $maxPolls);
    }

    public function testExtractsTheFilesOfTheBuild(): void
    {
        $archive = self::zip(['b/fr.b.xlf' => 'B', 'a/fr.a.xlf' => 'A']);

        $files = $this->subject($this->translationApi(), $archive)->download(368815, 'fr', $this->directory);

        self::assertSame(['a/fr.a.xlf', 'b/fr.b.xlf'], $files);
        self::assertSame('A', file_get_contents($this->directory . '/a/fr.a.xlf'));
    }

    public function testRequestsOnlyTheGivenLanguage(): void
    {
        $api = $this->createMock(TranslationApi::class);
        $api->expects(self::once())->method('buildProject')
            ->with(368815, ['targetLanguageIds' => ['fr'], 'exportApprovedOnly' => true])
            ->willReturn($this->build('finished'));
        $api->method('downloadProjectBuild')->willReturn(new DownloadFile(['url' => self::URL]));

        $this->subject($api, self::zip(['fr.xlf' => 'x']))->download(368815, 'fr', $this->directory, true);
    }

    public function testWaitsUntilTheBuildIsFinished(): void
    {
        $api = $this->createMock(TranslationApi::class);
        $api->method('buildProject')->willReturn($this->build('inProgress'));
        $api->expects(self::exactly(2))->method('getProjectBuildStatus')
            ->willReturnOnConsecutiveCalls($this->build('inProgress'), $this->build('finished'));
        $api->method('downloadProjectBuild')->willReturn(new DownloadFile(['url' => self::URL]));

        $files = $this->subject($api, self::zip(['fr.xlf' => 'x']))->download(368815, 'fr', $this->directory);

        self::assertSame(['fr.xlf'], $files);
    }

    public function testGivesUpWhenTheBuildTakesTooLong(): void
    {
        $api = $this->createMock(TranslationApi::class);
        $api->method('buildProject')->willReturn($this->build('inProgress'));
        $api->method('getProjectBuildStatus')->willReturn($this->build('inProgress'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('takes too long');

        $this->subject($api, '', 2)->download(368815, 'fr', $this->directory);
    }

    public function testFailedBuildThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to build');

        $this->subject($this->translationApi('failed'), '')->download(368815, 'fr', $this->directory);
    }

    public function testRefusesPathsLeavingTheTargetDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe path');

        $this->subject($this->translationApi(), self::zip(['../evil.xlf' => 'x']))->download(368815, 'fr', $this->directory);
    }

    public function testInvalidArchiveThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid archive');

        $this->subject($this->translationApi(), 'not a zip')->download(368815, 'fr', $this->directory);
    }
}
