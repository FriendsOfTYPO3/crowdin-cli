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
use TYPO3\CrowdinCli\Command\TranslationsUnapprovedCommand;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsUnapprovedCommandTest extends TestCase
{
    private StringTranslationApi&MockObject $stringTranslation;
    private CommandTester $tester;

    /** @var array<int, list<StringTranslation>> */
    private array $translations = [];

    /** @var array<int, list<StringTranslationApproval>> */
    private array $approvals = [];

    /** @var list<SourceString> the strings which have an approved translation and an unapproved one, which Crowdin does not list */
    private array $hiddenStrings = [];

    protected function setUp(): void
    {
        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        // Strings 1 (file 7) and 2 (file 8) are in the order of Crowdin, which is not the order of the files
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::listed(2, 12, 'bob'),
            self::listed(1, 21, 'carol'),
        ]));
        $this->stringTranslation->method('list')->willReturnCallback(
            fn(int $projectId, array $params): ModelCollection => self::collection($this->translations[$params['stringId']] ?? [])
        );
        $this->stringTranslation->method('listApprovals')->willReturnCallback(
            fn(int $projectId, array $params): ModelCollection => self::collection($this->approvals[$params['stringId']] ?? [])
        );

        $sourceString = $this->createMock(SourceStringApi::class);
        $sourceString->method('get')->willReturnCallback(
            static fn(int $projectId, int $id): SourceString => new SourceString(['id' => $id, 'fileId' => $id === 1 ? 7 : 8, 'text' => 'Source ' . $id])
        );
        $sourceString->method('list')->willReturnCallback(
            fn(): ModelCollection => self::collection($this->hiddenStrings)
        );
        $file = $this->createMock(FileApi::class);
        $file->method('get')->willReturnCallback(
            static fn(int $projectId, int $id): File => new File(['id' => $id, 'path' => $id === 7 ? '/a/first.xlf' : '/b/second.xlf'])
        );

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['stringTranslation', $this->stringTranslation],
            ['sourceString', $sourceString],
            ['file', $file],
        ]);

        $this->tester = new CommandTester(new TranslationsUnapprovedCommand(new PendingTranslationRepository($crowdin)));
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

    private static function suggestion(int $id, string $username, string $createdAt = '2026-10-19T10:00:00+00:00'): StringTranslation
    {
        return new StringTranslation([
            'id' => $id,
            'text' => 'Texte ' . $id,
            'user' => ['id' => $id, 'username' => $username, 'fullName' => $username],
            'rating' => 0,
            'createdAt' => $createdAt,
            'pluralCategoryName' => null,
        ]);
    }

    public function testShowsEverySuggestionOfAStringAndFlagsTheCompetition(): void
    {
        $this->translations[1] = [self::suggestion(20, 'alice'), self::suggestion(21, 'carol', '2026-10-20T10:00:00+00:00')];

        $status = $this->tester->execute(['project' => '123', 'language' => 'fr']);

        self::assertSame(0, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('2 SUGGESTIONS FOR THIS STRING', $display);
        self::assertStringContainsString('(same string)', $display);
        self::assertStringContainsString('Texte 20', $display);
        self::assertStringContainsString('Texte 21', $display);
        self::assertStringContainsString('1 string(s) have competing suggestions', $display);
        self::assertStringContainsString('Showing 2 of 2 string(s) with unapproved translations.', $display);
        // Texte 20 is the oldest suggestion: it comes first
        self::assertLessThan(strpos($display, 'Texte 21'), strpos($display, 'Texte 20'));
    }

    public function testShowsTheApprovedTranslationAndListsUntranslatedStringsFirst(): void
    {
        // String 1 (first file) already has an approved translation, string 2 (second file) has none: files stay ordered
        $this->translations[1] = [self::suggestion(21, 'carol'), self::suggestion(90, 'dave', '2025-01-01T10:00:00+00:00')];
        $this->approvals[1] = [new StringTranslationApproval(['id' => 500, 'translationId' => 90, 'stringId' => 1])];

        $this->tester->execute(['project' => '123', 'language' => 'fr']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Current translation (approved)', $display);
        self::assertStringContainsString('Texte 90', $display);
        self::assertStringContainsString('1 string(s) already have an approved translation', $display);
        self::assertStringNotContainsString('SUGGESTIONS FOR THIS STRING', $display);
        self::assertLessThan(strpos($display, '/b/second.xlf'), strpos($display, '/a/first.xlf'));
    }

    private function hideSuggestionBehindApprovedTranslation(): void
    {
        // String 3 is approved ("Texte 300"): Crowdin lists that one only, the suggestion 301 of "pkraume" is hidden behind it
        $this->hiddenStrings = [new SourceString(['id' => 3, 'fileId' => 8])];
        $this->translations[3] = [self::suggestion(300, 'dave', '2025-01-01T10:00:00+00:00'), self::suggestion(301, 'pkraume', '2026-10-21T08:26:23+00:00')];
        $this->approvals[3] = [new StringTranslationApproval(['id' => 501, 'translationId' => 300, 'stringId' => 3])];
    }

    public function testShowsSuggestionsWhichAreHiddenBehindAnApprovedTranslationLast(): void
    {
        $this->hideSuggestionBehindApprovedTranslation();

        $this->tester->execute(['project' => '123', 'language' => 'fr']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('pkraume', $display);
        self::assertStringContainsString('Texte 301', $display);
        self::assertStringContainsString('Texte 300', $display);
        self::assertStringContainsString('Showing 3 of 3 string(s) with unapproved translations.', $display);
        self::assertStringContainsString('1 string(s) already have an approved translation', $display);
        // Same file as string 2, which has no translation at all: that one comes first
        self::assertLessThan(strpos($display, 'Texte 301'), strpos($display, 'Texte 12'));
        self::assertStringNotContainsString('more string(s) have suggestions behind', $display);
    }

    public function testLimitLeavesTheHiddenSuggestionsOutWhenThereIsNoRoomAndSaysSo(): void
    {
        $this->hideSuggestionBehindApprovedTranslation();

        $this->tester->execute(['project' => '123', 'language' => 'fr', '--limit' => '2']);

        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('Texte 301', $display);
        self::assertStringContainsString('Showing 2 of 3 string(s) with unapproved translations.', $display);
        self::assertStringContainsString('1 more string(s) have suggestions behind an approved translation', $display);
    }

    public function testLimitCutsInTheOrderOfTheFilesAndCountsStrings(): void
    {
        $this->translations[1] = [self::suggestion(20, 'alice'), self::suggestion(21, 'carol')];

        $this->tester->execute(['project' => '123', 'language' => 'fr', '--limit' => '1']);

        $display = $this->tester->getDisplay();
        // String 1 is in the first file, although Crowdin lists it last: it is the one which is kept, with all its suggestions
        self::assertStringContainsString('/a/first.xlf (1)', $display);
        self::assertStringNotContainsString('/b/second.xlf', $display);
        self::assertStringContainsString('Texte 20', $display);
        self::assertStringContainsString('Texte 21', $display);
        self::assertStringContainsString('Showing 1 of 2 string(s) with unapproved translations.', $display);
    }
}
