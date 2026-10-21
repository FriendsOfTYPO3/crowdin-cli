<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\FileApi;
use CrowdinApiClient\Api\SourceStringApi;
use CrowdinApiClient\Api\StorageApi;
use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Api\TranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\Storage;
use CrowdinApiClient\Model\TranslationImport;
use CrowdinApiClient\ModelCollection;
use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\TranslationsUploadCommand;
use TYPO3\CrowdinCli\Service\ProjectResolver;
use TYPO3\CrowdinCli\Service\TranslationUploader;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsUploadCommandTest extends TestCase
{
    private string $configurationFile;
    private string $directory;
    private TranslationApi&MockObject $translation;
    private StorageApi&MockObject $storage;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->configurationFile = tempnam(sys_get_temp_dir(), 'crowdin-cli-test');
        file_put_contents($this->configurationFile, json_encode([
            'projects' => ['typo3-cms' => ['id' => 368815, 'extensionKey' => 'typo3-cms', 'languages' => []]],
        ], JSON_THROW_ON_ERROR));

        $this->directory = sys_get_temp_dir() . '/crowdin-cli-upload-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/a', 0o755, true);
        file_put_contents(
            $this->directory . '/a/fr.locallang.xlf',
            '<xliff version="1.0"><file><body><trans-unit id="one"><source>One</source><target>Un</target></trans-unit></body></file></xliff>'
        );

        $file = $this->createMock(FileApi::class);
        $file->method('list')->willReturn(self::collection([new File(['id' => 7, 'path' => '/a/locallang.xlf', 'type' => 'xliff'])]));
        $sourceString = $this->createMock(SourceStringApi::class);
        $sourceString->method('list')->willReturn(self::collection([new SourceString(['id' => 1, 'identifier' => 'one', 'text' => 'One'])]));
        $stringTranslation = $this->createMock(StringTranslationApi::class);
        $stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([]));
        $this->storage = $this->createMock(StorageApi::class);
        $this->storage->method('create')->willReturn(new Storage(['id' => 99, 'fileName' => 'fr.locallang.xlf']));
        $this->translation = $this->createMock(TranslationApi::class);
        $this->translation->method('importTranslations')->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'finished']));

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['file', $file],
            ['sourceString', $sourceString],
            ['stringTranslation', $stringTranslation],
            ['storage', $this->storage],
            ['translation', $this->translation],
        ]);

        $this->tester = new CommandTester(new TranslationsUploadCommand(
            new TranslationUploader($crowdin, 0),
            new ProjectResolver(new ConfigurationReader($this->configurationFile)),
        ));
    }

    protected function tearDown(): void
    {
        unlink($this->configurationFile);
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    /**
     * @param list<object> $items
     */
    private static function collection(array $items): ModelCollection
    {
        $collection = new ModelCollection();
        foreach ($items as $item) {
            $collection->add($item);
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(): array
    {
        return ['project' => 'typo3-cms', 'language' => 'fr', 'directory' => $this->directory];
    }

    public function testDryRunShowsWhatWouldBeUploadedAndSendsNothing(): void
    {
        $this->storage->expects(self::never())->method('create');

        $status = $this->tester->execute($this->arguments() + ['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('a/fr.locallang.xlf: 1 to upload', $this->tester->getDisplay());
        self::assertStringContainsString('1 translation(s) in 1 file(s) would be uploaded to typo3-cms as suggestions', $this->tester->getDisplay());
    }

    public function testNothingIsSentWithoutConfirmation(): void
    {
        $this->storage->expects(self::never())->method('create');

        $this->tester->setInputs(['no']);
        $status = $this->tester->execute($this->arguments());

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testUploadsAfterConfirmation(): void
    {
        $this->translation->expects(self::once())->method('importTranslations')
            ->with(368815, self::callback(static fn(array $p): bool => $p['autoApproveImported'] === false && $p['fileId'] === 7 && $p['languageIds'] === ['fr']))
            ->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'finished']));

        $this->tester->setInputs(['yes']);
        $status = $this->tester->execute($this->arguments());

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('[1/1] a/fr.locallang.xlf uploaded (1)', $this->tester->getDisplay());
    }

    public function testYesSkipsTheConfirmationAndAutoApproveIsPassedOn(): void
    {
        $this->translation->expects(self::once())->method('importTranslations')
            ->with(368815, self::callback(static fn(array $p): bool => $p['autoApproveImported'] === true))
            ->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'finished']));

        $status = $this->tester->execute($this->arguments() + ['--yes' => true, '--auto-approve' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('approved translations', $this->tester->getDisplay());
    }

    public function testUnknownProjectFails(): void
    {
        $status = $this->tester->execute(['project' => '1'] + $this->arguments());

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown project "1"', $this->tester->getDisplay());
    }
}
