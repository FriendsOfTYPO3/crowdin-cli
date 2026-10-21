<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

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
use TYPO3\CrowdinCli\Entity\PendingTranslation;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AllowMockObjectsWithoutExpectations]
final class PendingTranslationRepositoryTest extends TestCase
{
    private StringTranslationApi&MockObject $stringTranslation;
    private SourceStringApi&MockObject $sourceString;
    private PendingTranslationRepository $subject;

    protected function setUp(): void
    {
        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        $this->sourceString = $this->createMock(SourceStringApi::class);

        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['stringTranslation', $this->stringTranslation],
            ['sourceString', $this->sourceString],
        ]);

        $this->subject = new PendingTranslationRepository($crowdin);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function languageTranslation(int $stringId, ?int $translationId, ?string $username, array $overrides = []): LanguageTranslation
    {
        return new LanguageTranslation($overrides + [
            'stringId' => $stringId,
            'contentType' => 'text/plain',
            'translationId' => $translationId,
            'text' => 'Text ' . $stringId,
            'user' => $username === null ? null : ['id' => $stringId * 10, 'username' => $username, 'fullName' => strtoupper($username)],
            'createdAt' => '2026-10-19T10:00:00+00:00',
        ]);
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

    public function testFindUnapprovedAsksCrowdinForTranslationsWithoutApproval(): void
    {
        $this->stringTranslation
            ->expects(self::once())
            ->method('listLanguageTranslations')
            ->with(123, 'de', ['croql' => 'count of approvals = 0', 'limit' => 500, 'offset' => 0])
            ->willReturn(self::collection([self::languageTranslation(1, 11, 'alice')]));

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertCount(1, $result);
        self::assertSame(11, $result[0]->translationId);
        self::assertSame(1, $result[0]->stringId);
        self::assertSame('Text 1', $result[0]->text);
        self::assertSame('alice', $result[0]->username);
        self::assertSame('ALICE (alice)', $result[0]->authorLabel());
    }

    public function testFindUnapprovedDropsDuplicatedTranslations(): void
    {
        $this->stringTranslation
            ->method('listLanguageTranslations')
            ->willReturn(self::collection([
                self::languageTranslation(1, 11, 'alice'),
                self::languageTranslation(2, 12, 'bob'),
                self::languageTranslation(1, 11, 'alice'),
            ]));

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertSame([11, 12], array_map(static fn($t): int => $t->translationId, $result));
    }

    public function testFindUnapprovedFiltersByUser(): void
    {
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::languageTranslation(1, 11, 'alice'),
            self::languageTranslation(2, 12, 'bob'),
            self::languageTranslation(3, 13, 'alice'),
        ]));

        $result = $this->subject->findUnapproved(123, 'de', 'ALICE');

        self::assertSame([11, 13], array_map(static fn($t): int => $t->translationId, $result));
    }

    public function testFindUnapprovedFiltersByPathPrefix(): void
    {
        $fileApi = $this->createMock(FileApi::class);
        $fileApi->expects(self::once())->method('list')->with(123, ['limit' => 500, 'offset' => 0])->willReturnCallback(function (): ModelCollection {
            $collection = new ModelCollection();
            foreach ([1 => '/14.3/typo3/sysext/backend/de.xlf', 2 => '/14.3/typo3/sysext/core/de.xlf', 3 => '/14.3/typo3/sysext/backend/other.xlf'] as $id => $path) {
                $collection->add(new File(['id' => $id, 'path' => $path]));
            }

            return $collection;
        });
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['stringTranslation', $this->stringTranslation],
            ['file', $fileApi],
        ]);

        $calls = [];
        $this->stringTranslation
            ->expects(self::exactly(4))
            ->method('listLanguageTranslations')
            ->willReturnCallback(function (int $projectId, string $languageId, array $params) use (&$calls): ModelCollection {
                self::assertArrayNotHasKey('croql', $params);
                $fileId = $params['fileId'];
                $calls[] = [$fileId, $params['approvedOnly'] ?? 0];
                $items = [self::languageTranslation($fileId, $fileId * 10, 'alice'), self::languageTranslation($fileId + 100, $fileId * 10 + 1, 'bob')];

                // The second translation of each file is approved
                return self::collection(isset($params['approvedOnly']) ? [$items[1]] : $items);
            });

        $result = (new PendingTranslationRepository($crowdin))->findUnapproved(123, 'de', null, '14.3/typo3/sysext/backend');

        self::assertSame([[1, 1], [1, 0], [3, 1], [3, 0]], $calls);
        self::assertSame([10, 30], array_map(static fn($t): int => $t->translationId, $result));
    }

    public function testFindUnapprovedSkipsEntriesWithoutTranslationId(): void
    {
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::languageTranslation(1, null, null, ['text' => null]),
            self::languageTranslation(2, 12, 'bob'),
        ]));

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertSame([12], array_map(static fn($t): int => $t->translationId, $result));
    }

    public function testFindUnapprovedHandlesUnknownUser(): void
    {
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::languageTranslation(1, 11, null),
        ]));

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertNull($result[0]->username);
        self::assertSame('(unknown)', $result[0]->authorLabel());
    }

    public function testFindUnapprovedFlattensPluralForms(): void
    {
        $this->stringTranslation->method('listLanguageTranslations')->willReturn(self::collection([
            self::languageTranslation(5, null, 'alice', [
                'text' => null,
                'contentType' => 'application/vnd.crowdin.text+plural',
                'plurals' => [
                    'one' => ['translationId' => 51, 'text' => '1 Datei', 'pluralForm' => 'one'],
                    'other' => ['translationId' => 52, 'text' => '%d Dateien', 'pluralForm' => 'other', 'user' => ['id' => 7, 'username' => 'bob']],
                ],
            ]),
        ]));

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertCount(2, $result);
        self::assertSame([51, 52], array_map(static fn($t): int => $t->translationId, $result));
        self::assertSame(['one', 'other'], array_map(static fn($t): ?string => $t->pluralForm, $result));
        self::assertSame('1 Datei', $result[0]->text);
        // A plural form without own user falls back to the user of the string translation
        self::assertSame('alice', $result[0]->username);
        self::assertSame('bob', $result[1]->username);
    }

    public function testFindUnapprovedPaginates(): void
    {
        $firstPage = [];
        for ($i = 1; $i <= 500; $i++) {
            $firstPage[] = self::languageTranslation($i, $i, 'alice');
        }

        $offsets = [];
        $this->stringTranslation
            ->expects(self::exactly(2))
            ->method('listLanguageTranslations')
            ->willReturnCallback(function (int $projectId, string $languageId, array $params) use (&$offsets, $firstPage): ModelCollection {
                $offsets[] = $params['offset'];

                return $params['offset'] === 0
                    ? self::collection($firstPage)
                    : self::collection([self::languageTranslation(501, 501, 'bob')]);
            });

        $result = $this->subject->findUnapproved(123, 'de');

        self::assertSame([0, 500], $offsets);
        self::assertCount(501, $result);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function stringTranslation(int $id, string $username, string $createdAt, array $overrides = []): StringTranslation
    {
        return new StringTranslation($overrides + [
            'id' => $id,
            'text' => 'Text ' . $id,
            'user' => ['id' => $id, 'username' => $username, 'fullName' => $username],
            'rating' => 0,
            'createdAt' => $createdAt,
            'pluralCategoryName' => null,
        ]);
    }

    private static function pending(int $stringId, int $translationId, string $username, ?string $pluralForm = null): PendingTranslation
    {
        return new PendingTranslation($translationId, $stringId, 'Text ' . $translationId, $translationId, $username, $username, '2026-10-21T10:00:00+00:00', $pluralForm);
    }

    public function testFindReviewsGathersCompetingSuggestionsOldestFirst(): void
    {
        $this->stringTranslation
            ->expects(self::once())
            ->method('list')
            ->with(123, ['stringId' => 5, 'languageId' => 'de', 'limit' => 500])
            ->willReturn(self::collection([
                self::stringTranslation(52, 'bob', '2026-10-21T10:00:00+00:00'),
                self::stringTranslation(51, 'alice', '2026-10-20T10:00:00+00:00'),
            ]));
        $this->stringTranslation
            ->expects(self::once())
            ->method('listApprovals')
            ->with(123, ['stringId' => 5, 'languageId' => 'de', 'limit' => 500])
            ->willReturn(self::collection([]));

        $reviews = $this->subject->findReviews(123, 'de', [self::pending(5, 52, 'bob')]);

        self::assertCount(1, $reviews);
        self::assertSame(5, $reviews[0]->stringId);
        self::assertSame([51, 52], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
        self::assertSame('alice', $reviews[0]->candidates[0]->username);
        self::assertTrue($reviews[0]->isContested());
        self::assertNull($reviews[0]->approved);
        self::assertFalse($reviews[0]->improvesApproved());
    }

    public function testFindReviewsSeparatesTheApprovedTranslationFromTheSuggestions(): void
    {
        $this->stringTranslation->method('list')->willReturn(self::collection([
            self::stringTranslation(51, 'alice', '2026-10-19T10:00:00+00:00'),
            self::stringTranslation(52, 'bob', '2026-10-21T10:00:00+00:00'),
        ]));
        $this->stringTranslation->method('listApprovals')->willReturn(self::collection([
            new StringTranslationApproval(['id' => 900, 'translationId' => 51, 'stringId' => 5]),
        ]));

        $reviews = $this->subject->findReviews(123, 'de', [self::pending(5, 52, 'bob')]);

        self::assertSame(51, $reviews[0]->approved?->translationId);
        self::assertSame([52], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
        self::assertFalse($reviews[0]->isContested());
        self::assertTrue($reviews[0]->improvesApproved());
    }

    public function testFindReviewsKeepsTheGivenTranslationEvenIfCrowdinDoesNotListIt(): void
    {
        $this->stringTranslation->method('list')->willReturn(self::collection([]));
        $this->stringTranslation->method('listApprovals')->willReturn(self::collection([]));

        $reviews = $this->subject->findReviews(123, 'de', [self::pending(5, 52, 'bob')]);

        self::assertSame([52], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
    }

    public function testFindReviewsLoadsEachStringOnceAndKeepsPluralFormsApart(): void
    {
        $this->stringTranslation
            ->expects(self::once())
            ->method('list')
            ->willReturn(self::collection([
                self::stringTranslation(51, 'alice', '2026-10-19T10:00:00+00:00', ['pluralCategoryName' => 'one']),
                self::stringTranslation(52, 'bob', '2026-10-20T10:00:00+00:00', ['pluralCategoryName' => 'one']),
                self::stringTranslation(53, 'alice', '2026-10-19T10:00:00+00:00', ['pluralCategoryName' => 'other']),
            ]));
        $this->stringTranslation->expects(self::once())->method('listApprovals')->willReturn(self::collection([]));

        $reviews = $this->subject->findReviews(123, 'de', [self::pending(5, 51, 'alice', 'one'), self::pending(5, 53, 'alice', 'other')]);

        self::assertCount(2, $reviews);
        self::assertSame([51, 52], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
        self::assertSame('one', $reviews[0]->pluralForm);
        self::assertSame([53], array_map(static fn($t): int => $t->translationId, $reviews[1]->candidates));
    }

    public function testFindStringsWithHiddenSuggestionsAsksForApprovedStringsWithAnUnapprovedTranslation(): void
    {
        $this->sourceString
            ->expects(self::once())
            ->method('list')
            ->with(123, [
                'croql' => 'count of languages summary where (language = @language:"de" and is approved) > 0 and count of translations where (count of approvals = 0 and language = @language:"de") > 0',
                'limit' => 500,
                'offset' => 0,
            ])
            ->willReturn(self::collection([
                new SourceString(['id' => 30, 'fileId' => 2]),
                new SourceString(['id' => 20, 'fileId' => 1]),
                new SourceString(['id' => 10, 'fileId' => 2]),
            ]));

        $strings = $this->subject->findStringsWithHiddenSuggestions(123, 'de');

        // Ordered by file, then by string
        self::assertSame([20 => 1, 10 => 2, 30 => 2], $strings);
    }

    public function testFindStringsWithHiddenSuggestionsNarrowsByUsernameButNotByUserId(): void
    {
        $croql = [];
        $this->sourceString
            ->method('list')
            ->willReturnCallback(function (int $projectId, array $params) use (&$croql): ModelCollection {
                $croql[] = $params['croql'];

                return self::collection([]);
            });

        $this->subject->findStringsWithHiddenSuggestions(123, 'de', 'pkraume');
        $this->subject->findStringsWithHiddenSuggestions(123, 'de', '15020195');
        $this->subject->findStringsWithHiddenSuggestions(123, 'de', 'evil" or true');

        self::assertStringContainsString('count of approvals = 0 and language = @language:"de" and user = @user:"pkraume")', $croql[0]);
        self::assertStringNotContainsString('@user', $croql[1]);
        self::assertStringNotContainsString('@user', $croql[2]);
    }

    public function testFindStringsWithHiddenSuggestionsFiltersByPathPrefix(): void
    {
        $fileApi = $this->createMock(FileApi::class);
        $fileApi->method('list')->willReturnCallback(function (): ModelCollection {
            return self::collection([new File(['id' => 1, 'path' => '/14.3/typo3/sysext/backend/de.xlf']), new File(['id' => 2, 'path' => '/14.3/typo3/sysext/core/de.xlf'])]);
        });
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([
            ['sourceString', $this->sourceString],
            ['file', $fileApi],
        ]);
        $this->sourceString->method('list')->willReturn(self::collection([
            new SourceString(['id' => 10, 'fileId' => 1]),
            new SourceString(['id' => 20, 'fileId' => 2]),
        ]));

        $strings = (new PendingTranslationRepository($crowdin))->findStringsWithHiddenSuggestions(123, 'de', null, '14.3/typo3/sysext/backend');

        self::assertSame([10 => 1], $strings);
    }

    public function testFindStringsWithHiddenSuggestionsPaginates(): void
    {
        $firstPage = [];
        for ($i = 1; $i <= 500; $i++) {
            $firstPage[] = new SourceString(['id' => $i, 'fileId' => 1]);
        }
        $offsets = [];
        $this->sourceString
            ->expects(self::exactly(2))
            ->method('list')
            ->willReturnCallback(function (int $projectId, array $params) use (&$offsets, $firstPage): ModelCollection {
                $offsets[] = $params['offset'];

                return self::collection($params['offset'] === 0 ? $firstPage : [new SourceString(['id' => 501, 'fileId' => 1])]);
            });

        self::assertCount(501, $this->subject->findStringsWithHiddenSuggestions(123, 'de'));
        self::assertSame([0, 500], $offsets);
    }

    public function testFindHiddenReviewsReturnsTheSuggestionsNextToTheApprovedTranslation(): void
    {
        // The case of "Reaktionen", approved, and the later suggestion "Reactions"
        $this->stringTranslation->method('list')->willReturn(self::collection([
            self::stringTranslation(1463032, 'steffenmatthes', '2025-12-14T21:48:05+00:00', ['text' => 'Reaktionen']),
            self::stringTranslation(1951731, 'pkraume', '2026-10-21T08:26:23+00:00', ['text' => 'Reactions']),
        ]));
        $this->stringTranslation->method('listApprovals')->willReturn(self::collection([
            new StringTranslationApproval(['id' => 900, 'translationId' => 1463032, 'stringId' => 379326]),
        ]));

        $reviews = $this->subject->findHiddenReviews(123, 'de', [379326], 'pkraume');

        self::assertCount(1, $reviews);
        self::assertSame(379326, $reviews[0]->stringId);
        self::assertSame(1463032, $reviews[0]->approved?->translationId);
        self::assertSame([1951731], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
        self::assertSame('pkraume', $reviews[0]->candidates[0]->username);
        self::assertTrue($reviews[0]->improvesApproved());
    }

    public function testFindHiddenReviewsSkipsStringsOfOtherUsersAndStringsWithoutApprovedTranslation(): void
    {
        $this->stringTranslation->method('list')->willReturnCallback(fn(int $projectId, array $params): ModelCollection => self::collection([
            self::stringTranslation($params['stringId'] * 10 + 1, 'alice', '2026-10-19T10:00:00+00:00'),
            self::stringTranslation($params['stringId'] * 10 + 2, 'bob', '2026-10-20T10:00:00+00:00'),
        ]));
        // Only the translation of "alice" is approved on string 1, nothing on string 2
        $this->stringTranslation->method('listApprovals')->willReturnCallback(fn(int $projectId, array $params): ModelCollection => self::collection(
            $params['stringId'] === 1 ? [new StringTranslationApproval(['id' => 900, 'translationId' => 11, 'stringId' => 1])] : []
        ));

        self::assertCount(1, $this->subject->findHiddenReviews(123, 'de', [1, 2]));
        self::assertSame([12], array_map(static fn($t): int => $t->translationId, $this->subject->findHiddenReviews(123, 'de', [1, 2], 'bob')[0]->candidates));
        self::assertSame([], $this->subject->findHiddenReviews(123, 'de', [1, 2], 'carol'));
    }

    public function testFindHiddenReviewsKeepsPluralFormsApart(): void
    {
        $this->stringTranslation->method('list')->willReturn(self::collection([
            self::stringTranslation(51, 'alice', '2026-10-19T10:00:00+00:00', ['pluralCategoryName' => 'one']),
            self::stringTranslation(52, 'bob', '2026-10-20T10:00:00+00:00', ['pluralCategoryName' => 'one']),
            self::stringTranslation(53, 'alice', '2026-10-19T10:00:00+00:00', ['pluralCategoryName' => 'other']),
        ]));
        // Only the form "one" is approved: the suggestion for "other" improves nothing
        $this->stringTranslation->method('listApprovals')->willReturn(self::collection([
            new StringTranslationApproval(['id' => 900, 'translationId' => 51, 'stringId' => 5]),
        ]));

        $reviews = $this->subject->findHiddenReviews(123, 'de', [5]);

        self::assertCount(1, $reviews);
        self::assertSame('one', $reviews[0]->pluralForm);
        self::assertSame([52], array_map(static fn($t): int => $t->translationId, $reviews[0]->candidates));
    }

    public function testFindSourceTextsFetchesEachStringOnce(): void
    {
        $this->sourceString
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (int $projectId, int $stringId): SourceString {
                self::assertSame(123, $projectId);
                $string = $this->createMock(SourceString::class);
                $string->method('getText')->willReturn($stringId === 1 ? 'Hello' : ['One file', '%d files']);

                return $string;
            });

        $texts = $this->subject->findSourceTexts(123, [1, 2, 1]);

        self::assertSame([1 => 'Hello', 2 => 'One file | %d files'], $texts);
    }

    public function testFindSourcesReturnsTextAndFileId(): void
    {
        $string = $this->createMock(SourceString::class);
        $string->method('getText')->willReturn('Hello');
        $string->method('getFileId')->willReturn(42);
        $string->method('getIdentifier')->willReturn('LGL.usergroups');
        $this->sourceString->expects(self::once())->method('get')->with(123, 1)->willReturn($string);

        self::assertSame([1 => ['text' => 'Hello', 'fileId' => 42, 'identifier' => 'LGL.usergroups']], $this->subject->findSources(123, [1]));
    }

    public function testFindSourcesLoadsWholeFileWhenManyStringsAreNeeded(): void
    {
        $stringIds = range(1, 30);
        $this->sourceString
            ->expects(self::once())
            ->method('get')
            ->with(123, 1)
            ->willReturn(new SourceString(['id' => 1, 'text' => 'Text 1', 'fileId' => 42, 'identifier' => 'key1']));
        $this->sourceString
            ->expects(self::once())
            ->method('list')
            ->willReturnCallback(function (int $projectId, array $params): ModelCollection {
                self::assertSame(42, $params['fileId']);
                self::assertSame(0, $params['offset']);

                return self::stringCollection(1, 500);
            });

        $sources = $this->subject->findSources(123, $stringIds);

        // A full page, but everything needed was found: no further page is requested
        self::assertCount(30, $sources);
        self::assertSame(['text' => 'Text 30', 'fileId' => 42, 'identifier' => 'key30'], $sources[30]);
    }

    public function testFindSourcesPagesThroughTheFileUntilEverythingIsFound(): void
    {
        $this->sourceString->method('get')->willReturn(new SourceString(['id' => 1, 'text' => 'Text 1', 'fileId' => 42, 'identifier' => 'key1']));
        $offsets = [];
        $this->sourceString
            ->expects(self::exactly(2))
            ->method('list')
            ->willReturnCallback(function (int $projectId, array $params) use (&$offsets): ModelCollection {
                $offsets[] = $params['offset'];

                return $params['offset'] === 0 ? self::stringCollection(1, 500) : self::stringCollection(501, 520);
            });

        $sources = $this->subject->findSources(123, [...range(1, 25), 510]);

        self::assertSame([0, 500], $offsets);
        self::assertSame('Text 510', $sources[510]['text']);
        self::assertCount(26, $sources);
    }

    private static function stringCollection(int $from, int $to): ModelCollection
    {
        $collection = new ModelCollection();
        for ($id = $from; $id <= $to; $id++) {
            $collection->add(new SourceString(['id' => $id, 'text' => 'Text ' . $id, 'fileId' => 42, 'identifier' => 'key' . $id]));
        }

        return $collection;
    }

    public function testFindFilePathsFetchesEachFileOnce(): void
    {
        $fileApi = $this->createMock(FileApi::class);
        $fileApi->expects(self::once())->method('get')->with(123, 42)->willReturnCallback(function (): File {
            $file = $this->createMock(File::class);
            $file->method('getPath')->willReturn('/typo3/core/de.xlf');

            return $file;
        });
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['file', $fileApi]]);

        $paths = (new PendingTranslationRepository($crowdin))->findFilePaths(123, [42, 42]);

        self::assertSame([42 => '/typo3/core/de.xlf'], $paths);
    }

    public function testApproveCreatesApprovalAndReturnsItsId(): void
    {
        $this->stringTranslation
            ->expects(self::once())
            ->method('createApproval')
            ->with(123, ['translationId' => 456])
            ->willReturn(new StringTranslationApproval(['id' => 789, 'translationId' => 456]));

        self::assertSame(789, $this->subject->approve(123, 456));
    }

    public function testRevokeApprovalDeletesApproval(): void
    {
        $this->stringTranslation
            ->expects(self::once())
            ->method('deleteApproval')
            ->with(123, 789);

        $this->subject->revokeApproval(123, 789);
    }
}
