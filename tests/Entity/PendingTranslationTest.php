<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Entity\PendingTranslation;

final class PendingTranslationTest extends TestCase
{
    private function create(?int $userId, ?string $username, ?string $fullName): PendingTranslation
    {
        return new PendingTranslation(1, 2, 'Text', $userId, $username, $fullName, '2026-10-19T10:00:00+00:00');
    }

    /**
     * @return iterable<string, array{?int, ?string, ?string, string}>
     */
    public static function authorLabelProvider(): iterable
    {
        yield 'full name and username' => [1, 'jdoe', 'John Doe', 'John Doe (jdoe)'];
        yield 'full name equals username' => [1, 'jdoe', 'jdoe', 'jdoe'];
        yield 'no full name' => [1, 'jdoe', null, 'jdoe'];
        yield 'empty full name' => [1, 'jdoe', '', 'jdoe'];
        yield 'unknown user' => [null, null, null, '(unknown)'];
    }

    #[DataProvider('authorLabelProvider')]
    public function testAuthorLabel(?int $userId, ?string $username, ?string $fullName, string $expected): void
    {
        self::assertSame($expected, $this->create($userId, $username, $fullName)->authorLabel());
    }

    public function testIsSuggestedByMatchesUsernameCaseInsensitively(): void
    {
        $translation = $this->create(42, 'JDoe', 'John Doe');

        self::assertTrue($translation->isSuggestedBy('jdoe'));
        self::assertTrue($translation->isSuggestedBy('JDOE'));
        self::assertFalse($translation->isSuggestedBy('other'));
    }

    public function testIsSuggestedByMatchesUserId(): void
    {
        $translation = $this->create(42, 'jdoe', null);

        self::assertTrue($translation->isSuggestedBy('42'));
        self::assertFalse($translation->isSuggestedBy('43'));
    }

    public function testIsSuggestedByDoesNotMatchUnknownUser(): void
    {
        $translation = $this->create(null, null, null);

        self::assertFalse($translation->isSuggestedBy('jdoe'));
        self::assertFalse($translation->isSuggestedBy(''));
    }

    public function testReviewKeyDistinguishesPluralForms(): void
    {
        $one = new PendingTranslation(1, 2, 'Text', null, null, null, '2026-10-19T10:00:00+00:00', 'one');
        $other = new PendingTranslation(3, 2, 'Text', null, null, null, '2026-10-19T10:00:00+00:00', 'other');
        $plain = $this->create(null, null, null);
        $plainRival = new PendingTranslation(9, 2, 'Other text', null, null, null, '2026-10-20T10:00:00+00:00');

        self::assertNotSame($one->reviewKey(), $other->reviewKey());
        self::assertNotSame($one->reviewKey(), $plain->reviewKey());
        self::assertSame($plain->reviewKey(), $plainRival->reviewKey());
    }
}
