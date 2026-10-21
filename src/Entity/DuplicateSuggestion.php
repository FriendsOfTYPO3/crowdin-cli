<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * A suggestion which adds nothing, since the same text is already there.
 */
final readonly class DuplicateSuggestion
{
    /**
     * @param PendingTranslation $suggestion the redundant suggestion
     * @param PendingTranslation $original what makes it redundant: the approved translation or an older suggestion
     * @param bool $ofApproved whether the original is the approved translation
     */
    public function __construct(
        public PendingTranslation $suggestion,
        public PendingTranslation $original,
        public bool $ofApproved,
    ) {}

    public function reason(): string
    {
        return $this->ofApproved
            ? 'same as the approved translation'
            : sprintf('same as the older suggestion #%d', $this->original->translationId);
    }
}
