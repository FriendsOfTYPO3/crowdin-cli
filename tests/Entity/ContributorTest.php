<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Entity\Contributor;

final class ContributorTest extends TestCase
{
    #[DataProvider('labels')]
    public function testLabel(string $username, string $fullName, string $expected): void
    {
        self::assertSame($expected, (new Contributor($username, $fullName, 1, 1, 0))->label());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function labels(): iterable
    {
        yield 'name and username' => ['jdoe', 'Jane Doe', 'Jane Doe (jdoe)'];
        yield 'no name' => ['jdoe', '', 'jdoe'];
        yield 'name is the username' => ['jdoe', 'jdoe', 'jdoe'];
        yield 'username already in the name' => ['TYPO3', 'TYPO3 Project (TYPO3)', 'TYPO3 Project (TYPO3)'];
    }
}
