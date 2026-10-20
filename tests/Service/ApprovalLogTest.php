<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\ApprovalLog;

final class ApprovalLogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crowdin-cli-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $file = $this->directory . '/approvals.log';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function subject(): ApprovalLog
    {
        return new ApprovalLog($this->directory . '/approvals.log');
    }

    public function testEmptyLog(): void
    {
        self::assertSame([], $this->subject()->entries());
        self::assertNull($this->subject()->lastSessionId());
    }

    public function testRecordCreatesDirectoryAndEntries(): void
    {
        $subject = $this->subject();
        $subject->record('s1', 123, 'de', 11, 901);
        $subject->record('s1', 123, 'de', 12, 902);
        $subject->record('s2', 123, 'fr', 13, 903);

        self::assertCount(3, $subject->entries());
        self::assertSame('s2', $subject->lastSessionId());
        self::assertSame([11, 12], array_column($subject->entriesOfSession('s1'), 'translationId'));
        self::assertSame('fr', $subject->entriesOfSession('s2')[0]['language']);
    }

    public function testForgetRemovesOnlyTheGivenApprovals(): void
    {
        $subject = $this->subject();
        $subject->record('s1', 123, 'de', 11, 901);
        $subject->record('s1', 123, 'de', 12, 902);

        $subject->forget([901]);

        self::assertSame([902], array_column($subject->entries(), 'approvalId'));
    }
}
