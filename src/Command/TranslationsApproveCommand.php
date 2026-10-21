<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\CommandOutput;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AsCommand(name: 'translations:approve', description: 'Approve translations by ID, or all unapproved translations of a user and/or of a file path')]
final class TranslationsApproveCommand extends Command
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
            ->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID')
            ->addArgument('language', InputArgument::REQUIRED, 'Language ID, e.g. "de"')
            ->addArgument('translation-ids', InputArgument::IS_ARRAY, 'Translation IDs to approve (see translations:unapproved)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Approve all unapproved translations suggested by this username (or user ID)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Approve all unapproved translations of files whose path starts with this value, e.g. "/14.3/typo3/sysext/backend"')
            ->addOption('drop-rivals', null, InputOption::VALUE_NONE, 'Also permanently delete the other suggestions competing for the same strings (cannot be undone)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be approved')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (required with --format=json, unless --dry-run)');
        CommandOutput::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = new CommandOutput($input, $output);
        $io = $out->io;
        /** @var numeric-string $project */
        $project = $input->getArgument('project');
        /** @var string $language */
        $language = $input->getArgument('language');
        /** @var string|null $user */
        $user = $input->getOption('user');
        /** @var string|null $path */
        $path = $input->getOption('path');
        /** @var list<numeric-string> $idArguments */
        $idArguments = $input->getArgument('translation-ids');
        $projectId = (int)$project;
        $ids = array_map(static fn(string $id): int => (int)$id, $idArguments);
        $dropRivals = (bool)$input->getOption('drop-rivals');

        if ($user === null && $path === null && $ids === []) {
            return $out->fail('Provide translation IDs and/or --user and/or --path.', Command::INVALID);
        }
        if ($out->json && !$input->getOption('yes') && !$input->getOption('dry-run')) {
            return $out->fail('--format=json cannot ask for confirmation: add --yes to approve, or --dry-run.', Command::INVALID);
        }

        try {
            // Only ever approve translations which are really pending in this language
            $listed = $this->repository->findUnapproved($projectId, $language, $user, $path);
            $pending = $listed;
            if ($ids !== []) {
                $pending = array_values(array_filter($listed, static fn($t): bool => in_array($t->translationId, $ids, true)));
                $unknown = array_values(array_diff($ids, array_map(static fn($t): int => $t->translationId, $pending)));
                if ($unknown !== []) {
                    // Crowdin lists a single suggestion per string: an unknown ID may be one of the competing suggestions
                    foreach ($this->repository->findReviews($projectId, $language, $listed) as $review) {
                        foreach ($review->candidates as $candidate) {
                            if (in_array($candidate->translationId, $unknown, true) && ($user === null || $candidate->isSuggestedBy($user))) {
                                $pending[] = $candidate;
                            }
                        }
                    }
                    $unknown = array_diff($unknown, array_map(static fn($t): int => $t->translationId, $pending));
                }
                foreach ($unknown as $id) {
                    $out->warning(sprintf('Translation %d is not an unapproved translation%s in "%s", skipped.', $id, $user !== null || $path !== null ? ' matching the filters' : '', $language));
                }
            }

            // The suggestions which become pointless once the selected one is approved, indexed by the ID of the latter
            $rivalsOf = [];
            if ($dropRivals && $pending !== []) {
                $perString = [];
                foreach ($pending as $t) {
                    $perString[$t->reviewKey()][] = $t;
                }
                foreach ($perString as $selected) {
                    if (count($selected) > 1) {
                        $out->warning(sprintf(
                            'Translations %s compete for the same string: approving one would delete the others. Skipped, approve one of them by ID.',
                            implode(', ', array_map(static fn($t): int => $t->translationId, $selected))
                        ));
                    }
                }
                $pending = array_values(array_filter($pending, static fn($t): bool => count($perString[$t->reviewKey()]) === 1));
                foreach ($this->repository->findReviews($projectId, $language, $pending) as $review) {
                    foreach ($pending as $t) {
                        if ($t->reviewKey() === $review->candidates[0]->reviewKey()) {
                            $rivalsOf[$t->translationId] = $review->rivalsOf($t);
                        }
                    }
                }
            }

            if ($pending === []) {
                $io->note('Nothing to approve.');
                $out->emit($this->report($projectId, $language, (bool)$input->getOption('dry-run'), [], null));
                return Command::SUCCESS;
            }
            $rivalCount = array_sum(array_map(count(...), $rivalsOf));

            /** @var array<int, array{translation_id: int, author: string, text: string, competing: list<int>, status: string, approval_id: int|null, deleted: list<int>, error: string|null}> $entries */
            $entries = [];
            foreach ($pending as $t) {
                $entries[$t->translationId] = [
                    'translation_id' => $t->translationId,
                    'author' => $t->authorLabel(),
                    'text' => $t->text,
                    'competing' => array_map(static fn($r): int => $r->translationId, $rivalsOf[$t->translationId] ?? []),
                    'status' => 'pending',
                    'approval_id' => null,
                    'deleted' => [],
                    'error' => null,
                ];
            }

            // Source texts are fetched one by one, only do it if they are going to be displayed
            if ($io->isVerbose() && !$out->json) {
                $sources = $this->repository->findSourceTexts($projectId, array_map(static fn($t): int => $t->stringId, $pending));
                foreach ($pending as $t) {
                    $io->writeln(sprintf('#%d [%s] %s => %s', $t->translationId, $t->authorLabel(), $sources[$t->stringId] ?? '?', $t->text));
                    foreach ($rivalsOf[$t->translationId] ?? [] as $rival) {
                        $io->writeln(sprintf('    deletes #%d [%s] => %s', $rival->translationId, $rival->authorLabel(), $rival->text));
                    }
                }
            }

            $languageName = $this->repository->findLanguageName($language);
            $io->writeln(sprintf('%d translation(s) in "%s"%s to approve%s.', count($pending), $language, $languageName !== null ? sprintf(' (%s)', $languageName) : '', $rivalCount > 0 ? sprintf(', %d competing suggestion(s) to delete', $rivalCount) : ''));
            if ($input->getOption('dry-run')) {
                $io->note('Dry run, nothing approved. Use -v to see the translations.');
                $out->emit($this->report($projectId, $language, true, array_values($entries), null));
                return Command::SUCCESS;
            }
            if (!$input->getOption('yes') && !$io->confirm($rivalCount > 0 ? sprintf('Approve them and permanently delete the %d competing suggestion(s)? The deletion cannot be undone', $rivalCount) : 'Approve them?', false)) {
                return Command::SUCCESS;
            }

            $failed = 0;
            $deleted = 0;
            $sessionId = ApprovalLog::newSessionId();
            $io->progressStart(count($pending));
            foreach ($pending as $t) {
                try {
                    $approvalId = $this->repository->approve($projectId, $t->translationId);
                    $this->approvalLog->record($sessionId, $projectId, $language, $t->translationId, $approvalId);
                    $entries[$t->translationId]['status'] = 'approved';
                    $entries[$t->translationId]['approval_id'] = $approvalId;
                } catch (\Throwable $e) {
                    $failed++;
                    $entries[$t->translationId]['status'] = 'failed';
                    $entries[$t->translationId]['error'] = $e->getMessage();
                    $out->warning(sprintf('Translation %d: %s', $t->translationId, $e->getMessage()));
                    // Never delete the alternatives of something which could not be approved
                    $io->progressAdvance();
                    continue;
                }
                $deletedRivals = [];
                foreach ($rivalsOf[$t->translationId] ?? [] as $rival) {
                    try {
                        $this->repository->delete($projectId, $rival->translationId);
                        $deleted++;
                        $deletedRivals[] = $rival->translationId;
                    } catch (\Throwable $e) {
                        $failed++;
                        $out->warning(sprintf('Competing translation %d cannot be deleted: %s', $rival->translationId, $e->getMessage()));
                    }
                }
                $entries[$t->translationId]['deleted'] = $deletedRivals;
                $io->progressAdvance();
            }
            $io->progressFinish();
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        $out->emit($this->report($projectId, $language, false, array_values($entries), $sessionId));
        if ($failed > 0) {
            $io->error(sprintf('%d approval(s) or deletion(s) failed.', $failed));
            return Command::FAILURE;
        }
        $io->success(sprintf('Done%s. Use "translations:undo" to revoke these approvals.', $deleted > 0 ? sprintf(', %d competing suggestion(s) deleted', $deleted) : ''));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $translations
     * @return array<string, mixed>
     */
    private function report(int $projectId, string $language, bool $dryRun, array $translations, ?string $session): array
    {
        return [
            'project' => $projectId,
            'language' => $language,
            'dry_run' => $dryRun,
            'session' => $session,
            'translations' => $translations,
        ];
    }
}
