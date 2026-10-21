<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * Everything to know to review one string (or one plural form of it): the suggestions competing for it and the
 * translation it already has, if any.
 */
final readonly class StringReview
{
    /**
     * @param non-empty-list<PendingTranslation> $candidates the unapproved suggestions, oldest first
     * @param PendingTranslation|null $approved the translation which is currently approved for the string
     */
    public function __construct(
        public int $stringId,
        public ?string $pluralForm,
        public array $candidates,
        public ?PendingTranslation $approved = null,
    ) {}

    /**
     * More than one suggestion competes: approving one makes the others pointless.
     */
    public function isContested(): bool
    {
        return count($this->candidates) > 1;
    }

    /**
     * The string is already translated: the suggestions only try to make that translation better, which is
     * less urgent than translating something which has no translation at all.
     */
    public function improvesApproved(): bool
    {
        return $this->approved !== null;
    }

    /**
     * @return list<PendingTranslation> the suggestions which become pointless if the given one is approved
     */
    public function rivalsOf(PendingTranslation $chosen): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn(PendingTranslation $candidate): bool => $candidate->translationId !== $chosen->translationId
        ));
    }
}
