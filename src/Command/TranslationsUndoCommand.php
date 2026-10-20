<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AsCommand(name: 'translations:undo', description: 'Revoke the approvals made by this tool in a session (default: the last one)')]
final class TranslationsUndoCommand extends Command
{
    public function __construct(
        private readonly PendingTranslationRepository $repository,
        private readonly ApprovalLog $approvalLog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('session', InputArgument::OPTIONAL, 'Session ID, as shown at the end of "interactive" (default: the last session)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the sessions with approvals which can be revoked')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($input->getOption('list')) {
                $sessions = [];
                foreach ($this->approvalLog->entries() as $entry) {
                    $sessions[$entry['session']] ??= ['count' => 0, 'projects' => []];
                    $sessions[$entry['session']]['count']++;
                    $sessions[$entry['session']]['projects'][$entry['project'] . ' ' . $entry['language']] = true;
                }
                if ($sessions === []) {
                    $io->note('No approvals to revoke.');
                    return Command::SUCCESS;
                }
                $io->table(['Session', 'Approvals', 'Project and language'], array_map(
                    static fn(string $id, array $s): array => [$id, $s['count'], implode(', ', array_keys($s['projects']))],
                    array_keys($sessions),
                    array_values($sessions)
                ));
                return Command::SUCCESS;
            }

            /** @var string|null $sessionId */
            $sessionId = $input->getArgument('session') ?? $this->approvalLog->lastSessionId();
            $entries = $sessionId === null ? [] : $this->approvalLog->entriesOfSession($sessionId);
            if ($entries === []) {
                $io->note($sessionId === null ? 'No approvals to revoke.' : sprintf('No approvals found for session "%s" (see --list).', $sessionId));
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('%d approval(s) of session "%s" to revoke.', count($entries), $sessionId));
            if (!$input->getOption('yes') && !$io->confirm('Revoke them?', false)) {
                return Command::SUCCESS;
            }

            $revoked = [];
            foreach ($entries as $entry) {
                try {
                    $this->repository->revokeApproval($entry['project'], $entry['approvalId']);
                    $revoked[] = $entry['approvalId'];
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Translation %d: %s', $entry['translationId'], $e->getMessage()));
                }
            }
            $this->approvalLog->forget($revoked);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (count($revoked) < count($entries)) {
            $io->error(sprintf('%d revocation(s) failed, they stay in the log.', count($entries) - count($revoked)));
            return Command::FAILURE;
        }
        $io->success(sprintf('%d approval(s) revoked.', count($revoked)));

        return Command::SUCCESS;
    }
}
