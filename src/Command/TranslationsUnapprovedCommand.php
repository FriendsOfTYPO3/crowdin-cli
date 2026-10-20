<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AsCommand(name: 'translations:unapproved', description: 'List translations which have not been approved yet, with their author')]
final class TranslationsUnapprovedCommand extends Command
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
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only show suggestions of this username (or user ID)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of translations to display', '100');
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
        /** @var numeric-string $limitOption */
        $limitOption = $input->getOption('limit');
        $projectId = (int)$project;
        $limit = max(1, (int)$limitOption);

        try {
            $pending = $this->repository->findUnapproved(
                $projectId,
                $language,
                $user
            );
            $all = $pending;
            $total = count($pending);
            $pending = array_slice($pending, 0, $limit);
            $sources = $this->repository->findSourceTexts($projectId, array_map(static fn($t): int => $t->stringId, $pending));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($pending === []) {
            $io->success('No unapproved translations found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($pending as $i => $t) {
            if ($i > 0) {
                $rows[] = new TableSeparator();
            }
            $rows[] = [
                $t->translationId,
                $t->authorLabel(),
                substr($t->createdAt, 0, 10),
                wordwrap($sources[$t->stringId] ?? '?', 50, "\n", true),
                wordwrap($t->text, 50, "\n", true),
            ];
        }
        $io->table(['Translation ID', 'Suggested by', 'Date', 'Source', 'Translation'], $rows);
        $io->writeln(sprintf('Showing %d of %d unapproved translation(s).', count($pending), $total));

        // Summary per author (all unapproved translations) helps to decide on bulk approvals
        $perUser = [];
        foreach ($all as $t) {
            $perUser[$t->authorLabel()] = ($perUser[$t->authorLabel()] ?? 0) + 1;
        }
        arsort($perUser);
        $io->table(['Suggested by', 'Count'], array_map(null, array_keys($perUser), array_values($perUser)));

        return Command::SUCCESS;
    }
}
