<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\ReviewInput;

final class ReviewInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function validInputProvider(): iterable
    {
        yield 'approve all' => ['a', ReviewInput::APPROVE, [1, 2, 3, 4, 5]];
        yield 'approve all, upper case and spaces' => ['  ALL ', ReviewInput::APPROVE, [1, 2, 3, 4, 5]];
        yield 'skip' => ['s', ReviewInput::SKIP, []];
        yield 'details' => ['v', ReviewInput::DETAILS, []];
        yield 'quit' => ['q', ReviewInput::QUIT, []];
        yield 'all except one' => ['e 3', ReviewInput::APPROVE, [1, 2, 4, 5]];
        yield 'all except several' => ['except 1, 5', ReviewInput::APPROVE, [2, 3, 4]];
        yield 'all except everything' => ['e 1-5', ReviewInput::APPROVE, []];
        yield 'only some' => ['o 2,4', ReviewInput::APPROVE, [2, 4]];
        yield 'only a range' => ['only 2-4', ReviewInput::APPROVE, [2, 3, 4]];
        yield 'only, unsorted with duplicates' => ['o 4 2 4', ReviewInput::APPROVE, [2, 4]];
    }

    /**
     * @param list<int> $positions
     */
    #[DataProvider('validInputProvider')]
    public function testParse(string $input, string $action, array $positions): void
    {
        $result = ReviewInput::parse($input, 5);

        self::assertNotNull($result);
        self::assertSame($action, $result->action);
        self::assertSame($positions, $result->positions);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown' => ['x'];
        yield 'position out of range' => ['e 6'];
        yield 'position zero' => ['o 0'];
        yield 'reversed range' => ['o 4-2'];
        yield 'missing positions' => ['e'];
        yield 'garbage positions' => ['o a,b'];
    }

    #[DataProvider('invalidInputProvider')]
    public function testParseRejectsInvalidInput(string $input): void
    {
        self::assertNull(ReviewInput::parse($input, 5));
    }
}
