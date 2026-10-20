<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\CellText;

final class CellTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function wrapProvider(): iterable
    {
        yield 'short text is left untouched' => ['Hello world', 'Hello world', 20];
        yield 'empty text' => ['', '', 20];
        yield 'wraps at word boundaries' => ["aaa bbb\nccc ddd", 'aaa bbb ccc ddd', 7];
        yield 'text of exactly the width fits' => ['aaa bbb', 'aaa bbb', 7];
        yield 'leading and trailing whitespace is trimmed' => ['Hello', "\r\n  Hello \r\n", 20];
        yield 'newlines are collapsed' => ['one two three', "one\ntwo\r\n\r\nthree", 20];
        yield 'carriage returns never remain' => ['one two', "one\rtwo", 20];
        yield 'tabs and repeated spaces are collapsed' => ['a b c', "a\t b   c", 20];
        yield 'long words are cut' => ["abcde\nfghij\nkl", 'abcdefghijkl', 5];
        yield 'long word after a short one starts on a new line' => ["ab\ncdefg\nhi", 'ab cdefghi', 5];
        yield 'width is counted in characters, not bytes' => ['éééé ààà', 'éééé ààà', 8];
        yield 'multibyte characters are not split' => ["ééé\néé", 'ééééé', 3];
    }

    #[DataProvider('wrapProvider')]
    public function testWrap(string $expected, string $text, int $width): void
    {
        self::assertSame($expected, CellText::wrap($text, $width));
    }

    public function testDefaultWidthIsFifty(): void
    {
        $lines = explode("\n", CellText::wrap(str_repeat('word ', 30)));

        self::assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(50, mb_strlen($line));
        }
    }
}
