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
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AsCommand(name: 'translations:approve', description: 'Approve translations by ID, or all unapproved translations of a user')]
final class TranslationsApproveCommand extends Command
{
    public function __construct(
        private readonly PendingTranslationRepository $repository,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be approved')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var numeric-string $project */
        $project = $input->getArgument('project');
        /** @var string $language */
        $language = $input->getArgument('language');
        /** @var string|null $user */
        $user = $input->getOption('user');
        /** @var list<numeric-string> $idArguments */
        $idArguments = $input->getArgument('translation-ids');
        $projectId = (int)$project;
        $ids = array_map(static fn(string $id): int => (int)$id, $idArguments);

        if ($user === null && $ids === []) {
            $io->error('Provide translation IDs and/or --user.');
            return Command::INVALID;
        }

        try {
            // Only ever approve translations which are really pending in this language
            $pending = $this->repository->findUnapproved($projectId, $language, $user);
            if ($ids !== []) {
                $pending = array_values(array_filter($pending, static fn($t): bool => in_array($t->translationId, $ids, true)));
                $unknown = array_diff($ids, array_map(static fn($t): int => $t->translationId, $pending));
                foreach ($unknown as $id) {
                    $io->warning(sprintf('Translation %d is not an unapproved translation%s in "%s", skipped.', $id, $user !== null ? ' of that user' : '', $language));
                }
            }

            if ($pending === []) {
                $io->note('Nothing to approve.');
                return Command::SUCCESS;
            }

            $sources = $this->repository->findSourceTexts($projectId, array_map(static fn($t): int => $t->stringId, $pending));
            foreach ($pending as $t) {
                $io->writeln(sprintf('#%d [%s] %s => %s', $t->translationId, $t->authorLabel(), $sources[$t->stringId] ?? '?', $t->text), OutputInterface::VERBOSITY_VERBOSE);
            }

            $io->writeln(sprintf('%d translation(s) in "%s" to approve.', count($pending), $language));
            if ($input->getOption('dry-run')) {
                $io->note('Dry run, nothing approved. Use -v to see the translations.');
                return Command::SUCCESS;
            }
            if (!$input->getOption('yes') && !$io->confirm('Approve them?', false)) {
                return Command::SUCCESS;
            }

            $failed = 0;
            $io->progressStart(count($pending));
            foreach ($pending as $t) {
                try {
                    $this->repository->approve($projectId, $t->translationId);
                } catch (\Throwable $e) {
                    $failed++;
                    $io->warning(sprintf('Translation %d: %s', $t->translationId, $e->getMessage()));
                }
                $io->progressAdvance();
            }
            $io->progressFinish();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($failed > 0) {
            $io->error(sprintf('%d approval(s) failed.', $failed));
            return Command::FAILURE;
        }
        $io->success('Done.');

        return Command::SUCCESS;
    }
}
