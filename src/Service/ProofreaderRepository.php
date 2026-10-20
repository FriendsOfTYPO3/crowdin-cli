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
            } catch (BadResponseException $e) {
                throw new \RuntimeException($this->describeFailure($e->getResponse()->getStatusCode(), $projectId), 0, $e);
            } catch (ApiException $e) {
                throw new \RuntimeException($this->describeFailure($e->getCode(), $projectId, $e->getMessage()), 0, $e);
            }

            $items = $response['data'] ?? [];
            foreach ($items as $item) {
                yield $item['data'];
            }
            $offset += self::PAGE_SIZE;
        } while (count($items) === self::PAGE_SIZE);
    }

    private function describeFailure(int $status, int $projectId, ?string $apiMessage = null): string
    {
        return match ($status) {
            401 => 'Crowdin rejected the access token (401). Check CROWDIN_ACCESS_TOKEN.',
            403 => 'Crowdin denied access to the project members (403). The token needs a scope that allows reading project members '
                . '(see the README), and its owner must be a manager of the project.',
            404 => sprintf('Project %d not found, or its members are not visible to this token (404).', $projectId),
            429 => 'Crowdin rate limit reached (429), try again in a moment.',
            default => sprintf('Crowdin could not list the members (HTTP %d%s).', $status, $apiMessage !== null ? ': ' . $apiMessage : ''),
        };
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
