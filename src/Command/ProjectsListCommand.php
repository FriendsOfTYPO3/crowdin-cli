<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationWriter;
use FriendsOfTYPO3\CrowdinBase\Configuration\Entity\Language;
use FriendsOfTYPO3\CrowdinBase\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projects:list', description: 'List all available Crowdin projects')]
final class ProjectsListCommand extends Command
{
    public function __construct(
        private readonly ConfigurationReader $reader,
        private readonly ConfigurationWriter $writer,
        private readonly ProjectRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Fetch the projects from Crowdin and update the local configuration first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($input->getOption('refresh')) {
                $this->writer->write($this->repository->findAll());
            }
            $projects = $this->reader->read();
        } catch (\Throwable $e) {
            $io->error($e->getMessage() . ' (try "projects:list --refresh")');
            return Command::FAILURE;
        }

        $io->table(
            ['ID', 'Identifier', 'Name', 'Languages'],
            array_map(
                static fn($p): array => [
                    $p->id,
                    $p->identifier,
                    $p->extensionKey,
                    count($p->languages),
                ],
                $projects
            )
        );
        if ($output->isVerbose()) {
            foreach ($projects as $p) {
                $io->writeln(sprintf('<info>%s</info>: %s', $p->identifier, implode(', ', array_map(static fn(Language $l): string => $l->id, $p->languages))));
            }
        }

        return Command::SUCCESS;
    }
}
