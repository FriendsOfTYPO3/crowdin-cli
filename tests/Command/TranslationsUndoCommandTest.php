<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Command;

use CrowdinApiClient\Api\StringTranslationApi;
use CrowdinApiClient\Crowdin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CrowdinCli\Command\TranslationsUndoCommand;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AllowMockObjectsWithoutExpectations]
final class TranslationsUndoCommandTest extends TestCase
{
    private string $file;
    private ApprovalLog $log;
    private StringTranslationApi&MockObject $stringTranslation;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'crowdin-cli-test') ?: '';
        $this->log = new ApprovalLog($this->file);
        $this->log->record('s1', 123, 'de', 11, 901);
        $this->log->record('s2', 123, 'fr', 12, 902);
        $this->log->record('s2', 123, 'fr', 13, 903);

        $this->stringTranslation = $this->createMock(StringTranslationApi::class);
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['stringTranslation', $this->stringTranslation]]);
        $this->tester = new CommandTester(new TranslationsUndoCommand(new PendingTranslationRepository($crowdin), $this->log));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testRevokesLastSessionByDefault(): void
    {
        $this->stringTranslation->expects(self::exactly(2))->method('deleteApproval');

        $status = $this->tester->execute(['--yes' => true]);

        self::assertSame(0, $status);
        self::assertSame([901], array_column($this->log->entries(), 'approvalId'));
    }

    public function testRevokesGivenSession(): void
    {
        $this->stringTranslation->expects(self::once())->method('deleteApproval')->with(123, 901);

        $this->tester->execute(['session' => 's1', '--yes' => true]);

        self::assertSame([902, 903], array_column($this->log->entries(), 'approvalId'));
    }

    public function testFailedRevocationStaysInLog(): void
    {
        $this->stringTranslation->method('deleteApproval')->willReturnCallback(static function (int $projectId, int $approvalId): void {
            if ($approvalId === 902) {
                throw new \RuntimeException('Not allowed');
            }
        });

        $status = $this->tester->execute(['--yes' => true]);

        self::assertSame(1, $status);
        self::assertSame([901, 902], array_column($this->log->entries(), 'approvalId'));
    }

    public function testConfirmationCanBeDeclined(): void
    {
        $this->stringTranslation->expects(self::never())->method('deleteApproval');

        $this->tester->setInputs(['no']);
        $this->tester->execute([]);

        self::assertCount(3, $this->log->entries());
    }

    public function testJsonFormatRevokesWithoutAsking(): void
    {
        $this->stringTranslation->expects(self::exactly(2))->method('deleteApproval');

        $status = $this->tester->execute(['--yes' => true, '--format' => 'json']);

        self::assertSame(0, $status);
        /** @var array{session: string, revoked: list<int>, failed: list<array<string, mixed>>} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('s2', $result['session']);
        self::assertSame([902, 903], $result['revoked']);
        self::assertSame([], $result['failed']);
    }

    public function testJsonFormatReportsFailedRevocations(): void
    {
        $this->stringTranslation->method('deleteApproval')->willReturnCallback(static function (int $projectId, int $approvalId): void {
            if ($approvalId === 902) {
                throw new \RuntimeException('Not allowed');
            }
        });

        $status = $this->tester->execute(['--yes' => true, '--format' => 'json']);

        self::assertSame(1, $status);
        /** @var array{revoked: list<int>, failed: list<array<string, mixed>>} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([903], $result['revoked']);
        self::assertSame('Not allowed', $result['failed'][0]['error']);
    }

    public function testJsonFormatRefusesToAskForConfirmation(): void
    {
        $this->stringTranslation->expects(self::never())->method('deleteApproval');

        $status = $this->tester->execute(['--format' => 'json']);

        self::assertSame(2, $status);
        self::assertCount(3, $this->log->entries());
        /** @var array{error: array{message: string}} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('--yes', $result['error']['message']);
    }

    public function testJsonFormatList(): void
    {
        $this->tester->execute(['--list' => true, '--format' => 'json']);

        /** @var array{sessions: list<array<string, mixed>>} $result */
        $result = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [['session' => 's1', 'approvals' => 1, 'projects' => ['123 de']], ['session' => 's2', 'approvals' => 2, 'projects' => ['123 fr']]],
            $result['sessions']
        );
    }

    public function testList(): void
    {
        $this->tester->execute(['--list' => true]);

        self::assertStringContainsString('s2', $this->tester->getDisplay());
        self::assertStringContainsString('123 fr', $this->tester->getDisplay());
    }
}
