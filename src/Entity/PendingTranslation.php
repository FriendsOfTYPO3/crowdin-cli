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

    public function isSuggestedBy(string $user): bool
    {
        return ($this->username !== null && strcasecmp($this->username, $user) === 0)
            || ($this->userId !== null && (string)$this->userId === $user);
    }
}
