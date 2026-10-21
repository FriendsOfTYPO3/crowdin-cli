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
use TYPO3\CrowdinCli\Entity\DuplicateSuggestion;
use TYPO3\CrowdinCli\Service\CellText;
use TYPO3\CrowdinCli\Service\CommandOutput;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;

#[AsCommand(name: 'translations:dedupe', description: 'Delete suggestions which repeat the approved translation or an older suggestion of the same string')]
final class TranslationsDedupeCommand extends Command
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
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Only clean up files whose path starts with this value, e.g. "/14.3/typo3/sysext/backend"')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be deleted')
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
        /** @var string|null $path */
        $path = $input->getOption('path');
        $dryRun = (bool)$input->getOption('dry-run');
        $projectId = (int)$project;

        if ($out->json && !$input->getOption('yes') && !$dryRun) {
            return $out->fail('--format=json cannot ask for confirmation: add --yes to delete, or --dry-run.', Command::INVALID);
        }

        try {
            $pending = $this->repository->findUnapproved($projectId, $language, null, $path);
            $pendingStrings = array_map(static fn($t): int => $t->stringId, $pending);
            // Crowdin does not list the suggestions of a string which has an approved translation: look for them separately
            $hidden = array_keys(array_diff_key($this->repository->findStringsWithHiddenSuggestions($projectId, $language, null, $path), array_flip($pendingStrings)));

            $io->writeln(sprintf('Looking up the suggestions of %d string(s)...', count(array_unique($pendingStrings)) + count($hidden)));
            $reviews = array_merge(
                $this->repository->findReviews($projectId, $language, $pending),
                $this->repository->findHiddenReviews($projectId, $language, $hidden)
            );

            /** @var list<array{DuplicateSuggestion, int}> $found duplicate and ID of its string */
            $found = [];
            foreach ($reviews as $review) {
                foreach ($review->duplicates() as $duplicate) {
                    $found[] = [$duplicate, $review->stringId];
                }
            }

            $sources = $this->repository->findSources($projectId, array_values(array_unique(array_column($found, 1))));
            $paths = $this->repository->findFilePaths($projectId, array_values(array_unique(array_column($sources, 'fileId'))));
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        $pathOf = static fn(int $stringId): string => $paths[$sources[$stringId]['fileId'] ?? 0] ?? '(unknown file)';
        usort($found, static fn(array $a, array $b): int => [$pathOf($a[1]), $a[1], $a[0]->suggestion->translationId] <=> [$pathOf($b[1]), $b[1], $b[0]->suggestion->translationId]);

        /** @var array<int, array{string_id: int, file: string, source: string|null, translation_id: int, author: string, text: string, reason: string, duplicate_of: int, status: string, error: string|null}> $entries */
        $entries = [];
        foreach ($found as [$duplicate, $stringId]) {
            $t = $duplicate->suggestion;
            $entries[$t->translationId] = [
                'string_id' => $stringId,
                'file' => $pathOf($stringId),
                'source' => $sources[$stringId]['text'] ?? null,
                'translation_id' => $t->translationId,
                'author' => $t->authorLabel(),
                'text' => $t->text,
                'reason' => $duplicate->reason(),
                'duplicate_of' => $duplicate->original->translationId,
                'status' => 'pending',
                'error' => null,
            ];
        }

        if ($entries === []) {
            $io->success('No duplicate suggestions found.');
            $out->emit($this->report($projectId, $language, $dryRun, []));
            return Command::SUCCESS;
        }

        if (!$out->json) {
            $this->showTable($io, $found, $pathOf, $sources);
        }
        $languageName = $this->repository->findLanguageName($language);
        $io->writeln(sprintf('%d duplicate suggestion(s) in "%s"%s to delete.', count($entries), $language, $languageName !== null ? sprintf(' (%s)', $languageName) : ''));
        if ($dryRun) {
            $io->note('Dry run, nothing deleted.');
            $out->emit($this->report($projectId, $language, true, array_values($entries)));
            return Command::SUCCESS;
        }
        if (!$input->getOption('yes') && !$io->confirm('Permanently delete them? This cannot be undone', false)) {
            return Command::SUCCESS;
        }

        $failed = 0;
        $io->progressStart(count($entries));
        foreach ($entries as $id => $entry) {
            try {
                $this->repository->delete($projectId, $id);
                $entries[$id]['status'] = 'deleted';
            } catch (\Throwable $e) {
                $failed++;
                $entries[$id]['status'] = 'failed';
                $entries[$id]['error'] = $e->getMessage();
                $out->warning(sprintf('Translation %d cannot be deleted: %s', $id, $e->getMessage()));
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        $out->emit($this->report($projectId, $language, false, array_values($entries)));
        if ($failed > 0) {
            $io->error(sprintf('%d deletion(s) failed.', $failed));
            return Command::FAILURE;
        }
        $io->success(sprintf('%d duplicate suggestion(s) deleted.', count($entries)));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{DuplicateSuggestion, int}> $found
     * @param array<int, array{text: string, fileId: int, identifier: string}> $sources
     */
    private function showTable(SymfonyStyle $io, array $found, \Closure $pathOf, array $sources): void
    {
        $byFile = [];
        foreach ($found as $item) {
            $byFile[$pathOf($item[1])][] = $item;
        }
        foreach ($byFile as $filePath => $items) {
            $io->section(sprintf('%s (%d)', $filePath, count($items)));
            $rows = [];
            $previous = null;
            foreach ($items as [$duplicate, $stringId]) {
                if ($previous !== null && $previous !== $stringId) {
                    $rows[] = new TableSeparator();
                }
                $t = $duplicate->suggestion;
                $rows[] = [
                    $t->translationId,
                    $t->authorLabel(),
                    substr($t->createdAt, 0, 10),
                    $previous === $stringId ? '(same string)' : CellText::wrap($sources[$stringId]['text'] ?? '?'),
                    CellText::wrap($t->text),
                    $duplicate->reason(),
                ];
                $previous = $stringId;
            }
            $io->table(['Translation ID', 'Suggested by', 'Date', 'Source', 'Suggestion to delete', 'Why'], $rows);
        }
    }

    /**
     * @param list<array<string, mixed>> $duplicates
     * @return array<string, mixed>
     */
    private function report(int $projectId, string $language, bool $dryRun, array $duplicates): array
    {
        return [
            'project' => $projectId,
            'language' => $language,
            'dry_run' => $dryRun,
            'duplicates' => $duplicates,
        ];
    }
}
