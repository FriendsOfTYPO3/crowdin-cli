<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Exceptions\ApiValidationException;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\StreamInterface;
use TYPO3\CrowdinCli\Entity\Contributor;

/**
 * Retrieves who contributed to a project in a language, through the "Top Members" report of the Crowdin API.
 *
 * Generating a report is asynchronous: it is requested, polled until finished, then downloaded.
 */
final readonly class ContributorRepository
{
    public const array UNITS = ['strings', 'words', 'chars', 'chars_with_spaces'];
    public const array ORDERS = ['translated', 'approved'];

    private const string WHAT = 'the project reports';

    /**
     * @param int $pollIntervalMicroseconds pause between two checks whether the report is ready
     * @param int $maxPolls number of checks before giving up
     */
    public function __construct(
        private Crowdin $crowdin,
        private int $pollIntervalMicroseconds = 1_000_000,
        private int $maxPolls = 180,
    ) {}

    /**
     * @param string $unit one of self::UNITS
     * @param string $orderBy one of self::ORDERS
     * @return list<Contributor> best first, members without any activity left out
     * @throws \RuntimeException if the report cannot be produced (missing scope or role, unknown language, ...)
     */
    public function findContributors(
        int $projectId,
        string $languageId,
        string $unit = 'strings',
        string $orderBy = 'translated',
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): array {
        $schema = ['unit' => $unit, 'languageId' => $languageId, 'format' => 'json'];
        if ($from !== null) {
            $schema['dateFrom'] = $from->format(\DATE_ATOM);
        }
        if ($to !== null) {
            $schema['dateTo'] = $to->format(\DATE_ATOM);
        }

        try {
            $reports = $this->crowdin->report;
            $report = $reports->generate($projectId, ['name' => 'top-members', 'schema' => $schema]);
            if ($report === null) {
                throw new \RuntimeException('Crowdin returned no report.');
            }

            $this->waitUntilFinished($projectId, $report->getIdentifier(), $report->getStatus());

            $url = $reports->download($projectId, $report->getIdentifier())?->getUrl()
                ?? throw new \RuntimeException('Crowdin returned no download link for the report.');
            // The link is pre-signed, it must not be sent with the access token
            /** @var StreamInterface $stream */
            $stream = $this->crowdin->getClient()->request('GET', $url, []);
            $content = (string)$stream;
        } catch (BadResponseException | ApiException | ApiValidationException $e) {
            throw ApiFailure::describe($e, self::WHAT, $projectId);
        }

        return $this->parse($content, $orderBy);
    }

    private function waitUntilFinished(int $projectId, string $reportId, string $status): void
    {
        for ($poll = 0; $status !== 'finished'; $poll++) {
            if ($status === 'failed') {
                throw new \RuntimeException('Crowdin failed to generate the report.');
            }
            if ($poll >= $this->maxPolls) {
                throw new \RuntimeException('Crowdin takes too long to generate the report, try again later.');
            }

            usleep($this->pollIntervalMicroseconds);
            $status = $this->crowdin->report->get($projectId, $reportId)?->getStatus() ?? 'failed';
        }
    }

    /**
     * @return list<Contributor>
     */
    private function parse(string $content, string $orderBy): array
    {
        try {
            /** @var array{data?: list<array{user: array{username: string, fullName?: string}, translated?: int, approved?: int, voted?: int}>} $report */
            $report = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The report of Crowdin is not valid JSON.', 0, $e);
        }

        $contributors = [];
        foreach ($report['data'] ?? [] as $row) {
            $contributor = new Contributor(
                $row['user']['username'],
                $row['user']['fullName'] ?? '',
                $row['translated'] ?? 0,
                $row['approved'] ?? 0,
                $row['voted'] ?? 0,
            );
            if ($contributor->translated > 0 || $contributor->approved > 0 || $contributor->voted > 0) {
                $contributors[] = $contributor;
            }
        }

        usort($contributors, static function (Contributor $a, Contributor $b) use ($orderBy): int {
            $primary = $orderBy === 'approved' ? $b->approved <=> $a->approved : $b->translated <=> $a->translated;
            $secondary = $orderBy === 'approved' ? $b->translated <=> $a->translated : $b->approved <=> $a->approved;

            return $primary ?: $secondary ?: strcasecmp($a->username, $b->username);
        });

        return $contributors;
    }
}
