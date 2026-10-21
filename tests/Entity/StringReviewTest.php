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
