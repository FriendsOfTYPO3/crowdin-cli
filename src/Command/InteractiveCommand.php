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
use TYPO3\CrowdinCli\Entity\StringReview;
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
            $candidates = array_values(array_filter($progress, static fn(LanguageProgress $p): bool => $p->hasPending()));
            if ($candidates === []) {
                $io->success('No translation waits for approval.');
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
        // Crowdin does not list the suggestions of a string which has an approved translation: they are looked for
        // separately, which is only affordable for one suggester at a time
        /** @var array<string, true> $hiddenLoaded suggesters whose hidden suggestions are part of the pending ones */
        $hiddenLoaded = [];
        $hiddenCount = 0;
        if ($user !== null) {
            $pending = $this->withHiddenSuggestions($io, $pending, $projectId, $language, $user, $path);
            $hiddenLoaded[strtolower($user)] = true;
        } else {
            $hiddenCount = count($this->repository->findStringsWithHiddenSuggestions($projectId, $language, null, $path));
        }
        if ($pending === []) {
            $io->note('No unapproved translations found.');
            return true;
        }

        while (true) {
            $remaining = $this->open($pending);
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
                if ($hiddenCount > 0) {
                    $io->writeln(sprintf('%d more string(s) have suggestions behind an approved translation: they are added once a suggester is picked (or with --user).', $hiddenCount));
                }
                $answer = $this->pick($io, sprintf('Suggester (number, "%s" for everybody, "%s" to go back, "%s" to quit)', self::ALL, self::BACK, self::QUIT), $authors, [self::ALL, self::BACK, self::QUIT], false);
                if ($answer === self::QUIT) {
                    return false;
                }
                if ($answer === self::BACK) {
                    return true;
                }
                $label = $answer === self::ALL ? null : $answer;
                $username = $label === null ? null : self::usernameOf($label);
                if ($username !== null && $username !== '(unknown)' && $hiddenCount > 0 && !isset($hiddenLoaded[strtolower($username)])) {
                    $pending = $this->withHiddenSuggestions($io, $pending, $projectId, $language, $username, $path);
                    $hiddenLoaded[strtolower($username)] = true;
                    $remaining = $this->open($pending);
                }
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

    /**
     * @param list<PendingTranslation> $pending
     * @return list<PendingTranslation> what has been neither approved nor dropped during this run
     */
    private function open(array $pending): array
    {
        return array_values(array_filter($pending, fn(PendingTranslation $t): bool => !isset($this->approvedIds[$t->translationId]) && !isset($this->droppedIds[$t->translationId])));
    }

    /**
     * Adds the suggestions of the given suggester which compete with an approved translation.
     *
     * @param list<PendingTranslation> $pending
     * @return list<PendingTranslation>
     */
    private function withHiddenSuggestions(SymfonyStyle $io, array $pending, int $projectId, string $language, string $user, ?string $path): array
    {
        $username = self::usernameOf($user);
        $stringIds = array_keys($this->repository->findStringsWithHiddenSuggestions($projectId, $language, $username, $path));
        $io->writeln(sprintf('Looking for suggestions of %s behind an approved translation (%d string(s))...', $username, count($stringIds)));
        $known = array_column(array_map(static fn(PendingTranslation $t): array => [$t->translationId, true], $pending), 1, 0);
        foreach ($this->repository->findHiddenReviews($projectId, $language, $stringIds, $username) as $review) {
            foreach ($review->candidates as $candidate) {
                if (!isset($known[$candidate->translationId])) {
                    $known[$candidate->translationId] = true;
                    $pending[] = $candidate;
                }
            }
        }

        return $pending;
    }

    /**
     * The username of a "Full name (username)" label, which is also what is shown for suggesters without a full name.
     */
    private static function usernameOf(string $label): string
    {
        return preg_match('/\(([^()]+)\)$/', $label, $m) === 1 ? $m[1] : $label;
    }

    private function printEquivalentCommand(SymfonyStyle $io, int $projectId, string $language, ?string $suggester, ?string $path): void
    {
        $username = $suggester !== null ? self::usernameOf($suggester) : null;
        $io->writeln(sprintf(
            '<comment>Same list without questions:</comment> ./crowdin-cli translations:unapproved %d %s%s%s',
            $projectId,
            escapeshellarg($language),
            $username !== null && $username !== '(unknown)' ? ' --user=' . escapeshellarg($username) : '',
            $path !== null ? ' --path=' . escapeshellarg($path) : ''
        ));
    }

    /**
     * Reviews the selection in two steps: first the strings which have a single suggestion, chunk by chunk (the ones
     * which have no translation yet first, then the ones which try to improve an approved translation), then the
     * strings with competing suggestions, one at a time, since approving one makes the others pointless.
     *
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

        $io->writeln('Looking for competing suggestions and existing translations...');
        $reviews = $this->repository->findReviews($projectId, $language, $selection);
        usort($reviews, static fn(StringReview $a, StringReview $b): int => [$a->improvesApproved(), $pathOf($a->candidates[0]), $a->candidates[0]->translationId] <=> [$b->improvesApproved(), $pathOf($b->candidates[0]), $b->candidates[0]->translationId]);

        $chunks = [];
        foreach ([false, true] as $improvesApproved) {
            $group = array_values(array_filter($reviews, static fn(StringReview $r): bool => !$r->isContested() && $r->improvesApproved() === $improvesApproved));
            foreach (array_chunk($group, $pageSize) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        foreach ($chunks as $index => $chunk) {
            $improvesApproved = $chunk[0]->improvesApproved();
            $io->section(sprintf(
                'Chunk %d of %d (%d translation(s)%s)',
                $index + 1,
                count($chunks),
                count($chunk),
                $improvesApproved ? ', improving an approved translation: less urgent' : ''
            ));
            $this->renderChunk($io, $chunk, $sources, $pathOf, $improvesApproved);
            $translations = array_map(static fn(StringReview $r): PendingTranslation => $r->candidates[0], $chunk);

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
                    foreach ($translations as $i => $t) {
                        $identifier = $sources[$t->stringId]['identifier'] ?? '';
                        $io->writeln(sprintf('%d: %s (translation #%d) by %s on %s%s', $i + 1, $identifier !== '' ? $identifier : 'string #' . $t->stringId, $t->translationId, $t->authorLabel(), substr($t->createdAt, 0, 10), $t->pluralForm !== null ? ', plural form ' . $t->pluralForm : ''));
                    }
                    continue;
                }
                if ($review->action === ReviewInput::APPROVE) {
                    if ($review->positions === []) {
                        $io->note('Nothing selected.');
                        continue;
                    }
                    $this->approve($io, $projectId, $language, $translations, $review->positions);
                }
                if ($review->action === ReviewInput::DROP) {
                    $question = sprintf('Permanently delete %d suggestion(s) (position(s) %s)? This cannot be undone', count($review->positions), implode(', ', $review->positions));
                    if (!$io->confirm($question, false)) {
                        continue;
                    }
                    $this->drop($io, $projectId, $translations, $review->positions);
                }
                break;
            }
        }

        $contested = array_values(array_filter($reviews, static fn(StringReview $r): bool => $r->isContested()));
        foreach ($contested as $index => $review) {
            if (!$this->reviewContested($io, $projectId, $language, $review, $sources, $pathOf, $index + 1, count($contested))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Single-string step: several people suggested a translation for the same string.
     *
     * @param array<int, array{text: string, fileId: int, identifier: string}> $sources
     * @param \Closure(PendingTranslation): string $pathOf
     * @return bool false if the user quits
     */
    private function reviewContested(SymfonyStyle $io, int $projectId, string $language, StringReview $review, array $sources, \Closure $pathOf, int $number, int $total): bool
    {
        $first = $review->candidates[0];
        $identifier = $sources[$first->stringId]['identifier'] ?? '';
        $source = $sources[$first->stringId]['text'] ?? '?';

        while (true) {
            $candidates = $review->candidates;
            $count = count($candidates);
            $io->section(sprintf('Competing suggestions %d of %d (%d for the same string)', $number, $total, $count));
            $io->writeln(sprintf('<info>%s</info>%s%s', $pathOf($first), $identifier !== '' ? ' (' . $identifier . ')' : '', $review->pluralForm !== null ? ', plural form ' . $review->pluralForm : ''));
            $io->writeln('<comment>Source</comment>');
            $io->writeln(CellText::wrap($source, 100));
            $io->writeln('<comment>Current translation</comment>');
            $io->writeln($review->approved !== null
                ? CellText::wrap($review->approved->text, 100) . sprintf(' (approved, by %s)', $review->approved->authorLabel())
                : '(none yet: this string is not translated)');

            $rows = [];
            foreach ($candidates as $i => $t) {
                if ($i > 0) {
                    $rows[] = new TableSeparator();
                }
                $rows[] = [$i + 1, $t->authorLabel(), substr($t->createdAt, 0, 10), CellText::wrap($t->text)];
            }
            $io->table(['#', 'Suggested by', 'Date', 'Translation'], $rows);

            $canKeep = $review->approved !== null;
            while (true) {
                /** @var string $answer */
                $answer = $io->ask(sprintf('[1-%d] approve that one  [d 1,2] drop some  %s[s] skip  [q] quit', $count, $canKeep ? '[k] keep the current translation  ' : ''), 's');
                $input = ReviewInput::parseChoice($answer, $count, $canKeep);
                if ($input === null) {
                    $io->error(sprintf('Not understood. Examples: "1", "d 2", %s"s", "q".', $canKeep ? '"k", ' : ''));
                    continue;
                }

                switch ($input->action) {
                    case ReviewInput::QUIT:
                        return false;
                    case ReviewInput::SKIP:
                        return true;
                    case ReviewInput::CHOOSE:
                        $chosen = $candidates[$input->positions[0] - 1];
                        $this->approve($io, $projectId, $language, [$chosen], [1]);
                        if (!$this->dryRun && !isset($this->approvedIds[$chosen->translationId])) {
                            // The approval failed: the suggestions stay as they are
                            continue 2;
                        }
                        $rivals = $review->rivalsOf($chosen);
                        if ($rivals !== [] && $io->confirm(sprintf('Permanently delete the %d competing suggestion(s)? This cannot be undone', count($rivals)), true)) {
                            $this->drop($io, $projectId, $rivals, range(1, count($rivals)));
                        }

                        return true;
                    case ReviewInput::KEEP:
                        if ($io->confirm(sprintf('Keep the current translation and permanently delete all %d suggestion(s)? This cannot be undone', $count), false)) {
                            $this->drop($io, $projectId, $candidates, range(1, $count));

                            return true;
                        }
                        continue 2;
                    case ReviewInput::DROP:
                        if (!$io->confirm(sprintf('Permanently delete %d suggestion(s) (position(s) %s)? This cannot be undone', count($input->positions), implode(', ', $input->positions)), false)) {
                            continue 2;
                        }
                        $this->drop($io, $projectId, $candidates, $input->positions);
                        $remaining = [];
                        foreach ($candidates as $i => $t) {
                            $gone = $this->dryRun ? in_array($i + 1, $input->positions, true) : isset($this->droppedIds[$t->translationId]);
                            if (!$gone) {
                                $remaining[] = $t;
                            }
                        }
                        if ($remaining === []) {
                            return true;
                        }
                        $review = new StringReview($review->stringId, $review->pluralForm, $remaining, $review->approved);
                        // Show what is left
                        continue 3;
                }
            }
        }
    }

    /**
     * @param list<StringReview> $chunk
     * @param array<int, array{text: string, fileId: int, identifier: string}> $sources
     * @param \Closure(PendingTranslation): string $pathOf
     * @param bool $showCurrent whether to show the translation which is already approved
     */
    private function renderChunk(SymfonyStyle $io, array $chunk, array $sources, \Closure $pathOf, bool $showCurrent): void
    {
        $headers = $showCurrent ? ['#', 'Suggested by', 'Source', 'Current translation', 'Suggestion'] : ['#', 'Suggested by', 'Source', 'Translation'];
        $currentPath = null;
        $rows = [];
        foreach ($chunk as $i => $review) {
            $t = $review->candidates[0];
            $path = $pathOf($t);
            if ($path !== $currentPath) {
                if ($rows !== []) {
                    $io->table($headers, $rows);
                    $rows = [];
                }
                $io->writeln(sprintf('<info>%s</info>', $path));
                $currentPath = $path;
            }
            if ($rows !== []) {
                $rows[] = new TableSeparator();
            }
            $row = [
                $i + 1,
                $t->authorLabel(),
                CellText::wrap($sources[$t->stringId]['text'] ?? '?'),
            ];
            if ($showCurrent) {
                $row[] = CellText::wrap($review->approved->text ?? '');
            }
            $row[] = CellText::wrap($t->text);
            $rows[] = $row;
        }
        $io->table($headers, $rows);
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
