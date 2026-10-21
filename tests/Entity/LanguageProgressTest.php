<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Entity;

use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Entity\LanguageProgress;

final class LanguageProgressTest extends TestCase
{
    public function testPendingIsTranslatedMinusApproved(): void
    {
        self::assertSame(15, (new LanguageProgress('fr', 80, 65))->pending());
    }

    public function testPendingNeverNegative(): void
    {
        self::assertSame(0, (new LanguageProgress('fr', 50, 60))->pending());
    }

    public function testHasPendingUsesExactStringCountWhenKnown(): void
    {
        self::assertTrue((new LanguageProgress('de', 99, 99, 4))->hasPending());
        self::assertFalse((new LanguageProgress('de', 99, 99, 0))->hasPending());
    }

    public function testHasPendingFallsBackToPercentages(): void
    {
        self::assertTrue((new LanguageProgress('de', 80, 65))->hasPending());
        self::assertFalse((new LanguageProgress('de', 99, 99))->hasPending());
    }
}
