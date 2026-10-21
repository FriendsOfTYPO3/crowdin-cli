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
        yield 'drop some' => ['d 2,4', ReviewInput::DROP, [2, 4]];
        yield 'drop a range' => ['drop 2-3', ReviewInput::DROP, [2, 3]];
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

    /**
     * @return iterable<string, array{string, bool, string, list<int>}>
     */
    public static function choiceProvider(): iterable
    {
        yield 'approve the second' => ['2', false, ReviewInput::CHOOSE, [2]];
        yield 'approve, with spaces' => ['  3 ', true, ReviewInput::CHOOSE, [3]];
        yield 'drop some' => ['d 1,3', false, ReviewInput::DROP, [1, 3]];
        yield 'drop a range' => ['drop 1-2', false, ReviewInput::DROP, [1, 2]];
        yield 'skip' => ['s', false, ReviewInput::SKIP, []];
        yield 'quit' => ['Q', false, ReviewInput::QUIT, []];
        yield 'keep the current translation' => ['k', true, ReviewInput::KEEP, []];
    }

    /**
     * @param list<int> $positions
     */
    #[DataProvider('choiceProvider')]
    public function testParseChoice(string $input, bool $canKeep, string $action, array $positions): void
    {
        $result = ReviewInput::parseChoice($input, 3, $canKeep);

        self::assertNotNull($result);
        self::assertSame($action, $result->action);
        self::assertSame($positions, $result->positions);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invalidChoiceProvider(): iterable
    {
        yield 'empty' => ['', true];
        yield 'out of range' => ['4', true];
        yield 'zero' => ['0', true];
        yield 'keep without current translation' => ['k', false];
        yield 'approve all is ambiguous' => ['a', true];
        yield 'drop out of range' => ['d 4', true];
        yield 'drop without position' => ['d', true];
        yield 'several numbers' => ['1 2', true];
    }

    #[DataProvider('invalidChoiceProvider')]
    public function testParseChoiceRejectsInvalidInput(string $input, bool $canKeep): void
    {
        self::assertNull(ReviewInput::parseChoice($input, 3, $canKeep));
    }
}
