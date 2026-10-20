<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Exceptions\ApiValidationException;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Turns the errors of the Crowdin API into messages telling the user what to do about them.
 */
final class ApiFailure
{
    /**
     * @param string $what what was being accessed, e.g. "the project members"
     */
    public static function describe(BadResponseException|ApiException|ApiValidationException $e, string $what, int $projectId): \RuntimeException
    {
        if ($e instanceof ApiValidationException) {
            return new \RuntimeException(
                sprintf('Crowdin rejected the request for %s: %s', $what, json_encode($e->getErrors(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                0,
                $e,
            );
        }

        $status = $e instanceof BadResponseException ? $e->getResponse()->getStatusCode() : $e->getCode();
        $apiMessage = $e instanceof ApiException ? $e->getMessage() : null;

        return new \RuntimeException(match ($status) {
            401 => 'Crowdin rejected the access token (401). Check CROWDIN_ACCESS_TOKEN.',
            403 => sprintf(
                'Crowdin denied access to %s (403). The token needs a scope that allows it (see the README), '
                . 'and its owner must be a manager of the project.',
                $what,
            ),
            404 => sprintf('Not found (404): project %d or the requested language does not exist, or this token cannot see %s.', $projectId, $what),
            429 => 'Crowdin rate limit reached (429), try again in a moment.',
            default => sprintf('Crowdin could not provide %s (HTTP %d%s).', $what, $status, $apiMessage !== null ? ': ' . $apiMessage : ''),
        }, 0, $e);
    }
}
