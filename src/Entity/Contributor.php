<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * What a member contributed to a project in one language, as reported by the Top Members report.
 */
final readonly class Contributor
{
    public function __construct(
        public string $username,
        public string $fullName,
        public int $translated,
        public int $approved,
        public int $voted,
    ) {}

    /**
     * "Full Name (username)", without repeating the username when Crowdin already did.
     */
    public function label(): string
    {
        if ($this->fullName === '' || $this->fullName === $this->username || str_contains($this->fullName, '(' . $this->username . ')')) {
            return $this->fullName !== '' ? $this->fullName : $this->username;
        }

        return sprintf('%s (%s)', $this->fullName, $this->username);
    }
}
