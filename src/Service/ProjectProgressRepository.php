<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\Progress;
use TYPO3\CrowdinCli\Entity\LanguageProgress;

/**
 * Retrieves the translation progress of a project through the Crowdin API.
 */
final readonly class ProjectProgressRepository
{
    public function __construct(
        private Crowdin $crowdin,
    ) {}

    /**
     * @param string|null $languageId only this language
     * @return list<LanguageProgress> ordered by language ID
     */
    public function findProgress(int $projectId, ?string $languageId = null): array
    {
        $progress = $this->crowdin->translationStatus->getProjectProgress($projectId, ['limit' => 500]);
        if ($progress === null) {
            throw new \RuntimeException('Crowdin returned no progress information.');
        }

        $result = [];
        /** @var Progress $item */
        foreach ($progress as $item) {
            if ($languageId !== null && strcasecmp($item->getLanguageId(), $languageId) !== 0) {
                continue;
            }
            $result[] = new LanguageProgress($item->getLanguageId(), $item->getTranslationProgress(), $item->getApprovalProgress());
        }
        usort($result, static fn(LanguageProgress $a, LanguageProgress $b): int => $a->languageId <=> $b->languageId);

        return $result;
    }
}
