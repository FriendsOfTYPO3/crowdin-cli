<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use CrowdinApiClient\Api\ReportApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Http\Client\CrowdinHttpClientInterface;
use CrowdinApiClient\Model\DownloadFile;
use CrowdinApiClient\Model\Report;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use TYPO3\CrowdinCli\Service\ContributorRepository;

#[AllowMockObjectsWithoutExpectations]
final class ContributorRepositoryTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>}> */
    private array $requests = [];

    private const string URL = 'https://crowdin-tmp.example.com/report.json?signature=abc';

    private function report(string $status): Report
    {
        return new Report(['identifier' => 'abc-123', 'status' => $status, 'progress' => 0, 'attributes' => []]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function reportJson(array $rows): string
    {
        return json_encode(['data' => $rows], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $username, string $fullName, int $translated, int $approved, int $voted = 0): array
    {
        return [
            'user' => ['id' => '1', 'username' => $username, 'fullName' => $fullName],
            'translated' => $translated,
            'approved' => $approved,
            'voted' => $voted,
        ];
    }

    private function subject(ReportApi $reports, string $content = '{"data": []}', int $maxPolls = 3): ContributorRepository
    {
        $http = $this->createStub(CrowdinHttpClientInterface::class);
        $http->method('request')->willReturnCallback(function (string $method, string $uri, array $options) use ($content): StreamInterface {
            $this->requests[] = [$method, $uri, $options];

            return Utils::streamFor($content);
        });
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['report', $reports]]);
        $crowdin->method('getClient')->willReturn($http);

        return new ContributorRepository($crowdin, 0, $maxPolls);
    }

    private function reportsApi(string $generatedStatus = 'finished'): ReportApi&MockObject
    {
        $api = $this->createMock(ReportApi::class);
        $api->method('generate')->willReturn($this->report($generatedStatus));
        $api->method('download')->willReturn(new DownloadFile(['url' => self::URL, 'expireIn' => '1 hour']));

        return $api;
    }

    public function testContributorsAreRankedByTranslatedThenApproved(): void
    {
        $content = $this->reportJson([
            $this->row('b', 'Bea', 10, 5),
            $this->row('a', 'Al', 10, 7),
            $this->row('idle', 'Idle', 0, 0),
            $this->row('c', 'Cy', 30, 0, 4),
        ]);
        $api = $this->reportsApi();
        $api->expects(self::once())->method('generate')->with(123, [
            'name' => 'top-members',
            'schema' => ['unit' => 'strings', 'languageId' => 'fr', 'format' => 'json'],
        ])->willReturn($this->report('finished'));

        $result = $this->subject($api, $content)->findContributors(123, 'fr');

        self::assertSame(['c', 'a', 'b'], array_column($result, 'username'));
        self::assertSame(4, $result[0]->voted);
        // The pre-signed link is fetched as is, without the access token
        self::assertSame([['GET', self::URL, []]], $this->requests);
    }

    public function testRankingByApproved(): void
    {
        $content = $this->reportJson([$this->row('a', 'Al', 30, 1), $this->row('b', 'Bea', 10, 5)]);

        $result = $this->subject($this->reportsApi(), $content)->findContributors(123, 'fr', orderBy: 'approved');

        self::assertSame(['b', 'a'], array_column($result, 'username'));
    }

    public function testPeriodAndUnitAreSentToCrowdin(): void
    {
        $api = $this->createMock(ReportApi::class);
        $api->expects(self::once())->method('generate')->with(123, [
            'name' => 'top-members',
            'schema' => [
                'unit' => 'words',
                'languageId' => 'de',
                'format' => 'json',
                'dateFrom' => '2026-01-01T00:00:00+00:00',
                'dateTo' => '2026-06-30T23:59:59+00:00',
            ],
        ])->willReturn($this->report('finished'));
        $api->method('download')->willReturn(new DownloadFile(['url' => self::URL, 'expireIn' => '1 hour']));

        $this->subject($api)->findContributors(
            123,
            'de',
            'words',
            from: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            to: new \DateTimeImmutable('2026-06-30T23:59:59+00:00'),
        );
    }

    public function testWaitsUntilTheReportIsFinished(): void
    {
        $api = $this->reportsApi('created');
        $api->expects(self::exactly(2))->method('get')->with(123, 'abc-123')
            ->willReturnOnConsecutiveCalls($this->report('inProgress'), $this->report('finished'));

        $this->subject($api)->findContributors(123, 'fr');
    }

    public function testGivesUpWhenTheReportNeverFinishes(): void
    {
        $api = $this->reportsApi('created');
        $api->method('get')->willReturn($this->report('inProgress'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('takes too long');

        $this->subject($api, maxPolls: 2)->findContributors(123, 'fr');
    }

    public function testFailedReportIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to generate');

        $this->subject($this->reportsApi('failed'))->findContributors(123, 'fr');
    }

    public function testMissingScopeIsReportedReadably(): void
    {
        $api = $this->createMock(ReportApi::class);
        $api->method('generate')->willThrowException(new ApiException('Forbidden', 403));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('denied access to the project reports (403)');

        $this->subject($api)->findContributors(123, 'fr');
    }

    public function testInvalidReportContentIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->subject($this->reportsApi(), '<html>')->findContributors(123, 'fr');
    }
}
