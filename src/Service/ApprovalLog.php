<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

/**
 * Remembers the approvals made by this tool (one JSON object per line), so that they can be revoked.
 *
 * Every run of the tool that approves something is a "session".
 */
final readonly class ApprovalLog
{
    public function __construct(
        private string $file,
    ) {}

    public static function newSessionId(): string
    {
        return date('Ymd-His');
    }

    public function record(string $sessionId, int $projectId, string $language, int $translationId, int $approvalId): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }
        $line = json_encode([
            'session' => $sessionId,
            'project' => $projectId,
            'language' => $language,
            'translationId' => $translationId,
            'approvalId' => $approvalId,
            'approvedAt' => date('c'),
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Cannot write to "%s".', $this->file));
        }
    }

    /**
     * @return list<array{session: string, project: int, language: string, translationId: int, approvalId: int, approvedAt: string}>
     */
    public function entries(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $entries = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{session: string, project: int, language: string, translationId: int, approvalId: int, approvedAt: string}|null $entry */
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function lastSessionId(): ?string
    {
        $entries = $this->entries();

        return $entries === [] ? null : $entries[array_key_last($entries)]['session'];
    }

    /**
     * @return list<array{session: string, project: int, language: string, translationId: int, approvalId: int, approvedAt: string}>
     */
    public function entriesOfSession(string $sessionId): array
    {
        return array_values(array_filter($this->entries(), static fn(array $e): bool => $e['session'] === $sessionId));
    }

    /**
     * Forget the given approvals (after they have been revoked).
     *
     * @param list<int> $approvalIds
     */
    public function forget(array $approvalIds): void
    {
        $remaining = array_filter($this->entries(), static fn(array $e): bool => !in_array($e['approvalId'], $approvalIds, true));
        $content = '';
        foreach ($remaining as $entry) {
            $content .= json_encode($entry, JSON_THROW_ON_ERROR) . "\n";
        }
        if (is_file($this->file) && file_put_contents($this->file, $content, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Cannot write to "%s".', $this->file));
        }
    }
}
