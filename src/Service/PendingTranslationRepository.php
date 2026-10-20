<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use TYPO3\CrowdinCli\Entity\PendingTranslation;

/**
 * Retrieves and approves translations through the Crowdin API.
 */
final readonly class PendingTranslationRepository
{
    private const int LIMIT = 500;

    /** Number of still needed strings from which loading a whole file is cheaper than one request per string */
    private const int BULK_THRESHOLD = 20;

    public function __construct(
        private Crowdin $crowdin,
    ) {}

    /**
     * Translations of the given language which have no approval yet.
     *
     * @param string|null $pathPrefix only translations of files whose path in Crowdin starts with this value
     * @return list<PendingTranslation>
     */
    public function findUnapproved(int $projectId, string $languageId, ?string $user = null, ?string $pathPrefix = null): array
    {
        $pending = [];
        if ($pathPrefix === null) {
            $translations = [];
            foreach ($this->listLanguageTranslations($projectId, $languageId, ['croql' => 'count of approvals = 0']) as $item) {
                array_push($translations, ...$this->toPending($item));
            }
        } else {
            // Crowdin does not accept "croql" together with "fileId": list the translations of every matching file
            // and drop the approved ones
            $translations = [];
            foreach ($this->findFileIdsByPathPrefix($projectId, $pathPrefix) as $fileId) {
                $approved = [];
                foreach ($this->listLanguageTranslations($projectId, $languageId, ['fileId' => $fileId, 'approvedOnly' => 1]) as $item) {
                    foreach ($this->toPending($item) as $translation) {
                        $approved[$translation->translationId] = true;
                    }
                }
                foreach ($this->listLanguageTranslations($projectId, $languageId, ['fileId' => $fileId]) as $item) {
                    foreach ($this->toPending($item) as $translation) {
                        if (!isset($approved[$translation->translationId])) {
                            $translations[] = $translation;
                        }
                    }
                }
            }
        }

        foreach ($translations as $translation) {
            if ($user === null || $translation->isSuggestedBy($user)) {
                $pending[] = $translation;
            }
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<LanguageTranslation>
     */
    private function listLanguageTranslations(int $projectId, string $languageId, array $params): array
    {
        $result = [];
        $offset = 0;
        do {
            $items = $this->crowdin->stringTranslation->listLanguageTranslations($projectId, $languageId, $params + [
                'limit' => self::LIMIT,
                'offset' => $offset,
            ]);
            $count = 0;
            /** @var LanguageTranslation $item */
            foreach ($items as $item) {
                $count++;
                $result[] = $item;
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        return $result;
    }

    /**
     * @return list<int> IDs of the files whose path starts with the given prefix
     */
    private function findFileIdsByPathPrefix(int $projectId, string $pathPrefix): array
    {
        $pathPrefix = '/' . ltrim($pathPrefix, '/');
        $fileIds = [];
        $offset = 0;
        do {
            $files = $this->crowdin->file->list($projectId, ['limit' => self::LIMIT, 'offset' => $offset]);
            $count = 0;
            /** @var File $file */
            foreach ($files as $file) {
                $count++;
                if (str_starts_with($file->getPath(), $pathPrefix)) {
                    $fileIds[] = $file->getId();
                }
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        return $fileIds;
    }

    /**
     * @param list<int> $stringIds
     * @return array<int, string> source texts indexed by string ID
     */
    public function findSourceTexts(int $projectId, array $stringIds): array
    {
        return array_map(static fn(array $source): string => $source['text'], $this->findSources($projectId, $stringIds));
    }

    /**
     * @param list<int> $stringIds
     * @return array<int, array{text: string, fileId: int}> indexed by string ID
     */
    public function findSources(int $projectId, array $stringIds): array
    {
        // The strings list endpoint cannot filter by IDs, only by file. Look up one string to learn its file and, if many
        // other strings are still needed, load the strings of that whole file page by page instead of one by one.
        $sources = [];
        $remaining = array_flip(array_unique($stringIds));
        while ($remaining !== []) {
            $stringId = array_key_first($remaining);
            $string = $this->crowdin->sourceString->get($projectId, $stringId);
            $sources[$stringId] = $this->toSource($string);
            unset($remaining[$stringId]);

            $fileId = $string?->getFileId();
            if ($fileId !== null && count($remaining) >= self::BULK_THRESHOLD) {
                $this->collectFileSources($projectId, $fileId, $remaining, $sources);
            }
        }

        return $sources;
    }

    /**
     * @param array<int, mixed> $remaining string IDs which are still needed, as keys. Resolved ones are removed
     * @param array<int, array{text: string, fileId: int}> $sources
     */
    private function collectFileSources(int $projectId, int $fileId, array &$remaining, array &$sources): void
    {
        $offset = 0;
        do {
            $strings = $this->crowdin->sourceString->list($projectId, ['fileId' => $fileId, 'limit' => self::LIMIT, 'offset' => $offset]);
            $count = 0;
            /** @var SourceString $string */
            foreach ($strings as $string) {
                $count++;
                if (isset($remaining[$string->getId()])) {
                    $sources[$string->getId()] = $this->toSource($string);
                    unset($remaining[$string->getId()]);
                }
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT && $remaining !== []);
    }

    /**
     * @return array{text: string, fileId: int}
     */
    private function toSource(?SourceString $string): array
    {
        /** @var string|list<string>|null $text */
        $text = $string?->getText();

        return [
            'text' => is_array($text) ? implode(' | ', $text) : (string)$text,
            'fileId' => $string?->getFileId() ?? 0,
        ];
    }

    /**
     * @param list<int> $fileIds
     * @return array<int, string> file paths in Crowdin indexed by file ID
     */
    public function findFilePaths(int $projectId, array $fileIds): array
    {
        $paths = [];
        foreach (array_unique($fileIds) as $fileId) {
            $paths[$fileId] = $this->crowdin->file->get($projectId, $fileId)?->getPath() ?? '(unknown file)';
        }

        return $paths;
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
