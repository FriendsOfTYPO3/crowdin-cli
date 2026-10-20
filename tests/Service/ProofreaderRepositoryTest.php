<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\ProofreaderRepository;

#[AllowMockObjectsWithoutExpectations]
final class ProofreaderRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $permissions
     * @return array{data: array<string, mixed>}
     */
    private function member(string $username, ?string $fullName, string $role, array $permissions): array
    {
        return ['data' => ['username' => $username, 'fullName' => $fullName, 'role' => $role, 'permissions' => $permissions]];
    }

    private function subject(): ProofreaderRepository
    {
        $response = ['data' => [
            $this->member('jdoe', 'Jane Doe', 'translator', ['fr' => 'proofreader', 'de' => 'translator']),
            $this->member('alice', null, 'translator', ['fr' => 'proofreader', 'de' => 'proofreader']),
            $this->member('boss', 'The Boss', 'manager', []),
            $this->member('banned', 'Banned', 'blocked', ['fr' => 'proofreader']),
            $this->member('nobody', 'No Body', 'translator', ['fr' => 'translator', 'it' => 'translator']),
        ]];
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->expects(self::once())->method('apiRequest')
            ->with('get', 'projects/123/members', null, ['params' => ['limit' => 500, 'offset' => 0]])
            ->willReturn($response);

        return new ProofreaderRepository($crowdin);
    }

    public function testProofreadersAreGroupedByLanguage(): void
    {
        $result = $this->subject()->findProofreaders(123);

        self::assertSame(['de', 'fr', 'it'], array_keys($result->byLanguage));
        self::assertSame(['alice'], $result->byLanguage['de']);
        self::assertSame(['alice', 'Jane Doe (jdoe)'], $result->byLanguage['fr']);
        self::assertSame([], $result->byLanguage['it']);
        self::assertSame(['The Boss (boss)'], $result->managers);
    }

    public function testLanguageFilterIgnoresCase(): void
    {
        $result = $this->subject()->findProofreaders(123, 'FR');

        self::assertSame(['fr'], array_keys($result->byLanguage));
    }

    public function testMembersAreFetchedPageByPage(): void
    {
        $page = array_fill(0, 500, $this->member('x', null, 'translator', ['fr' => 'translator']));
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->expects(self::exactly(2))->method('apiRequest')->willReturnOnConsecutiveCalls(
            ['data' => $page],
            ['data' => [$this->member('last', null, 'translator', ['fr' => 'proofreader'])]],
        );

        $result = (new ProofreaderRepository($crowdin))->findProofreaders(123);

        self::assertSame(['last'], $result->byLanguage['fr']);
    }

    #[DataProvider('failures')]
    public function testHttpErrorsBecomeReadableExceptions(int $status, string $expected): void
    {
        $request = new Request('GET', 'https://api.crowdin.com/api/v2/projects/123/members');
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('apiRequest')->willThrowException(
            new ClientException('boom', $request, new Response($status)),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expected);

        (new ProofreaderRepository($crowdin))->findProofreaders(123);
    }

    #[DataProvider('failures')]
    public function testApiErrorsBecomeReadableExceptions(int $status, string $expected): void
    {
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('apiRequest')->willThrowException(new ApiException('Forbidden', $status));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expected);

        (new ProofreaderRepository($crowdin))->findProofreaders(123);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failures(): iterable
    {
        yield 'unauthorized' => [401, 'rejected the access token'];
        yield 'missing scope' => [403, 'needs a scope'];
        yield 'unknown project' => [404, 'Not found (404): project 123'];
        yield 'rate limit' => [429, 'rate limit'];
        yield 'other' => [418, 'HTTP 418'];
    }
}
