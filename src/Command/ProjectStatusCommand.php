<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use CrowdinApiClient\Crowdin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'project:status', description: 'Show the translation status of a project')]
final class ProjectStatusCommand extends Command
{
    public function __construct(
        private readonly Crowdin $crowdin,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var numeric-string $project */
        $project = $input->getArgument('project');
        $projectId = (int)$project;

        try {
            $progress = $this->crowdin->translationStatus->getProjectProgress($projectId, ['limit' => 500]);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($progress === null) {
            $io->error('Crowdin returned no progress information.');
            return Command::FAILURE;
        }

        $rows = [];
        /** @var \CrowdinApiClient\Model\Progress $item */
        foreach ($progress as $item) {
            $translated = $item->getTranslationProgress();
            $approved = $item->getApprovalProgress();
            $rows[] = [
                $item->getLanguageId(),
                $approved . ' %',
                max(0, $translated - $approved) . ' %',
                $translated . ' %',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $io->table(['Language', 'Approved', 'Translated, not approved', 'Translated (total)'], $rows);

        return Command::SUCCESS;
    }
}
