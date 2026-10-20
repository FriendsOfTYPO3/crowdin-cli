<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

/**
 * Answer of the user to a chunk of translations to review: approve all, all except some, only some, drop some, skip, ...
 *
 * Translations are referred to by their position (1-based) in the displayed chunk.
 */
final readonly class ReviewInput
{
    public const string APPROVE = 'approve';
    public const string DROP = 'drop';
    public const string SKIP = 'skip';
    public const string DETAILS = 'details';
    public const string QUIT = 'quit';

    /**
     * @param list<int> $positions 1-based positions of the translations to approve or drop (for APPROVE and DROP only)
     */
    private function __construct(
        public string $action,
        public array $positions = [],
    ) {}

    /**
     * @return self|null null if the input is not understood
     */
    public static function parse(string $input, int $chunkSize): ?self
    {
        $input = strtolower(trim($input));
        $all = $chunkSize > 0 ? range(1, $chunkSize) : [];

        switch ($input) {
            case 'a':
            case 'all':
            case 'y':
                return new self(self::APPROVE, $all);
            case 's':
            case 'skip':
            case 'n':
                return new self(self::SKIP);
            case 'v':
            case 'details':
                return new self(self::DETAILS);
            case 'q':
            case 'quit':
                return new self(self::QUIT);
        }

        // "e 3,7" (all except), "o 3,7" (only) or "d 3,7" (drop)
        if (preg_match('/^(e|except|o|only|d|drop)\s+(.+)$/', $input, $matches) !== 1) {
            return null;
        }
        $positions = self::parsePositions($matches[2], $chunkSize);
        if ($positions === null) {
            return null;
        }

        if (in_array($matches[1], ['d', 'drop'], true)) {
            return new self(self::DROP, $positions);
        }

        return in_array($matches[1], ['e', 'except'], true)
            ? new self(self::APPROVE, array_values(array_diff($all, $positions)))
            : new self(self::APPROVE, $positions);
    }

    /**
     * Parses "3", "1,4,7", "2-5" or a mix of them (separated by commas and/or spaces).
     *
     * @return list<int>|null sorted, unique positions. null if invalid or out of range
     */
    public static function parsePositions(string $list, int $max): ?array
    {
        $positions = [];
        foreach (preg_split('/[\s,]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $part, $m) !== 1) {
                return null;
            }
            $from = (int)$m[1];
            $to = isset($m[2]) ? (int)$m[2] : $from;
            if ($from < 1 || $to < $from || $to > $max) {
                return null;
            }
            array_push($positions, ...range($from, $to));
        }
        if ($positions === []) {
            return null;
        }
        $positions = array_values(array_unique($positions));
        sort($positions);

        return $positions;
    }
}
