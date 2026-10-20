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
use TYPO3\CrowdinCli\Service\ProofreaderRepository;

#[AsCommand(name: 'project:proofreaders', description: 'List the proofreaders of a project, per language')]
final class ProjectProofreadersCommand extends Command
{
    public function __construct(
        private readonly ProofreaderRepository $repository,
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

        try {
            $proofreaders = $this->repository->findProofreaders((int)$project, $language);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($proofreaders->byLanguage === []) {
            $io->warning($language === null ? 'No language found.' : sprintf('No language "%s" found.', $language));
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($proofreaders->byLanguage as $languageId => $labels) {
            $rows[] = [$languageId, $labels === [] ? '-' : implode("\n", $labels)];
        }
        $io->table(['Language', 'Proofreaders'], $rows);

        if ($proofreaders->managers !== []) {
            $io->writeln('Owner and managers, who can proofread every language: ' . implode(', ', $proofreaders->managers));
        }

        return Command::SUCCESS;
    }
}
