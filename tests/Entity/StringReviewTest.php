<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Entity;

use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Entity\PendingTranslation;
use TYPO3\CrowdinCli\Entity\StringReview;

final class StringReviewTest extends TestCase
{
    private static function translation(int $id): PendingTranslation
    {
        return new PendingTranslation($id, 7, 'Text ' . $id, null, 'user' . $id, null, '2026-10-19T10:00:00+00:00');
    }

    public function testSingleSuggestionIsNotContested(): void
    {
        $review = new StringReview(7, null, [self::translation(1)]);

        self::assertFalse($review->isContested());
        self::assertFalse($review->improvesApproved());
    }

    public function testSeveralSuggestionsAreContested(): void
    {
        $review = new StringReview(7, null, [self::translation(1), self::translation(2)]);

        self::assertTrue($review->isContested());
    }

    public function testApprovedTranslationMakesSuggestionsAnImprovement(): void
    {
        $review = new StringReview(7, null, [self::translation(2)], self::translation(1));

        self::assertTrue($review->improvesApproved());
    }

    private static function suggestion(int $id, string $text): PendingTranslation
    {
        return new PendingTranslation($id, 7, $text, null, 'user' . $id, null, '2026-10-19T10:00:00+00:00');
    }

    public function testSuggestionIdenticalToApprovedTranslationIsDuplicate(): void
    {
        $approved = self::suggestion(1, 'Abbrechen');
        $review = new StringReview(7, null, [self::suggestion(2, 'Abbruch'), self::suggestion(3, "  Abbrechen \n")], $approved);

        $duplicates = $review->duplicates();

        self::assertCount(1, $duplicates);
        self::assertSame(3, $duplicates[0]->suggestion->translationId);
        self::assertSame(1, $duplicates[0]->original->translationId);
        self::assertTrue($duplicates[0]->ofApproved);
        self::assertSame('same as the approved translation', $duplicates[0]->reason());
    }

    public function testAllButTheOldestOfIdenticalSuggestionsAreDuplicates(): void
    {
        $review = new StringReview(7, null, [
            self::suggestion(2, 'Sie müssen'),
            self::suggestion(3, 'Du musst'),
            self::suggestion(4, 'Sie müssen'),
            self::suggestion(5, 'Sie  müssen'),
        ]);

        $duplicates = $review->duplicates();

        self::assertSame([4, 5], array_map(static fn($d): int => $d->suggestion->translationId, $duplicates));
        self::assertSame([2, 2], array_map(static fn($d): int => $d->original->translationId, $duplicates));
        self::assertFalse($duplicates[0]->ofApproved);
        self::assertSame('same as the older suggestion #2', $duplicates[0]->reason());
    }

    public function testApprovedTranslationWinsOverOlderSuggestion(): void
    {
        $review = new StringReview(7, null, [self::suggestion(2, 'Ja'), self::suggestion(3, 'Ja')], self::suggestion(1, 'Ja'));

        self::assertSame([2, 3], array_map(static fn($d): int => $d->suggestion->translationId, $review->duplicates()));
        self::assertTrue($review->duplicates()[1]->ofApproved);
    }

    public function testDifferentSuggestionsAreNoDuplicates(): void
    {
        $review = new StringReview(7, null, [self::suggestion(2, 'Entfernen'), self::suggestion(3, 'Löschen')], self::suggestion(1, 'Abbrechen'));

        self::assertSame([], $review->duplicates());
    }

    public function testRivalsAreTheOtherSuggestions(): void
    {
        $first = self::translation(1);
        $second = self::translation(2);
        $third = self::translation(3);
        $review = new StringReview(7, null, [$first, $second, $third]);

        self::assertSame([1, 3], array_map(static fn(PendingTranslation $t): int => $t->translationId, $review->rivalsOf($second)));
        self::assertSame([], (new StringReview(7, null, [$first]))->rivalsOf($first));
    }
}
