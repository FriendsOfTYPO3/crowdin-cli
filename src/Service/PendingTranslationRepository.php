<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\LanguageTranslation;
use TYPO3\CrowdinCli\Entity\PendingTranslation;

/**
 * Retrieves and approves translations through the Crowdin API.
 */
final readonly class PendingTranslationRepository
{
    private const int LIMIT = 500;

    public function __construct(
        private Crowdin $crowdin,
    ) {}

    /**
     * Translations of the given language which have no approval yet.
     *
     * @return list<PendingTranslation>
     */
    public function findUnapproved(int $projectId, string $languageId, ?string $user = null): array
    {
        $pending = [];
        $offset = 0;
        do {
            $items = $this->crowdin->stringTranslation->listLanguageTranslations($projectId, $languageId, [
                'croql' => 'count of approvals = 0',
                'limit' => self::LIMIT,
                'offset' => $offset,
            ]);
            $count = 0;
            /** @var LanguageTranslation $item */
            foreach ($items as $item) {
                $count++;
                foreach ($this->toPending($item) as $translation) {
                    if ($user === null || $translation->isSuggestedBy($user)) {
                        $pending[] = $translation;
                    }
                }
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        return $pending;
    }

    /**
     * @param list<int> $stringIds
     * @return array<int, string> source texts indexed by string ID
     */
    public function findSourceTexts(int $projectId, array $stringIds): array
    {
        // The strings list endpoint cannot filter by IDs, so fetch them one by one
        $texts = [];
        foreach (array_unique($stringIds) as $stringId) {
            /** @var string|list<string>|null $text */
            $text = $this->crowdin->sourceString->get($projectId, $stringId)?->getText();
            $texts[$stringId] = is_array($text) ? implode(' | ', $text) : (string)$text;
        }

        return $texts;
    }

    public function approve(int $projectId, int $translationId): void
    {
        $this->crowdin->stringTranslation->createApproval($projectId, ['translationId' => $translationId]);
    }

    /**
     * @return list<PendingTranslation>
     */
    private function toPending(LanguageTranslation $item): array
    {
        /** @var array{id?: int, username?: string, fullName?: string}|null $itemUser */
        $itemUser = $item->getUser();
        /** @var array<array-key, array{translationId?: int, text?: string, pluralForm?: string, createdAt?: string, user?: array{id?: int, username?: string, fullName?: string}}>|null $plurals */
        $plurals = $item->getPlurals();
        if ($plurals === null || $plurals === []) {
            $translationId = $item->getTranslationId();
            if ($translationId === null) {
                return [];
            }
            return [$this->build($translationId, $item->getStringId(), (string)$item->getText(), $itemUser, $item->getCreatedAt())];
        }

        $result = [];
        foreach ($plurals as $form => $plural) {
            if (!isset($plural['translationId'])) {
                continue;
            }
            $result[] = $this->build(
                $plural['translationId'],
                $item->getStringId(),
                $plural['text'] ?? '',
                $plural['user'] ?? $itemUser,
                $plural['createdAt'] ?? $item->getCreatedAt(),
                $plural['pluralForm'] ?? (string)$form,
            );
        }

        return $result;
    }

    /**
     * @param array{id?: int, username?: string, fullName?: string}|null $user
     */
    private function build(int $translationId, int $stringId, string $text, ?array $user, string $createdAt, ?string $pluralForm = null): PendingTranslation
    {
        return new PendingTranslation(
            $translationId,
            $stringId,
            $text,
            $user['id'] ?? null,
            $user['username'] ?? null,
            $user['fullName'] ?? null,
            $createdAt,
            $pluralForm,
        );
    }
}
