<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * Who may proofread a project: the proofreaders of every language, plus the members who may proofread all of them.
 */
final readonly class ProjectProofreaders
{
    /**
     * @param array<string, list<string>> $byLanguage proofreader labels ("Name (username)"), indexed by language ID
     * @param list<string> $managers labels of the owner and managers, who can proofread every language
     */
    public function __construct(
        public array $byLanguage,
        public array $managers,
    ) {}
}
