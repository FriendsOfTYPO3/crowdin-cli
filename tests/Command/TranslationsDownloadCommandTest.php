<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\TranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Http\Client\CrowdinHttpClientInterface;
use CrowdinApiClient\Model\DownloadFile;
use CrowdinApiClient\Model\TranslationProjectBuild;
use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\TranslationsDownloadCommand;
use TYPO3\CrowdinCli\Service\ProjectResolver;
use TYPO3\CrowdinCli\Service\TranslationDownloader;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsDownloadCommandTest extends TestCase
{
    private string $configurationFile;
    private string $workingDirectory;
    private string $originalDirectory;
    private TranslationApi&MockObject $translation;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->configurationFile = tempnam(sys_get_temp_dir(), 'crowdin-cli-test');
        file_put_contents($this->configurationFile, json_encode([
            'projects' => ['typo3-cms' => ['id' => 368815, 'extensionKey' => 'typo3-cms', 'languages' => []]],
        ], JSON_THROW_ON_ERROR));

        // The default output directory is created in the current directory
        $this->originalDirectory = (string)getcwd();
        $this->workingDirectory = (string)realpath(sys_get_temp_dir()) . '/crowdin-cli-cwd-' . bin2hex(random_bytes(4));
        mkdir($this->workingDirectory);
        chdir($this->workingDirectory);

        $zip = tempnam(sys_get_temp_dir(), 'crowdin-test-zip');
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::OVERWRITE);
        $archive->addFromString('sysext/backend/fr.locallang.xlf', 'translated');
        $archive->close();
        $content = (string)file_get_contents($zip);
        unlink($zip);

        $this->translation = $this->createMock(TranslationApi::class);
        $this->translation->method('buildProject')->willReturn(
            new TranslationProjectBuild(['id' => 1, 'projectId' => 368815, 'status' => 'finished', 'progress' => 100])
        );
        $this->translation->method('downloadProjectBuild')->willReturn(new DownloadFile(['url' => 'https://crowdin-tmp.example.com/b.zip']));
        $http = $this->createStub(CrowdinHttpClientInterface::class);
        $http->method('request')->willReturn(Utils::streamFor($content));
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['translation', $this->translation]]);
        $crowdin->method('getClient')->willReturn($http);

        $this->tester = new CommandTester(new TranslationsDownloadCommand(
            new TranslationDownloader($crowdin, 0),
            new ProjectResolver(new ConfigurationReader($this->configurationFile)),
        ));
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        unlink($this->configurationFile);
        self::removeDirectory($this->workingDirectory);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    public function testDefaultsToADirectoryNamedAfterProjectAndLanguage(): void
    {
        $status = $this->tester->execute(['project' => '368815', 'language' => 'fr']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFileExists($this->workingDirectory . '/typo3-cms-fr/sysext/backend/fr.locallang.xlf');
        self::assertStringContainsString('1 file(s) extracted to', $this->tester->getDisplay());
    }

    public function testUsesTheGivenOutputDirectoryAsIs(): void
    {
        $target = $this->workingDirectory . '/custom';

        $this->tester->execute(['project' => 'typo3-cms', 'language' => 'fr', '--output-dir' => $target]);

        self::assertFileExists($target . '/sysext/backend/fr.locallang.xlf');
        self::assertDirectoryDoesNotExist($this->workingDirectory . '/typo3-cms-fr');
    }

    public function testUnknownProjectFails(): void
    {
        $this->translation->expects(self::never())->method('buildProject');

        $status = $this->tester->execute(['project' => '1', 'language' => 'fr']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown project "1"', $this->tester->getDisplay());
    }

    public function testFailureOfCrowdinIsReported(): void
    {
        $translation = $this->createMock(TranslationApi::class);
        $translation->method('buildProject')->willThrowException(new ApiException('Language not found', 404));
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['translation', $translation]]);
        $tester = new CommandTester(new TranslationsDownloadCommand(
            new TranslationDownloader($crowdin, 0),
            new ProjectResolver(new ConfigurationReader($this->configurationFile)),
        ));

        $status = $tester->execute(['project' => '368815', 'language' => 'xx']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Not found (404)', $tester->getDisplay());
    }
}
