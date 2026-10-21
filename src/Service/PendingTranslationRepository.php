<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use TYPO3\CrowdinCli\Entity\PendingTranslation;
use TYPO3\CrowdinCli\Entity\StringReview;

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
     * Human-readable name of a language, e.g. "Catalan" for "ca", or null if it cannot be determined.
     */
    public function findLanguageName(string $languageId): ?string
    {
        try {
            return $this->crowdin->language->get($languageId)?->getName();
        } catch (\Throwable) {
            return null;
        }
    }

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

        // Crowdin may return the same translation more than once (it did, across pages): keep the first one
        foreach ($translations as $translation) {
            if (isset($pending[$translation->translationId])) {
                continue;
            }
            if ($user === null || $translation->isSuggestedBy($user)) {
                $pending[$translation->translationId] = $translation;
            }
        }

        return array_values($pending);
    }

    /**
     * Gathers, for the given pending translations, the suggestions competing for the same string (or plural form)
     * and the translation which is already approved for it, if any.
     *
     * Crowdin lists only one translation per string when asked for a whole language or file, hence this costs two
     * requests per string: only do it for what is displayed or reviewed.
     *
     * @param list<PendingTranslation> $pending
     * @return list<StringReview> one per string (and plural form), in the order of the given translations
     */
    public function findReviews(int $projectId, string $languageId, array $pending): array
    {
        $reviews = [];
        $loaded = [];
        foreach ($pending as $translation) {
            $key = $translation->reviewKey();
            if (isset($reviews[$key])) {
                continue;
            }
            [$all, $approvedIds] = $loaded[$translation->stringId] ??= $this->loadStringTranslations($projectId, $languageId, $translation->stringId);

            [$candidates, $approved] = $this->split($all, $approvedIds, $translation->pluralForm);
            // The given translation is pending by definition, whatever Crowdin lists
            $candidates[$translation->translationId] ??= $translation;

            $reviews[$key] = new StringReview($translation->stringId, $translation->pluralForm, $this->oldestFirst($candidates), $approved);
        }

        return array_values($reviews);
    }

    /**
     * Strings which already have an approved translation in the given language and still carry unapproved suggestions.
     *
     * Crowdin lists only one translation per string when asked for a whole language or file, and that is the approved
     * one: those suggestions are invisible to findUnapproved(). They are found through the strings instead, which
     * costs a few requests only. Use findHiddenReviews() to learn what the suggestions are.
     *
     * @param string|null $user only strings with a suggestion of this username (the CroQL filter needs a username, a user ID does not narrow the result)
     * @param string|null $pathPrefix only strings of files whose path in Crowdin starts with this value
     * @return array<int, int> file ID indexed by string ID, ordered by file and string
     */
    public function findStringsWithHiddenSuggestions(int $projectId, string $languageId, ?string $user = null, ?string $pathPrefix = null): array
    {
        $language = '@language:"' . self::croqlLiteral($languageId) . '"';
        // A numeric value may be a user ID: it cannot narrow the query, findHiddenReviews() filters by it afterwards
        $suggestedBy = $user !== null && !ctype_digit($user) && preg_match('/^[\w.@-]+$/', $user) === 1 ? sprintf(' and user = @user:"%s"', $user) : '';
        $croql = sprintf(
            'count of languages summary where (language = %1$s and is approved) > 0 and count of translations where (count of approvals = 0 and language = %1$s%2$s) > 0',
            $language,
            $suggestedBy
        );
        $fileIds = $pathPrefix === null ? null : array_flip($this->findFileIdsByPathPrefix($projectId, $pathPrefix));

        $strings = [];
        $offset = 0;
        do {
            $items = $this->crowdin->sourceString->list($projectId, ['croql' => $croql, 'limit' => self::LIMIT, 'offset' => $offset]);
            $count = 0;
            /** @var SourceString $string */
            foreach ($items as $string) {
                $count++;
                if ($fileIds === null || isset($fileIds[$string->getFileId()])) {
                    $strings[$string->getId()] = $string->getFileId();
                }
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        // The order of Crowdin is not stable between pages: sort to get always the same list
        uksort($strings, static fn(int $a, int $b): int => [$strings[$a], $a] <=> [$strings[$b], $b]);

        return $strings;
    }

    /**
     * The suggestions of the given strings which compete with an approved translation, see findStringsWithHiddenSuggestions().
     * Costs two requests per string: only do it for what is displayed or reviewed.
     *
     * @param list<int> $stringIds
     * @param string|null $user only reviews with a suggestion of this username (or user ID)
     * @return list<StringReview> all of them improve an approved translation, in the order of the given strings
     */
    public function findHiddenReviews(int $projectId, string $languageId, array $stringIds, ?string $user = null): array
    {
        $reviews = [];
        foreach ($stringIds as $stringId) {
            [$all, $approvedIds] = $this->loadStringTranslations($projectId, $languageId, $stringId);
            $pluralForms = [];
            foreach ($all as $t) {
                $pluralForms[$t->pluralForm ?? ''] = $t->pluralForm;
            }
            foreach ($pluralForms as $pluralForm) {
                [$candidates, $approved] = $this->split($all, $approvedIds, $pluralForm);
                if ($approved === null || $candidates === []) {
                    continue;
                }
                if ($user !== null && !array_any($candidates, static fn(PendingTranslation $t): bool => $t->isSuggestedBy($user))) {
                    continue;
                }
                $reviews[] = new StringReview($stringId, $pluralForm, $this->oldestFirst($candidates), $approved);
            }
        }

        return $reviews;
    }

    /**
     * @param list<PendingTranslation> $all
     * @param array<int, true> $approvedIds
     * @return array{array<int, PendingTranslation>, PendingTranslation|null} the unapproved translations indexed by ID, and the approved one, of a plural form
     */
    private function split(array $all, array $approvedIds, ?string $pluralForm): array
    {
        $candidates = [];
        $approved = null;
        foreach ($all as $other) {
            if ($other->pluralForm !== $pluralForm) {
                continue;
            }
            if (isset($approvedIds[$other->translationId])) {
                $approved = $other;
            } else {
                $candidates[$other->translationId] = $other;
            }
        }

        return [$candidates, $approved];
    }

    /**
     * @param non-empty-array<int, PendingTranslation> $candidates
     * @return non-empty-list<PendingTranslation>
     */
    private function oldestFirst(array $candidates): array
    {
        uasort($candidates, static fn(PendingTranslation $a, PendingTranslation $b): int => [$a->createdAt, $a->translationId] <=> [$b->createdAt, $b->translationId]);

        return array_values($candidates);
    }

    private static function croqlLiteral(string $value): string
    {
        return addcslashes($value, '"\\');
    }

    /**
     * @return array{list<PendingTranslation>, array<int, true>} all translations of the string, and the IDs of the approved ones
     */
    private function loadStringTranslations(int $projectId, string $languageId, int $stringId): array
    {
        $translations = [];
        foreach ($this->crowdin->stringTranslation->list($projectId, ['stringId' => $stringId, 'languageId' => $languageId, 'limit' => self::LIMIT]) as $item) {
            /** @var StringTranslation $item */
            /** @var array{id?: int, username?: string, fullName?: string} $itemUser */
            $itemUser = $item->getUser();
            $translations[] = $this->build($item->getId(), $stringId, $item->getText(), $itemUser, $item->getCreatedAt(), $item->getPluralCategoryName() ?: null);
        }

        $approvedIds = [];
        foreach ($this->crowdin->stringTranslation->listApprovals($projectId, ['stringId' => $stringId, 'languageId' => $languageId, 'limit' => self::LIMIT]) as $approval) {
            /** @var StringTranslationApproval $approval */
            $approvedIds[$approval->getTranslationId()] = true;
        }

        return [$translations, $approvedIds];
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
     * @return array<int, array{text: string, fileId: int, identifier: string}> indexed by string ID
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
     * @param array<int, array{text: string, fileId: int, identifier: string}> $sources
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
     * @return array{text: string, fileId: int, identifier: string}
     */
    private function toSource(?SourceString $string): array
    {
        /** @var string|list<string>|null $text */
        $text = $string?->getText();

        return [
            'text' => is_array($text) ? implode(' | ', $text) : (string)$text,
            'fileId' => $string?->getFileId() ?? 0,
            'identifier' => (string)$string?->getIdentifier(),
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

    /**
     * @return int ID of the created approval (needed to revoke it)
     */
    public function approve(int $projectId, int $translationId): int
    {
        $approval = $this->crowdin->stringTranslation->createApproval($projectId, ['translationId' => $translationId]);
        if ($approval === null) {
            throw new \RuntimeException('Crowdin returned no approval.');
        }

        return $approval->getId();
    }

    /**
     * Permanently deletes a translation (suggestion). This cannot be undone.
     */
    public function delete(int $projectId, int $translationId): void
    {
        $this->crowdin->stringTranslation->delete($projectId, $translationId);
    }

    public function revokeApproval(int $projectId, int $approvalId): void
    {
        $this->crowdin->stringTranslation->deleteApproval($projectId, $approvalId);
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
