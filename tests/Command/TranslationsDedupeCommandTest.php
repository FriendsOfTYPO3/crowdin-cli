<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\FileApi;
use CrowdinApiClient\Api\SourceStringApi;
use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use CrowdinApiClient\ModelCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\TranslationsDedupeCommand;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsDedupeCommandTest extends TestCase
{
    private StringTranslationApi&MockObject $stringTranslation;
    private CommandTester $tester;

    /** @var list<int> */
    private array $deleted = [];

    /** @var list<int> */
    private array $undeletable = [];

    protected function setUp(): void
    {
        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        // String 1 has no translation yet and two identical suggestions, Crowdin lists the first one only
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([self::listed(1, 11)]));
        $suggestions = [
            1 => [self::suggestion(11, 'Abbrechen', '2026-10-19T10:00:00+00:00'), self::suggestion(12, 'Abbrechen', '2026-10-20T10:00:00+00:00')],
            // String 2 is approved (30) and a suggestion repeats it, another one differs
            2 => [self::suggestion(30, 'Löschen'), self::suggestion(31, 'Löschen'), self::suggestion(32, 'Entfernen')],
        ];
        $this->stringTranslation->method('list')->willReturnCallback(
            static fn(int $projectId, array $params): ModelCollection => self::collection($suggestions[$params['stringId']] ?? [])
        );
        $this->stringTranslation->method('listApprovals')->willReturnCallback(
            static fn(int $projectId, array $params): ModelCollection => self::collection(
                $params['stringId'] === 2 ? [new StringTranslationApproval(['id' => 900, 'translationId' => 30])] : []
            )
        );
        $this->stringTranslation->method('delete')->willReturnCallback(function (int $projectId, int $translationId): void {
            if (in_array($translationId, $this->undeletable, true)) {
                throw new \RuntimeException('Not allowed');
            }
            $this->deleted[] = $translationId;
        });

        $sourceString = $this->createMock(SourceStringApi::class);
        // String 2 has an approved translation: only found through the strings
        $sourceString->method('list')->willReturn(self::collection([
            new SourceString(['id' => 2, 'fileId' => 7, 'text' => 'Delete']),
        ]));
        $sourceString->method('get')->willReturnCallback(
            static fn(int $projectId, int $id): SourceString => new SourceString(['id' => $id, 'fileId' => 7, 'text' => 'Source ' . $id])
        );
        $file = $this->createMock(FileApi::class);
        $file->method('get')->willReturn(new File(['id' => 7, 'path' => '/main/locallang.xlf']));

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['stringTranslation', $this->stringTranslation],
            ['sourceString', $sourceString],
            ['file', $file],
        ]);

        $this->tester = new CommandTester(new TranslationsDedupeCommand(new PendingTranslationRepository($crowdin)));
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

    private static function listed(int $stringId, int $translationId): LanguageTranslation
    {
        return new LanguageTranslation([
            'stringId' => $stringId,
            'contentType' => 'text/plain',
            'translationId' => $translationId,
            'text' => 'Abbrechen',
            'user' => ['id' => 1, 'username' => 'alice', 'fullName' => 'alice'],
            'createdAt' => '2026-10-19T10:00:00+00:00',
        ]);
    }

    private static function suggestion(int $id, string $text, string $createdAt = '2026-10-19T10:00:00+00:00'): StringTranslation
    {
        return new StringTranslation([
            'id' => $id,
            'text' => $text,
            'user' => ['id' => $id, 'username' => 'user' . $id, 'fullName' => 'user' . $id],
            'rating' => 0,
            'createdAt' => $createdAt,
            'pluralCategoryName' => null,
        ]);
    }

    public function testDeletesDuplicatesAndKeepsTheOldest(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'de', '--yes' => true]);

        self::assertSame(0, $status);
        sort($this->deleted);
        self::assertSame([12, 31], $this->deleted);
        self::assertStringContainsString('2 duplicate suggestion(s) deleted', $this->tester->getDisplay());
    }

    public function testDryRunDeletesNothing(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'de', '--dry-run' => true]);

        self::assertSame(0, $status);
        self::assertSame([], $this->deleted);
        self::assertStringContainsString('2 duplicate suggestion(s)', $this->tester->getDisplay());
        self::assertStringContainsString('same as the approved translation', $this->tester->getDisplay());
        self::assertStringContainsString('same as the older suggestion #11', $this->tester->getDisplay());
    }

    public function testConfirmationCanBeDeclined(): void
    {
        $this->tester->setInputs(['no']);
        $status = $this->tester->execute(['project' => '123', 'language' => 'de']);

        self::assertSame(0, $status);
        self::assertSame([], $this->deleted);
    }

    public function testFailedDeletionIsReported(): void
    {
        $this->undeletable = [12];
        $status = $this->tester->execute(['project' => '123', 'language' => 'de', '--yes' => true, '--format' => 'json']);

        self::assertSame(1, $status);
        self::assertSame([31], $this->deleted);
        /** @var array{duplicates: list<array<string, mixed>>, warnings: list<string>} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $byId = array_column($result['duplicates'], null, 'translation_id');
        self::assertSame('failed', $byId[12]['status']);
        self::assertSame('Not allowed', $byId[12]['error']);
        self::assertSame('deleted', $byId[31]['status']);
    }

    public function testJsonFormatReportsEveryDuplicate(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'de', '--yes' => true, '--format' => 'json']);

        self::assertSame(0, $status);
        /** @var array{dry_run: bool, duplicates: list<array<string, mixed>>} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($result['dry_run']);
        $byId = array_column($result['duplicates'], null, 'translation_id');
        self::assertSame(['deleted', 'deleted'], array_column($byId, 'status'));
        self::assertSame(11, $byId[12]['duplicate_of']);
        self::assertSame(30, $byId[31]['duplicate_of']);
        self::assertSame('/main/locallang.xlf', $byId[12]['file']);
    }

    public function testJsonFormatRefusesToAskForConfirmation(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'de', '--format' => 'json']);

        self::assertSame(2, $status);
        self::assertSame([], $this->deleted);
        self::assertStringContainsString('--yes', $this->tester->getDisplay());
    }
}
