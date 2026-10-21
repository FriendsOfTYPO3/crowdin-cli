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
        public ?int $pendingStrings = null,
    ) {}

    /**
     * Whether translations wait for approval. Based on the exact number of strings when known,
     * as the percentages are rounded and may hide a few pending translations.
     */
    public function hasPending(): bool
    {
        return $this->pendingStrings !== null ? $this->pendingStrings > 0 : $this->pending() > 0;
    }

    /**
     * Percentage of translated strings which still wait for approval (rounded).
     */
    public function pending(): int
    {
        return max(0, $this->translated - $this->approved);
    }
}
