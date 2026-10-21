<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CrowdinCli\Entity\LanguageProgress;
use TYPO3\CrowdinCli\Service\CommandOutput;
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
        CommandOutput::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = new CommandOutput($input, $output);
        $io = $out->io;
        /** @var numeric-string $project */
        $project = $input->getArgument('project');
        /** @var string|null $language */
        $language = $input->getOption('language');
        $projectId = (int)$project;

        try {
            $progress = $this->repository->findProgress($projectId, $language);
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        if ($out->json) {
            $out->emit([
                'project' => $projectId,
                'languages' => array_map(static fn(LanguageProgress $p): array => [
                    'language' => $p->languageId,
                    'approved' => $p->approved,
                    'pending' => $p->pending(),
                    'translated' => $p->translated,
                ], array_values($progress)),
            ]);
            return Command::SUCCESS;
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
