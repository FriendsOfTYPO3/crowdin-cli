<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\SourceStringApi;
use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use CrowdinApiClient\ModelCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\TranslationsApproveCommand;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsApproveCommandTest extends TestCase
{
    private string $directory;
    private StringTranslationApi&MockObject $stringTranslation;
    private ApprovalLog $log;
    private CommandTester $tester;

    /** @var list<int> */
    private array $approved = [];

    /** @var list<int> */
    private array $deleted = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crowdin-cli-test-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->log = new ApprovalLog($this->directory . '/approvals.log');

        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        // Crowdin lists one translation per string: the suggestion of alice on string 1, the one of bob on string 2
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::listed(1, 11, 'alice'),
            self::listed(2, 12, 'bob'),
        ]));
        // Carol suggested something else for string 1
        $competing = [
            1 => [self::suggestion(11, 'alice'), self::suggestion(21, 'carol')],
            2 => [self::suggestion(12, 'bob')],
        ];
        $this->stringTranslation->method('list')->willReturnCallback(
            static fn(int $projectId, array $params): ModelCollection => self::collection($competing[$params['stringId']] ?? [])
        );
        $this->stringTranslation->method('listApprovals')->willReturn(self::collection([]));
        $this->stringTranslation->method('createApproval')->willReturnCallback(function (int $projectId, array $data): StringTranslationApproval {
            $this->approved[] = $data['translationId'];

            return new StringTranslationApproval(['id' => 9000 + $data['translationId'], 'translationId' => $data['translationId']]);
        });
        $this->stringTranslation->method('delete')->willReturnCallback(function (int $projectId, int $translationId): void {
            $this->deleted[] = $translationId;
        });

        $sourceString = $this->createMock(SourceStringApi::class);
        $sourceString->method('get')->willReturnCallback(
            static fn(int $projectId, int $id): SourceString => new SourceString(['id' => $id, 'fileId' => 7, 'text' => 'Source ' . $id])
        );

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['stringTranslation', $this->stringTranslation], ['sourceString', $sourceString]]);

        $this->tester = new CommandTester(new TranslationsApproveCommand(new PendingTranslationRepository($crowdin), $this->log));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
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

    private static function listed(int $stringId, int $translationId, string $username): LanguageTranslation
    {
        return new LanguageTranslation([
            'stringId' => $stringId,
            'contentType' => 'text/plain',
            'translationId' => $translationId,
            'text' => 'Texte ' . $translationId,
            'user' => ['id' => $translationId, 'username' => $username, 'fullName' => $username],
            'createdAt' => '2026-10-19T10:00:00+00:00',
        ]);
    }

    private static function suggestion(int $id, string $username): StringTranslation
    {
        return new StringTranslation([
            'id' => $id,
            'text' => 'Texte ' . $id,
            'user' => ['id' => $id, 'username' => $username, 'fullName' => $username],
            'rating' => 0,
            'createdAt' => '2026-10-19T10:00:00+00:00',
            'pluralCategoryName' => null,
        ]);
    }

    public function testApprovesWithoutTouchingCompetingSuggestionsByDefault(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['11'], '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([11], $this->approved);
        self::assertSame([], $this->deleted);
    }

    public function testCompetingSuggestionCanBeApprovedByItsId(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['21'], '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([21], $this->approved);
        self::assertSame([], $this->deleted);
        self::assertSame([21], array_column($this->log->entries(), 'translationId'));
    }

    public function testUnknownIdIsStillSkipped(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['999'], '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([], $this->approved);
        self::assertStringContainsString('Translation 999 is not an unapproved translation', $this->tester->getDisplay());
    }

    public function testDropRivalsDeletesTheCompetingSuggestionsAfterApproving(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['21'], '--drop-rivals' => true, '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([21], $this->approved);
        self::assertSame([11], $this->deleted);
        self::assertStringContainsString('1 competing suggestion(s) deleted', $this->tester->getDisplay());
    }

    public function testDropRivalsAsksBeforeDeleting(): void
    {
        $this->tester->setInputs(['no']);
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['21'], '--drop-rivals' => true]);

        self::assertSame(0, $status);
        self::assertSame([], $this->approved);
        self::assertSame([], $this->deleted);
        self::assertStringContainsString('1 competing suggestion(s) to delete', $this->tester->getDisplay());
        self::assertStringContainsString('permanently delete the 1 competing suggestion(s)', $this->tester->getDisplay());
    }

    public function testDropRivalsDryRunChangesNothing(): void
    {
        $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['21'], '--drop-rivals' => true, '--dry-run' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame([], $this->approved);
        self::assertSame([], $this->deleted);
        self::assertStringContainsString('deletes #11', $this->tester->getDisplay());
    }

    public function testDropRivalsRefusesToChooseBetweenTwoSelectedSuggestionsOfTheSameString(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['11', '21'], '--drop-rivals' => true, '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([], $this->approved);
        self::assertSame([], $this->deleted);
        self::assertStringContainsString('compete for the same string', $this->tester->getDisplay());
        self::assertStringContainsString('Nothing to approve', $this->tester->getDisplay());
    }

    public function testDropRivalsStillApprovesStringsWithoutCompetitors(): void
    {
        $status = $this->tester->execute(['project' => '123', 'language' => 'fr', '--user' => 'bob', '--drop-rivals' => true, '--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([12], $this->approved);
        self::assertSame([], $this->deleted);
    }

    public function testNothingIsDeletedIfTheApprovalFails(): void
    {
        $stringTranslation = $this->createMock(StringTranslationApi::class);
        $stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([self::listed(1, 11, 'alice')]));
        $stringTranslation->method('list')->willReturn(self::collection([self::suggestion(11, 'alice'), self::suggestion(21, 'carol')]));
        $stringTranslation->method('listApprovals')->willReturn(self::collection([]));
        $stringTranslation->method('createApproval')->willThrowException(new \RuntimeException('No permission'));
        $stringTranslation->expects(self::never())->method('delete');
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['stringTranslation', $stringTranslation]]);
        $tester = new CommandTester(new TranslationsApproveCommand(new PendingTranslationRepository($crowdin), $this->log));

        $status = $tester->execute(['project' => '123', 'language' => 'fr', 'translation-ids' => ['11'], '--drop-rivals' => true, '--yes' => true]);

        self::assertSame(1, $status);
        self::assertStringContainsString('No permission', $tester->getDisplay());
    }
}
