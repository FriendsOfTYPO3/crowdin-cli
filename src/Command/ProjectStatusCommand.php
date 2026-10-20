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
use TYPO3\CrowdinCli\Entity\LanguageProgress;
use TYPO3\CrowdinCli\Service\ProjectProgressRepository;

#[AsCommand(name: 'project:status', description: 'Show the translation status of a project')]
final class ProjectStatusCommand extends Command
{
    public function __construct(
        private readonly ProjectProgressRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID')
            ->addOption('language', 'L', InputOption::VALUE_REQUIRED, 'Only show this language, e.g. "fr"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var numeric-string $project */
        $project = $input->getArgument('project');
        /** @var string|null $language */
        $language = $input->getOption('language');
        $projectId = (int)$project;

        try {
            $progress = $this->repository->findProgress($projectId, $language);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($progress === []) {
            $io->warning($language === null ? 'No language found.' : sprintf('No progress found for language "%s".', $language));
            return Command::SUCCESS;
        }

        $rows = array_map(static fn(LanguageProgress $p): array => [
            $p->languageId,
            $p->approved . ' %',
            $p->pending() . ' %',
            $p->translated . ' %',
        ], $progress);

        $io->table(['Language', 'Approved', 'Translated, not approved', 'Translated (total)'], $rows);

        return Command::SUCCESS;
    }
}
