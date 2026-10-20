<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CrowdinCli\Entity\LanguageProgress;
use TYPO3\CrowdinCli\Entity\PendingTranslation;
use TYPO3\CrowdinCli\Service\ApprovalLog;
use TYPO3\CrowdinCli\Service\CellText;
use TYPO3\CrowdinCli\Service\PendingTranslationRepository;
use TYPO3\CrowdinCli\Service\ProjectProgressRepository;
use TYPO3\CrowdinCli\Service\ProjectResolver;
use TYPO3\CrowdinCli\Service\ReviewInput;

#[AsCommand(
    name: 'interactive',
    description: 'Guided review: pick a project, a language and a suggester, then approve translations chunk by chunk',
    aliases: ['review'],
)]
final class InteractiveCommand extends Command
{
    private const string QUIT = 'q';
    private const string BACK = 'b';
    private const string ALL = 'a';

    private string $sessionId = '';

    /** @var array<int, true> IDs of the translations approved during this run */
    private array $approvedIds = [];

    /** @var array<int, true> IDs of the translations dropped (deleted) during this run */
    private array $droppedIds = [];

    private bool $dryRun = false;

    public function __construct(
        private readonly ProjectResolver $projectResolver,
        private readonly ProjectProgressRepository $progressRepository,
        private readonly PendingTranslationRepository $repository,
        private readonly ApprovalLog $approvalLog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', 'P', InputOption::VALUE_REQUIRED, 'Crowdin project ID (or identifier), skips the project question')
            ->addOption('language', 'L', InputOption::VALUE_REQUIRED, 'Language ID, e.g. "fr": only work on this language')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only review suggestions of this username (or user ID), skips the suggester question')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Only review translations of files whose path starts with this value')
            ->addOption('page-size', 's', InputOption::VALUE_REQUIRED, 'Number of translations shown (and approved) at a time', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Walk through everything but do not approve anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('This command asks questions and cannot run with --no-interaction. Use translations:unapproved and translations:approve instead.');
            return Command::INVALID;
        }

        /** @var string|null $projectOption */
        $projectOption = $input->getOption('project');
        /** @var string|null $language */
        $language = $input->getOption('language');
        /** @var string|null $user */
        $user = $input->getOption('user');
        /** @var string|null $path */
        $path = $input->getOption('path');
        /** @var numeric-string $pageSizeOption */
        $pageSizeOption = $input->getOption('page-size');
        $pageSize = max(1, (int)$pageSizeOption);

        $this->sessionId = ApprovalLog::newSessionId();
        $this->approvedIds = [];
        $this->droppedIds = [];
        $this->dryRun = (bool)$input->getOption('dry-run');
        if ($this->dryRun) {
            $io->note('Dry run: nothing will be approved.');
        }

        try {
            $projectId = $this->selectProject($io, $output, $projectOption);
            if ($projectId !== null) {
                $this->workOnProject($io, $projectId, $language, $user, $path, $pageSize);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($this->approvedIds !== [] && !$this->dryRun) {
            $io->success(sprintf(
                '%d translation(s) approved in this session (%s). Revoke them with: ./crowdin-cli translations:undo %s',
                count($this->approvedIds),
                $this->sessionId,
                $this->sessionId
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return int|null null if the user quits
     */
    private function selectProject(SymfonyStyle $io, OutputInterface $output, ?string $option): ?int
    {
        if ($option !== null) {
            return $this->projectResolver->resolve($option)->id;
        }

        $this->getApplication()?->find('projects:list')->run(new ArrayInput([]), $output);
        while (true) {
            /** @var string|null $input */
            $input = $io->ask('Project ID (or "q" to quit)');
            $answer = trim((string)$input);
            if (strtolower($answer) === self::QUIT) {
                return null;
            }
            try {
                return $this->projectResolver->resolve($answer)->id;
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
            }
        }
    }

    /**
     * @param positive-int $pageSize
     */
    private function workOnProject(SymfonyStyle $io, int $projectId, ?string $languageOption, ?string $user, ?string $path, int $pageSize): void
    {
        while (true) {
            $progress = $this->progressRepository->findProgress($projectId, $languageOption);
            if ($progress === []) {
                $io->warning($languageOption === null ? 'No language found.' : sprintf('No progress found for language "%s".', $languageOption));
                return;
            }
            $io->table(
                ['Language', 'Approved', 'Translated, not approved', 'Translated (total)'],
                array_map(static fn(LanguageProgress $p): array => [$p->languageId, $p->approved . ' %', $p->pending() . ' %', $p->translated . ' %'], $progress)
            );

            // Percentages are rounded: a language showing 0 % may still hold a few pending translations
            $candidates = array_values(array_filter($progress, static fn(LanguageProgress $p): bool => $p->pending() > 0));
            if ($candidates === []) {
                $io->success('No translation waits for approval (the percentages are rounded, use translations:unapproved to be sure).');
                return;
            }

            if ($languageOption !== null) {
                $language = $candidates[0]->languageId;
            } else {
                $io->table(
                    ['#', 'Language', 'Translated, not approved'],
                    array_map(static fn(LanguageProgress $p, int $i): array => [$i + 1, $p->languageId, $p->pending() . ' %'], $candidates, array_keys($candidates))
                );
                $language = $this->pick($io, 'Language (number or ID, "q" to quit)', array_map(static fn(LanguageProgress $p): string => $p->languageId, $candidates), [self::QUIT]);
                if ($language === self::QUIT) {
                    return;
                }
            }

            $continue = $this->reviewLanguage($io, $projectId, $language, $user, $path, $pageSize);
            if (!$continue || $languageOption !== null) {
                return;
            }
        }
    }

    /**
     * @param positive-int $pageSize
     * @return bool false if the user quits
     */
    private function reviewLanguage(SymfonyStyle $io, int $projectId, string $language, ?string $user, ?string $path, int $pageSize): bool
    {
        $io->writeln(sprintf('Loading the unapproved translations in "%s"...', $language));
        $pending = $this->repository->findUnapproved($projectId, $language, $user, $path);
        if ($pending === []) {
            $io->note('No unapproved translations found.');
            return true;
        }

        while (true) {
            $remaining = array_values(array_filter($pending, fn(PendingTranslation $t): bool => !isset($this->approvedIds[$t->translationId]) && !isset($this->droppedIds[$t->translationId])));
            if ($remaining === []) {
                $io->success(sprintf('Nothing left to review in "%s".', $language));
                return true;
            }

            if ($user !== null) {
                $selection = $remaining;
                $label = $user;
            } else {
                $perUser = [];
                foreach ($remaining as $t) {
                    $perUser[$t->authorLabel()] = ($perUser[$t->authorLabel()] ?? 0) + 1;
                }
                arsort($perUser);
                $authors = array_keys($perUser);
                $io->table(['#', 'Suggested by', 'Count'], array_map(static fn(string $author, int $i): array => [$i + 1, $author, $perUser[$author]], $authors, array_keys($authors)));
                $answer = $this->pick($io, sprintf('Suggester (number, "%s" for everybody, "%s" to go back, "%s" to quit)', self::ALL, self::BACK, self::QUIT), $authors, [self::ALL, self::BACK, self::QUIT], false);
                if ($answer === self::QUIT) {
                    return false;
                }
                if ($answer === self::BACK) {
                    return true;
                }
                $label = $answer === self::ALL ? null : $answer;
                $selection = $label === null ? $remaining : array_values(array_filter($remaining, static fn(PendingTranslation $t): bool => $t->authorLabel() === $label));
            }

            $this->printEquivalentCommand($io, $projectId, $language, $label, $path);
            if (!$this->reviewSelection($io, $projectId, $language, $selection, $pageSize)) {
                return false;
            }
            if ($user !== null) {
                return true;
            }
        }
    }

    private function printEquivalentCommand(SymfonyStyle $io, int $projectId, string $language, ?string $suggester, ?string $path): void
    {
        $username = $suggester !== null && preg_match('/\(([^()]+)\)$/', $suggester, $m) === 1 ? $m[1] : $suggester;
        $io->writeln(sprintf(
            '<comment>Same list without questions:</comment> ./crowdin-cli translations:unapproved %d %s%s%s',
            $projectId,
            escapeshellarg($language),
            $username !== null && $username !== '(unknown)' ? ' --user=' . escapeshellarg($username) : '',
            $path !== null ? ' --path=' . escapeshellarg($path) : ''
        ));
    }

    /**
     * @param list<PendingTranslation> $selection
     * @param positive-int $pageSize
     * @return bool false if the user quits
     */
    private function reviewSelection(SymfonyStyle $io, int $projectId, string $language, array $selection, int $pageSize): bool
    {
        $io->writeln(sprintf('Loading %d source text(s)...', count($selection)));
        $sources = $this->repository->findSources($projectId, array_map(static fn(PendingTranslation $t): int => $t->stringId, $selection));
        $paths = $this->repository->findFilePaths($projectId, array_values(array_unique(array_column($sources, 'fileId'))));
        $pathOf = static fn(PendingTranslation $t): string => $paths[$sources[$t->stringId]['fileId'] ?? 0] ?? '(unknown file)';

        usort($selection, static fn(PendingTranslation $a, PendingTranslation $b): int => [$pathOf($a), $a->translationId] <=> [$pathOf($b), $b->translationId]);
        $chunks = array_chunk($selection, $pageSize);

        foreach ($chunks as $index => $chunk) {
            $io->section(sprintf('Chunk %d of %d (%d translation(s))', $index + 1, count($chunks), count($chunk)));
            $this->renderChunk($io, $chunk, $sources, $pathOf);

            while (true) {
                /** @var string $answer */
                $answer = $io->ask('[a] approve all  [e 3,7] all except  [o 3,7] only  [d 3,7] drop  [s] skip  [v] details  [q] quit', 's');
                $review = ReviewInput::parse($answer, count($chunk));
                if ($review === null) {
                    $io->error('Not understood. Examples: "a", "e 3,7", "o 1-4", "d 2", "s", "v", "q".');
                    continue;
                }
                if ($review->action === ReviewInput::QUIT) {
                    return false;
                }
                if ($review->action === ReviewInput::DETAILS) {
                    foreach ($chunk as $i => $t) {
                        $io->writeln(sprintf('%d: translation #%d (string #%d) by %s on %s%s', $i + 1, $t->translationId, $t->stringId, $t->authorLabel(), substr($t->createdAt, 0, 10), $t->pluralForm !== null ? ', plural form ' . $t->pluralForm : ''));
                    }
                    continue;
                }
                if ($review->action === ReviewInput::APPROVE) {
                    if ($review->positions === []) {
                        $io->note('Nothing selected.');
                        continue;
                    }
                    $this->approve($io, $projectId, $language, $chunk, $review->positions);
                }
                if ($review->action === ReviewInput::DROP) {
                    $question = sprintf('Permanently delete %d suggestion(s) (position(s) %s)? This cannot be undone', count($review->positions), implode(', ', $review->positions));
                    if (!$io->confirm($question, false)) {
                        continue;
                    }
                    $this->drop($io, $projectId, $chunk, $review->positions);
                }
                break;
            }
        }

        return true;
    }

    /**
     * @param list<PendingTranslation> $chunk
     * @param array<int, array{text: string, fileId: int}> $sources
     * @param \Closure(PendingTranslation): string $pathOf
     */
    private function renderChunk(SymfonyStyle $io, array $chunk, array $sources, \Closure $pathOf): void
    {
        $currentPath = null;
        $rows = [];
        foreach ($chunk as $i => $t) {
            $path = $pathOf($t);
            if ($path !== $currentPath) {
                if ($rows !== []) {
                    $io->table(['#', 'Suggested by', 'Source', 'Translation'], $rows);
                    $rows = [];
                }
                $io->writeln(sprintf('<info>%s</info>', $path));
                $currentPath = $path;
            }
            if ($rows !== []) {
                $rows[] = new TableSeparator();
            }
            $rows[] = [
                $i + 1,
                $t->authorLabel(),
                CellText::wrap($sources[$t->stringId]['text'] ?? '?'),
                CellText::wrap($t->text),
            ];
        }
        $io->table(['#', 'Suggested by', 'Source', 'Translation'], $rows);
    }

    /**
     * @param list<PendingTranslation> $chunk
     * @param list<int> $positions 1-based
     */
    private function approve(SymfonyStyle $io, int $projectId, string $language, array $chunk, array $positions): void
    {
        $approved = 0;
        $failed = 0;
        foreach ($positions as $position) {
            $t = $chunk[$position - 1];
            if ($this->dryRun) {
                $approved++;
                continue;
            }
            try {
                $approvalId = $this->repository->approve($projectId, $t->translationId);
                $this->approvedIds[$t->translationId] = true;
                $approved++;
                // The approval is done: a failing log must not make it look like it failed
                try {
                    $this->approvalLog->record($this->sessionId, $projectId, $language, $t->translationId, $approvalId);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Approval of translation %d cannot be logged (no undo possible): %s', $t->translationId, $e->getMessage()));
                }
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('Translation %d: %s', $t->translationId, $e->getMessage()));
            }
        }
        $io->writeln(sprintf($this->dryRun ? '<info>%d translation(s) would be approved.</info>' : '<info>%d approved</info>%s', $approved, $failed > 0 ? sprintf(', <error>%d failed</error> (they stay pending)', $failed) : ''));
    }

    /**
     * @param list<PendingTranslation> $chunk
     * @param list<int> $positions 1-based
     */
    private function drop(SymfonyStyle $io, int $projectId, array $chunk, array $positions): void
    {
        $dropped = 0;
        $failed = 0;
        foreach ($positions as $position) {
            $t = $chunk[$position - 1];
            if ($this->dryRun) {
                $dropped++;
                continue;
            }
            try {
                $this->repository->delete($projectId, $t->translationId);
                $this->droppedIds[$t->translationId] = true;
                $dropped++;
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('Translation %d: %s', $t->translationId, $e->getMessage()));
            }
        }
        $io->writeln(sprintf($this->dryRun ? '<info>%d translation(s) would be dropped.</info>' : '<info>%d dropped</info>%s', $dropped, $failed > 0 ? sprintf(', <error>%d failed</error> (they stay pending)', $failed) : ''));
    }

    /**
     * Asks for one of the given keys, by position (1-based) or, if allowed, by value, or for one of the extra keywords.
     *
     * @param list<string> $keys
     * @param list<string> $extras
     */
    private function pick(SymfonyStyle $io, string $question, array $keys, array $extras, bool $allowValue = true): string
    {
        /** @var string $answer */
        $answer = $io->ask($question, null, static function (mixed $answer) use ($keys, $extras, $allowValue): string {
            $answer = is_string($answer) ? trim($answer) : '';
            if (in_array(strtolower($answer), $extras, true)) {
                return strtolower($answer);
            }
            if (ctype_digit($answer) && (int)$answer >= 1 && (int)$answer <= count($keys)) {
                return $keys[(int)$answer - 1];
            }
            if ($allowValue) {
                foreach ($keys as $key) {
                    if (strcasecmp($key, $answer) === 0) {
                        return $key;
                    }
                }
            }
            throw new \InvalidArgumentException(sprintf('Please answer with a number between 1 and %d%s.', count($keys), $extras !== [] ? ' or one of: ' . implode(', ', $extras) : ''));
        });

        return $answer;
    }
}
