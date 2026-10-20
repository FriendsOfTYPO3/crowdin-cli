<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * Translation progress of a project in one language, in percent.
 */
final readonly class LanguageProgress
{
    public function __construct(
        public string $languageId,
        public int $translated,
        public int $approved,
    ) {}

    /**
     * Percentage of translated strings which still wait for approval.
     */
    public function pending(): int
    {
        return max(0, $this->translated - $this->approved);
    }
}
