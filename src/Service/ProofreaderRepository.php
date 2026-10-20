<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use GuzzleHttp\Exception\BadResponseException;
use TYPO3\CrowdinCli\Entity\ProjectProofreaders;

/**
 * Retrieves the proofreaders of a project through the members endpoint of the Crowdin API.
 */
final readonly class ProofreaderRepository
{
    private const int PAGE_SIZE = 500;

    public function __construct(
        private Crowdin $crowdin,
    ) {}

    /**
     * @param string|null $languageId only this language
     * @throws \RuntimeException if the members cannot be read (missing scope or role, unknown project, ...)
     */
    public function findProofreaders(int $projectId, ?string $languageId = null): ProjectProofreaders
    {
        $byLanguage = [];
        $managers = [];

        foreach ($this->fetchMembers($projectId) as $member) {
            $role = $member['role'] ?? '';
            if ($role === 'blocked' || $role === 'pending') {
                continue;
            }

            $label = $this->label($member);
            if ($role === 'owner' || $role === 'manager') {
                $managers[] = $label;
            }

            foreach ($member['permissions'] ?? [] as $language => $permission) {
                $byLanguage[$language] ??= [];
                if ($permission === 'proofreader') {
                    $byLanguage[$language][] = $label;
                }
            }
        }

        if ($languageId !== null) {
            $byLanguage = array_filter(
                $byLanguage,
                static fn(string $language): bool => strcasecmp($language, $languageId) === 0,
                ARRAY_FILTER_USE_KEY,
            );
        }

        ksort($byLanguage);
        foreach ($byLanguage as &$labels) {
            sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($labels);
        sort($managers, SORT_NATURAL | SORT_FLAG_CASE);

        return new ProjectProofreaders($byLanguage, $managers);
    }

    /**
     * @return \Generator<array{username?: string, fullName?: string|null, role?: string, permissions?: array<string, string>}>
     */
    private function fetchMembers(int $projectId): \Generator
    {
        $offset = 0;
        do {
            try {
                /** @var array{data?: list<array{data: array{username?: string, fullName?: string|null, role?: string, permissions?: array<string, string>}}>}|null $response */
                $response = $this->crowdin->apiRequest('get', sprintf('projects/%d/members', $projectId), null, [
                    'params' => ['limit' => self::PAGE_SIZE, 'offset' => $offset],
                ]);
            } catch (BadResponseException | ApiException $e) {
                throw ApiFailure::describe($e, 'the project members', $projectId);
            }

            $items = $response['data'] ?? [];
            foreach ($items as $item) {
                yield $item['data'];
            }
            $offset += self::PAGE_SIZE;
        } while (count($items) === self::PAGE_SIZE);
    }

    /**
     * @param array{username?: string, fullName?: string|null} $member
     */
    private function label(array $member): string
    {
        $username = $member['username'] ?? '';
        $fullName = $member['fullName'] ?? null;

        return $fullName !== null && $fullName !== '' ? sprintf('%s (%s)', $fullName, $username) : $username;
    }
}
