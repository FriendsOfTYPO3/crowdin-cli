<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CrowdinCli\Service\CommandOutput;
use TYPO3\CrowdinCli\Service\ContributorRepository;

#[AsCommand(name: 'project:contributors', description: 'Rank the contributors of a project in one language')]
final class ProjectContributorsCommand extends Command
{
    public function __construct(
        private readonly ContributorRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID')
            ->addArgument('language', InputArgument::REQUIRED, 'Crowdin language ID, e.g. "fr"')
            ->addOption('by', 'b', InputOption::VALUE_REQUIRED, 'Rank by "translated" or "approved"', 'translated')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Count "strings", "words", "chars" or "chars_with_spaces"', 'strings')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only count contributions as of this date (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Only count contributions until this date (YYYY-MM-DD)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of contributors displayed, 0 for all', '20');
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
        /** @var string $by */
        $by = $input->getOption('by');
        /** @var string $unit */
        $unit = $input->getOption('unit');
        /** @var string|null $fromOption */
        $fromOption = $input->getOption('from');
        /** @var string|null $toOption */
        $toOption = $input->getOption('to');
        /** @var numeric-string $limitOption */
        $limitOption = $input->getOption('limit');
        $limit = (int)$limitOption;

        if (!in_array($by, ContributorRepository::ORDERS, true)) {
            return $out->fail(sprintf('Invalid value for --by, use one of: %s.', implode(', ', ContributorRepository::ORDERS)));
        }
        if (!in_array($unit, ContributorRepository::UNITS, true)) {
            return $out->fail(sprintf('Invalid value for --unit, use one of: %s.', implode(', ', ContributorRepository::UNITS)));
        }
        if (!is_numeric($limitOption) || $limit < 0) {
            return $out->fail('Invalid value for --limit, use a number (0 for all).');
        }
        $from = $this->parseDate($fromOption, '00:00:00');
        $to = $this->parseDate($toOption, '23:59:59');
        if ($from === false || $to === false) {
            return $out->fail('Invalid date, use the format YYYY-MM-DD.');
        }

        $io->writeln('Asking Crowdin for the report, this can take a few seconds...');
        try {
            $contributors = $this->repository->findContributors((int)$project, $language, $unit, $by, $from, $to);
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        if ($contributors === [] && !$out->json) {
            $io->warning(sprintf('No contribution found for language "%s".', $language));
            return Command::SUCCESS;
        }

        $shown = $limit > 0 ? array_slice($contributors, 0, $limit) : $contributors;
        $rank = 0;
        $previous = null;
        $rows = [];
        $entries = [];
        foreach ($shown as $position => $contributor) {
            $key = $by === 'approved' ? [$contributor->approved, $contributor->translated] : [$contributor->translated, $contributor->approved];
            // Contributors with the same figures share their rank
            if ($key !== $previous) {
                $rank = $position + 1;
                $previous = $key;
            }
            $rows[] = [$rank, $contributor->label(), $contributor->translated, $contributor->approved, $contributor->voted];
            $entries[] = [
                'rank' => $rank,
                'username' => $contributor->username,
                'name' => $contributor->fullName,
                'translated' => $contributor->translated,
                'approved' => $contributor->approved,
                'votes' => $contributor->voted,
            ];
        }

        if ($out->json) {
            $out->emit([
                'project' => (int)$project,
                'language' => $language,
                'unit' => $unit,
                'ranked_by' => $by,
                'from' => $fromOption,
                'to' => $toOption,
                'total' => count($contributors),
                'contributors' => $entries,
            ]);
            return Command::SUCCESS;
        }

        $io->table(['Rank', 'Contributor', 'Translated', 'Approved', 'Votes'], $rows);
        $io->writeln(sprintf(
            'Unit: %s. Ranked by %s%s.%s',
            $unit,
            $by,
            $this->period($fromOption, $toOption),
            count($shown) < count($contributors) ? sprintf(' Showing %d of %d contributors, use --limit to see more.', count($shown), count($contributors)) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return \DateTimeImmutable|false|null null if no date was given, false if it is invalid
     */
    private function parseDate(?string $date, string $time): \DateTimeImmutable|false|null
    {
        if ($date === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, new \DateTimeZone('UTC'));

        // PHP rolls impossible dates over (2026-13-45), which must not pass silently
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $date . ' ' . $time ? $parsed : false;
    }

    private function period(?string $from, ?string $to): string
    {
        return match (true) {
            $from !== null && $to !== null => sprintf(', from %s to %s', $from, $to),
            $from !== null => sprintf(', as of %s', $from),
            $to !== null => sprintf(', until %s', $to),
            default => '',
        };
    }
}
