<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * A translation that has been suggested but not approved yet.
 */
final readonly class PendingTranslation
{
    public function __construct(
        public int $translationId,
        public int $stringId,
        public string $text,
        public ?int $userId,
        public ?string $username,
        public ?string $fullName,
        public string $createdAt,
        public ?string $pluralForm = null,
    ) {}

    public function authorLabel(): string
    {
        if ($this->username === null) {
            return '(unknown)';
        }

        return $this->fullName !== null && $this->fullName !== '' && $this->fullName !== $this->username
            ? sprintf('%s (%s)', $this->fullName, $this->username)
            : $this->username;
    }

    /**
     * Identifies what is translated: the string and, for plural strings, the plural form.
     * Suggestions with the same key compete with each other.
     */
    public function reviewKey(): string
    {
        return $this->stringId . ':' . ($this->pluralForm ?? '');
    }

    /**
     * The text without what Crowdin does not tell apart either: surrounding whitespace and runs of whitespace.
     */
    public function normalizedText(): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $this->text));
    }

    public function isSuggestedBy(string $user): bool
    {
        return ($this->username !== null && strcasecmp($this->username, $user) === 0)
            || ($this->userId !== null && (string)$this->userId === $user);
    }
}
