<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\FileApi;
use CrowdinApiClient\Api\SourceStringApi;
use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Api\TranslationStatusApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\Progress;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use CrowdinApiClient\ModelCollection;
use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\InteractiveCommand;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;
use TYPO3\CrowdinCli\Service\ProjectProgressRepository;
use TYPO3\CrowdinCli\Service\ProjectResolver;

#[AllowMockObjectsWithoutExpectations]
final class InteractiveCommandTest extends TestCase
{
    private string $directory;
    private StringTranslationApi&MockObject $stringTranslation;
    private ApprovalLog $log;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crowdin-cli-test-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory . '/configuration.json', json_encode([
            'projects' => ['typo3-cms' => ['id' => 123, 'extensionKey' => 'typo3-cms', 'languages' => []]],
        ], JSON_THROW_ON_ERROR));
        $this->log = new ApprovalLog($this->directory . '/approvals.log');

        $translationStatus = $this->createMock(TranslationStatusApi::class);
        $translationStatus->method('getProjectProgress')->willReturn(self::collection([
            new Progress(['language' => [], 'languageId' => 'de', 'translationProgress' => 90, 'approvalProgress' => 90]),
            new Progress(['language' => [], 'languageId' => 'fr', 'translationProgress' => 50, 'approvalProgress' => 30]),
        ]));

        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        // Three pending translations of alice and one of bob
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::translation(1, 11, 'alice'),
            self::translation(2, 12, 'alice'),
            self::translation(3, 13, 'bob'),
            self::translation(4, 14, 'alice'),
        ]));

        // Crowdin lists every suggestion and approval of a string on demand only
        $this->stringTranslation->method('list')->willReturnCallback(
            fn(int $projectId, array $params): ModelCollection => self::collection($this->competing[$params['stringId']] ?? [])
        );
        $this->stringTranslation->method('listApprovals')->willReturnCallback(
            fn(int $projectId, array $params): ModelCollection => self::collection($this->approvals[$params['stringId']] ?? [])
        );

        $sourceString = $this->createMock(SourceStringApi::class);
        $sourceString->method('get')->willReturnCallback(
            static fn(int $projectId, int $id): SourceString => new SourceString(['id' => $id, 'fileId' => 7, 'text' => 'Source ' . $id])
        );
        $file = $this->createMock(FileApi::class);
        $file->method('get')->willReturn(new File(['id' => 7, 'path' => '/14.3/typo3/sysext/backend/de.xlf']));

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['translationStatus', $translationStatus],
            ['stringTranslation', $this->stringTranslation],
            ['sourceString', $sourceString],
            ['file', $file],
        ]);

        $this->tester = new CommandTester(new InteractiveCommand(
            new ProjectResolver(new ConfigurationReader($this->directory . '/configuration.json')),
            new ProjectProgressRepository($crowdin),
            new PendingTranslationRepository($crowdin),
            $this->log,
        ));
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

    private static function translation(int $stringId, int $translationId, string $username): LanguageTranslation
    {
        return new LanguageTranslation([
            'stringId' => $stringId,
            'contentType' => 'text/plain',
            'translationId' => $translationId,
            'text' => 'Texte ' . $stringId,
            'user' => ['id' => $stringId, 'username' => $username, 'fullName' => $username],
            'createdAt' => '2026-10-19T10:00:00+00:00',
        ]);
    }

    private function expectApprovals(int $count): void
    {
        $this->stringTranslation
            ->expects(self::exactly($count))
            ->method('createApproval')
            ->willReturnCallback(function (int $projectId, array $data): StringTranslationApproval {
                $this->approvalCalls[] = $data['translationId'];

                return new StringTranslationApproval(['id' => 9000 + $data['translationId'], 'translationId' => $data['translationId']]);
            });
    }

    /** @var list<int> */
    private array $approvalCalls = [];

    /** @var array<int, list<StringTranslation>> all translations (approved or not) by string ID, if more than the listed one */
    private array $competing = [];

    /** @var array<int, list<StringTranslationApproval>> */
    private array $approvals = [];

    private static function suggestion(int $id, string $username, string $createdAt = '2026-10-19T10:00:00+00:00'): StringTranslation
    {
        return new StringTranslation([
            'id' => $id,
            'text' => 'Variante ' . $id,
            'user' => ['id' => $id, 'username' => $username, 'fullName' => $username],
            'rating' => 0,
            'createdAt' => $createdAt,
            'pluralCategoryName' => null,
        ]);
    }

    /**
     * String 1 of alice (translation 11) competes with a later suggestion of carol (translation 21).
     */
    private function competeOnStringOne(): void
    {
        $this->competing[1] = [self::suggestion(11, 'alice'), self::suggestion(21, 'carol', '2026-10-20T10:00:00+00:00')];
    }

    public function testRefusesToRunWithoutInteraction(): void
    {
        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(2, $status);
        self::assertStringContainsString('cannot run with --no-interaction', $this->tester->getDisplay());
    }

    public function testApproveAllExceptOneForOneSuggester(): void
    {
        $this->expectApprovals(2);

        // Language fr is the only one with pending work (#1), suggester alice is #1 (3 translations), approve all except #3
        $this->tester->setInputs(['1', '1', 'e 3', 'q']);
        $status = $this->tester->execute(['--project' => '123']);

        self::assertSame(0, $status);
        self::assertSame([11, 12], $this->approvalCalls);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('/14.3/typo3/sysext/backend/de.xlf', $display);
        self::assertStringContainsString('2 approved', $display);
        self::assertStringContainsString('translations:unapproved 123', $display);
        self::assertSame([11, 12], array_column($this->log->entries(), 'translationId'));
        self::assertSame(['fr', 'fr'], array_column($this->log->entries(), 'language'));
    }

    public function testLanguageAndUserOptionsSkipQuestionsAndSkipApproves(): void
    {
        $this->stringTranslation->expects(self::never())->method('createApproval');

        $this->tester->setInputs(['s']);
        $status = $this->tester->execute(['--project' => 'typo3-cms', '--language' => 'fr', '--user' => 'bob']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Texte 3', $this->tester->getDisplay());
        self::assertStringNotContainsString('Texte 1', $this->tester->getDisplay());
        self::assertSame([], $this->log->entries());
    }

    public function testDropDeletesConfirmedSuggestions(): void
    {
        $deleted = [];
        $this->stringTranslation->expects(self::never())->method('createApproval');
        $this->stringTranslation
            ->expects(self::once())
            ->method('delete')
            ->willReturnCallback(static function (int $projectId, int $translationId) use (&$deleted): void {
                $deleted[] = $translationId;
            });

        // bob has a single suggestion: drop it, confirm
        $this->tester->setInputs(['d 1', 'yes']);
        $status = $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'bob']);

        self::assertSame(0, $status);
        self::assertSame([13], $deleted);
        self::assertStringContainsString('1 dropped', $this->tester->getDisplay());
    }

    public function testDropIsNotDoneWithoutConfirmation(): void
    {
        $this->stringTranslation->expects(self::never())->method('delete');

        // Refuse the confirmation, then skip
        $this->tester->setInputs(['d 1', 'no', 's']);
        $status = $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'bob']);

        self::assertSame(0, $status);
        self::assertStringNotContainsString('dropped', $this->tester->getDisplay());
    }

    public function testStringWithCompetingSuggestionsIsReviewedOnItsOwn(): void
    {
        $this->competeOnStringOne();
        $this->expectApprovals(1);
        $deleted = [];
        $this->stringTranslation
            ->expects(self::once())
            ->method('delete')
            ->willReturnCallback(static function (int $projectId, int $translationId) use (&$deleted): void {
                $deleted[] = $translationId;
            });

        // alice has 3 pending strings, 1 of them contested: skip the chunk of the 2 others, approve the suggestion of carol, delete the other
        $this->tester->setInputs(['s', '2', 'yes']);
        $status = $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        self::assertSame(0, $status);
        self::assertSame([21], $this->approvalCalls);
        self::assertSame([11], $deleted);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Chunk 1 of 1 (2 translation(s))', $display);
        self::assertStringContainsString('Competing suggestions 1 of 1 (2 for the same string)', $display);
        self::assertStringContainsString('(none yet: this string is not translated)', $display);
        self::assertStringContainsString('Variante 11', $display);
        self::assertStringContainsString('Variante 21', $display);
        self::assertStringContainsString('1 dropped', $display);
        self::assertSame([21], array_column($this->log->entries(), 'translationId'));
    }

    public function testCompetingSuggestionsStayIfTheirDeletionIsDeclined(): void
    {
        $this->competeOnStringOne();
        $this->expectApprovals(1);
        $this->stringTranslation->expects(self::never())->method('delete');

        $this->tester->setInputs(['s', '1', 'no']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        self::assertSame([11], $this->approvalCalls);
        self::assertStringNotContainsString('dropped', $this->tester->getDisplay());
    }

    public function testKeepingTheCurrentTranslationDeletesAllSuggestions(): void
    {
        $this->competeOnStringOne();
        $this->competing[1][] = self::suggestion(99, 'dave', '2025-01-01T10:00:00+00:00');
        $this->approvals[1] = [new StringTranslationApproval(['id' => 500, 'translationId' => 99, 'stringId' => 1])];
        $this->stringTranslation->expects(self::never())->method('createApproval');
        $deleted = [];
        $this->stringTranslation
            ->expects(self::exactly(2))
            ->method('delete')
            ->willReturnCallback(static function (int $projectId, int $translationId) use (&$deleted): void {
                $deleted[] = $translationId;
            });

        $this->tester->setInputs(['s', 'k', 'yes']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        self::assertSame([11, 21], $deleted);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Variante 99 (approved, by dave)', $display);
        self::assertStringContainsString('[k] keep the current translation', $display);
    }

    public function testKeepIsOnlyOfferedIfThereIsACurrentTranslation(): void
    {
        $this->competeOnStringOne();
        $this->stringTranslation->expects(self::never())->method('delete');

        $this->tester->setInputs(['s', 'k', 's']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        self::assertStringContainsString('Not understood', $this->tester->getDisplay());
        self::assertStringNotContainsString('keep the current translation', $this->tester->getDisplay());
    }

    public function testDroppingSomeCompetingSuggestionsShowsWhatIsLeft(): void
    {
        $this->competeOnStringOne();
        $this->competing[1][] = self::suggestion(31, 'erin', '2026-10-21T10:00:00+00:00');
        $this->expectApprovals(1);
        $deleted = [];
        $this->stringTranslation
            ->expects(self::exactly(2))
            ->method('delete')
            ->willReturnCallback(static function (int $projectId, int $translationId) use (&$deleted): void {
                $deleted[] = $translationId;
            });

        // Drop the first suggestion, approve what is then the first one (21), delete the remaining rival (31)
        $this->tester->setInputs(['s', 'd 1', 'yes', '1', 'yes']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        self::assertSame([11, 31], $deleted);
        self::assertSame([21], $this->approvalCalls);
        self::assertStringContainsString('Competing suggestions 1 of 1 (3 for the same string)', $this->tester->getDisplay());
        self::assertStringContainsString('Competing suggestions 1 of 1 (2 for the same string)', $this->tester->getDisplay());
    }

    public function testSuggestionsImprovingAnApprovedTranslationComeAfterUntranslatedStrings(): void
    {
        $this->competing[2] = [self::suggestion(12, 'alice'), self::suggestion(99, 'dave', '2025-01-01T10:00:00+00:00')];
        $this->approvals[2] = [new StringTranslationApproval(['id' => 500, 'translationId' => 99, 'stringId' => 2])];
        $this->stringTranslation->expects(self::never())->method('createApproval');

        $this->tester->setInputs(['s', 's']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'alice']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Chunk 1 of 2 (2 translation(s))', $display);
        self::assertStringContainsString('Chunk 2 of 2 (1 translation(s), improving an approved translation: less urgent)', $display);
        self::assertStringContainsString('Current translation', $display);
        self::assertStringContainsString('Variante 99', $display);
        self::assertLessThan(strpos($display, 'Chunk 2 of 2'), strpos($display, 'Chunk 1 of 2'));
    }

    public function testNothingToDoWhenEverythingIsApproved(): void
    {
        $this->stringTranslation->expects(self::never())->method('listLanguageTranslations');

        $status = $this->tester->execute(['--project' => '123', '--language' => 'de']);

        self::assertSame(0, $status);
        self::assertStringContainsString('No translation waits for approval', $this->tester->getDisplay());
    }

    public function testDryRunApprovesNothing(): void
    {
        $this->stringTranslation->expects(self::never())->method('createApproval');

        $this->tester->setInputs(['a']);
        $this->tester->execute(['--project' => '123', '--language' => 'fr', '--user' => 'bob', '--dry-run' => true]);

        self::assertStringContainsString('1 translation(s) would be approved.', $this->tester->getDisplay());
        self::assertSame([], $this->log->entries());
    }

    public function testUnknownProjectFails(): void
    {
        $status = $this->tester->execute(['--project' => '999']);

        self::assertSame(1, $status);
        self::assertStringContainsString('Unknown project "999"', $this->tester->getDisplay());
    }
}
