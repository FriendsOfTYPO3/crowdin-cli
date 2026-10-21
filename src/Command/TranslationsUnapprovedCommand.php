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
use TYPO3\CrowdinCli\Entity\PendingTranslation;
use TYPO3\CrowdinCli\Entity\StringReview;
use TYPO3\CrowdinCli\Service\CellText;
use TYPO3\CrowdinCli\Service\CommandOutput;
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
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Only show translations of files whose path starts with this value, e.g. "/14.3/typo3/sysext/backend"')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of strings to display', '100');
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
        /** @var numeric-string $limitOption */
        $limitOption = $input->getOption('limit');
        $projectId = (int)$project;
        $limit = max(1, (int)$limitOption);

        try {
            $pending = $this->repository->findUnapproved(
                $projectId,
                $language,
                $user,
                $path
            );
            $all = $pending;
            // The limit applies to what is displayed, in the order it is displayed (by file): resolve every source first,
            // or a file would be cut at an arbitrary place, in the order of Crowdin
            $sources = $this->repository->findSources($projectId, array_map(static fn($t): int => $t->stringId, $pending));
            $paths = $this->repository->findFilePaths($projectId, array_values(array_unique(array_column($sources, 'fileId'))));
            $pathOf = static function (PendingTranslation $t) use (&$paths, &$sources): string {
                return $paths[$sources[$t->stringId]['fileId'] ?? 0] ?? '(unknown file)';
            };
            usort($pending, static fn(PendingTranslation $a, PendingTranslation $b): int => [$pathOf($a), $a->translationId] <=> [$pathOf($b), $b->translationId]);

            // Several suggestions may compete for one string, which is then displayed once
            $strings = [];
            foreach ($pending as $t) {
                $strings[$t->reviewKey()] ??= $t;
            }
            $total = count($strings);
            $reviews = $this->repository->findReviews($projectId, $language, array_slice(array_values($strings), 0, $limit));

            // Crowdin does not list the suggestions of a string which has an approved translation: look for them
            // separately. They come last, as they only try to improve what is approved
            $hidden = array_diff_key($this->repository->findStringsWithHiddenSuggestions($projectId, $language, $user, $path), $sources);
            $total += count($hidden);
            $room = $limit - count($reviews);
            $shownHidden = $room > 0 ? array_slice(array_keys($hidden), 0, $room) : [];
            if ($shownHidden !== []) {
                $reviews = array_merge($reviews, $this->repository->findHiddenReviews($projectId, $language, $shownHidden, $user));
                $sources += $this->repository->findSources($projectId, $shownHidden);
                $paths += $this->repository->findFilePaths($projectId, array_values(array_diff(array_unique(array_column($sources, 'fileId')), array_keys($paths))));
            }
            $notShownHidden = count($hidden) - count($shownHidden);
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        if ($reviews === [] && !$out->json) {
            $io->success('No unapproved translations found.');
            return Command::SUCCESS;
        }

        // Group by file, ordered by path. Strings without translation first, then the ones which only improve an approved translation
        $byFile = [];
        foreach ($reviews as $review) {
            $byFile[$pathOf($review->candidates[0])][] = $review;
        }
        ksort($byFile);

        // Summary per author (all unapproved translations) helps to decide on bulk approvals
        $perUser = [];
        foreach ($all as $t) {
            $perUser[$t->authorLabel()] = ($perUser[$t->authorLabel()] ?? 0) + 1;
        }
        arsort($perUser);

        if ($out->json) {
            $files = [];
            foreach ($byFile as $filePath => $fileReviews) {
                usort($fileReviews, static fn(StringReview $a, StringReview $b): int => $a->improvesApproved() <=> $b->improvesApproved());
                $files[] = [
                    'path' => (string)$filePath,
                    'strings' => array_map(static fn(StringReview $review): array => [
                        'string_id' => $review->stringId,
                        'plural_form' => $review->pluralForm,
                        'source' => $sources[$review->stringId]['text'] ?? null,
                        'contested' => $review->isContested(),
                        'improves_approved' => $review->improvesApproved(),
                        'approved_translation' => $review->approved?->text,
                        'suggestions' => array_map(static fn(PendingTranslation $t): array => [
                            'translation_id' => $t->translationId,
                            'author' => $t->authorLabel(),
                            'username' => $t->username,
                            'created_at' => $t->createdAt,
                            'text' => $t->text,
                        ], $review->candidates),
                    ], $fileReviews),
                ];
            }
            $out->emit([
                'project' => $projectId,
                'language' => $language,
                'total' => $total,
                'shown' => count($reviews),
                'not_shown_behind_approved' => $notShownHidden,
                'files' => $files,
                'authors' => array_map(
                    static fn(string $author, int $count): array => ['author' => $author, 'count' => $count],
                    array_keys($perUser),
                    array_values($perUser)
                ),
            ]);
            return Command::SUCCESS;
        }

        foreach ($byFile as $filePath => $fileReviews) {
            usort($fileReviews, static fn(StringReview $a, StringReview $b): int => $a->improvesApproved() <=> $b->improvesApproved());
            $showCurrent = array_any($fileReviews, static fn(StringReview $r): bool => $r->improvesApproved());
            $io->section(sprintf('%s (%d)', $filePath, count($fileReviews)));
            $rows = [];
            foreach ($fileReviews as $i => $review) {
                if ($i > 0) {
                    $rows[] = new TableSeparator();
                }
                $source = CellText::wrap($sources[$review->stringId]['text'] ?? '?');
                foreach ($review->candidates as $j => $t) {
                    if ($j === 0) {
                        $sourceCell = $review->isContested() ? sprintf("%d SUGGESTIONS FOR THIS STRING\n%s", count($review->candidates), $source) : $source;
                    } else {
                        $sourceCell = '(same string)';
                    }
                    $row = [$t->translationId, $t->authorLabel(), substr($t->createdAt, 0, 10), $sourceCell];
                    if ($showCurrent) {
                        $row[] = $j === 0 ? CellText::wrap($review->approved->text ?? '') : '';
                    }
                    $row[] = CellText::wrap($t->text);
                    $rows[] = $row;
                }
            }
            $headers = ['Translation ID', 'Suggested by', 'Date', 'Source'];
            if ($showCurrent) {
                $headers[] = 'Current translation (approved)';
            }
            $headers[] = 'Suggestion';
            $io->table($headers, $rows);
        }
        $io->writeln(sprintf('Showing %d of %d string(s) with unapproved translations.', count($reviews), $total));
        $contested = count(array_filter($reviews, static fn(StringReview $r): bool => $r->isContested()));
        if ($contested > 0) {
            $io->writeln(sprintf('%d string(s) have competing suggestions: approving one makes the others pointless (see "translations:approve --drop-rivals").', $contested));
        }
        $improvements = count(array_filter($reviews, static fn(StringReview $r): bool => $r->improvesApproved()));
        if ($improvements > 0) {
            $io->writeln(sprintf('%d string(s) already have an approved translation: their suggestions only try to improve it, so they are less urgent.', $improvements));
        }

        if ($notShownHidden > 0) {
            $io->writeln(sprintf('%d more string(s) have suggestions behind an approved translation: raise --limit to see them.', $notShownHidden));
        }

        if ($perUser === []) {
            return Command::SUCCESS;
        }
        $io->table(['Suggested by', 'Count'], array_map(null, array_keys($perUser), array_values($perUser)));

        return Command::SUCCESS;
    }
}
