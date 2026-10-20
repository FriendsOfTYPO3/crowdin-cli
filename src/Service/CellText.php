<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

/**
 * Prepares multi-line texts for display in a console table cell.
 */
final class CellText
{
    /**
     * Wraps the text at the given width (in characters, not bytes). Whitespace (including carriage
     * returns, which would mess up the cursor position within a table) is collapsed and trimmed first.
     */
    public static function wrap(string $text, int $width = 50): string
    {
        // Newlines and indentation in strings are mostly source formatting (e.g. XLF), which the Crowdin UI
        // collapses as well: trimming and collapsing them saves visual space
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $current = '';
            foreach (explode(' ', $line) as $word) {
                // Hard-cut words longer than the width
                while (mb_strlen($word) > $width) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, $width);
                    $word = mb_substr($word, $width);
                }
                if ($current === '') {
                    $current = $word;
                } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                    $current .= ' ' . $word;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }
            $lines[] = $current;
        }

        return implode("\n", $lines);
    }
}
