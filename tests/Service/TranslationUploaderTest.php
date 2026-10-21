<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use CrowdinApiClient\Api\FileApi;
use CrowdinApiClient\Api\SourceStringApi;
use CrowdinApiClient\Api\StorageApi;
use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Api\TranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\Storage;
use CrowdinApiClient\Model\TranslationImport;
use CrowdinApiClient\ModelCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\TranslationUploader;

#[AllowMockObjectsWithoutExpectations]
final class TranslationUploaderTest extends TestCase
{
    private string $directory;
    private TranslationApi&MockObject $translation;
    private TranslationUploader $subject;

    /** @var array<string, File> */
    private array $projectFiles;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crowdin-cli-upload-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/a', 0o755, true);

        $this->projectFiles = [
            '/a/locallang.xlf' => new File(['id' => 7, 'path' => '/a/locallang.xlf', 'type' => 'xliff']),
            '/a/data.json' => new File(['id' => 8, 'path' => '/a/data.json', 'type' => 'json']),
        ];

        $sourceString = $this->createMock(SourceStringApi::class);
        $sourceString->method('list')->willReturn(self::collection([
            new SourceString(['id' => 1, 'identifier' => 'one', 'text' => 'One']),
            new SourceString(['id' => 2, 'identifier' => 'two', 'text' => 'Two']),
            new SourceString(['id' => 3, 'identifier' => 'three', 'text' => 'Three']),
            new SourceString(['id' => 4, 'identifier' => 'four', 'text' => 'Four']),
        ]));
        $stringTranslation = $this->createMock(StringTranslationApi::class);
        $stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            new LanguageTranslation(['stringId' => 1, 'text' => 'Un']),
            new LanguageTranslation(['stringId' => 2, 'text' => 'Deux']),
        ]));
        $this->translation = $this->createMock(TranslationApi::class);

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['sourceString', $sourceString],
            ['stringTranslation', $stringTranslation],
            ['translation', $this->translation],
        ]);
        $this->subject = new TranslationUploader($crowdin, 0, 2);
    }

    protected function tearDown(): void
    {
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
     * @param array<string, string> $targets translations by unit ID
     */
    private function write(string $relativePath, array $targets): void
    {
        $units = '';
        foreach ($targets as $id => $target) {
            $units .= sprintf('<trans-unit id="%s"><source>x</source><target>%s</target></trans-unit>', $id, $target);
        }
        file_put_contents($this->directory . '/' . $relativePath, '<?xml version="1.0" encoding="utf-8"?><xliff version="1.0"><file><body>' . $units . '</body></file></xliff>');
    }

    public function testFindsLocalFilesBelowThePrefix(): void
    {
        $this->write('a/fr.locallang.xlf', []);
        mkdir($this->directory . '/b');
        $this->write('b/fr.other.xlf', []);

        self::assertSame(['a/fr.locallang.xlf', 'b/fr.other.xlf'], $this->subject->findLocalFiles($this->directory));
        self::assertSame(['b/fr.other.xlf'], $this->subject->findLocalFiles($this->directory, '/b'));
    }

    public function testOnlyTheTranslationsCrowdinDoesNotHaveAreUploaded(): void
    {
        // one: same as Crowdin, two: changed, three: new, four: empty
        $this->write('a/fr.locallang.xlf', ['one' => 'Un', 'two' => 'Deux modifié', 'three' => 'Trois', 'four' => '']);

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        self::assertTrue($candidate->isUploadable());
        self::assertSame(7, $candidate->fileId);
        self::assertSame('/a/locallang.xlf', $candidate->sourcePath);
        self::assertSame(2, $candidate->toUpload);
        self::assertSame(1, $candidate->unchanged);
        self::assertSame(1, $candidate->empty);
        self::assertStringContainsString('Deux modifié', (string)$candidate->content);
        self::assertStringContainsString('Trois', (string)$candidate->content);
        self::assertStringNotContainsString('id="one"', (string)$candidate->content);
        self::assertStringNotContainsString('id="four"', (string)$candidate->content);
    }

    public function testNothingToUploadWhenEverythingIsUpToDate(): void
    {
        $this->write('a/fr.locallang.xlf', ['one' => 'Un', 'two' => 'Deux']);

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        self::assertFalse($candidate->isUploadable());
        self::assertNull($candidate->content);
        self::assertSame(2, $candidate->unchanged);
    }

    public function testTranslationsSameAsSourceAreSkippedUnlessAllowed(): void
    {
        $this->write('a/fr.locallang.xlf', ['three' => 'Three']);

        $skipped = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);
        $allowed = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles, true);

        self::assertFalse($skipped->isUploadable());
        self::assertSame(1, $skipped->sameAsSource);
        self::assertTrue($allowed->isUploadable());
        self::assertSame(1, $allowed->toUpload);
    }

    public function testFileOfAnotherLanguageIsSkipped(): void
    {
        $this->write('a/de.locallang.xlf', ['three' => 'Drei']);

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/de.locallang.xlf', $this->projectFiles);

        self::assertFalse($candidate->isUploadable());
        self::assertStringContainsString('not a file of the language "fr"', (string)$candidate->skipReason);
    }

    public function testFileWithoutSourceInTheProjectIsSkipped(): void
    {
        $this->write('a/fr.unknown.xlf', ['three' => 'Trois']);

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.unknown.xlf', $this->projectFiles);

        self::assertStringContainsString('no source file "/a/unknown.xlf"', (string)$candidate->skipReason);
    }

    public function testOtherFormatsThanXliffAreSkipped(): void
    {
        file_put_contents($this->directory . '/a/fr.data.json', '{}');

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.data.json', $this->projectFiles);

        self::assertStringContainsString('only XLIFF', (string)$candidate->skipReason);
    }

    public function testInvalidXmlIsSkipped(): void
    {
        file_put_contents($this->directory . '/a/fr.locallang.xlf', 'not xml');

        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        self::assertSame('not a valid XML file', $candidate->skipReason);
    }

    public function testUploadImportsTheReducedFileForTheLanguage(): void
    {
        $this->write('a/fr.locallang.xlf', ['three' => 'Trois']);
        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        $storageApi = $this->createMock(StorageApi::class);
        $storageApi->expects(self::once())->method('create')->willReturnCallback(function (\SplFileInfo $file): Storage {
            self::assertSame('fr.locallang.xlf', $file->getFilename());
            self::assertStringContainsString('Trois', (string)file_get_contents($file->getPathname()));

            return new Storage(['id' => 99, 'fileName' => $file->getFilename()]);
        });
        $this->translation->expects(self::once())->method('importTranslations')
            ->with(1, [
                'storageId' => 99,
                'languageIds' => ['fr'],
                'fileId' => 7,
                'importEqSuggestions' => false,
                'autoApproveImported' => false,
            ])
            ->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'in_progress']));
        $this->translation->method('getTranslationImportStatus')->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'finished']));

        $this->subject = new TranslationUploader($this->crowdinWith($storageApi), 0, 2);
        $this->subject->upload(1, 'fr', $candidate);
    }

    public function testUploadFailsWhenCrowdinFailsTheImport(): void
    {
        $this->write('a/fr.locallang.xlf', ['three' => 'Trois']);
        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        $storageApi = $this->createMock(StorageApi::class);
        $storageApi->method('create')->willReturn(new Storage(['id' => 99, 'fileName' => 'x']));
        $this->translation->method('importTranslations')->willReturn(new TranslationImport(['identifier' => 'imp', 'status' => 'failed']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to import');

        (new TranslationUploader($this->crowdinWith($storageApi), 0, 2))->upload(1, 'fr', $candidate);
    }

    public function testUploadRefusesACandidateWithNothingToUpload(): void
    {
        $this->write('a/fr.locallang.xlf', ['one' => 'Un']);
        $candidate = $this->subject->analyze(1, 'fr', $this->directory, 'a/fr.locallang.xlf', $this->projectFiles);

        $this->expectException(\LogicException::class);

        $this->subject->upload(1, 'fr', $candidate);
    }

    private function crowdinWith(StorageApi $storage): Crowdin
    {
        $sourceString = $this->createStub(SourceStringApi::class);
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['sourceString', $sourceString],
            ['storage', $storage],
            ['translation', $this->translation],
        ]);

        return $crowdin;
    }
}
