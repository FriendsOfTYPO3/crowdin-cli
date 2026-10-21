<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CrowdinCli\Entity\UploadCandidate;
use TYPO3\CrowdinCli\Service\CommandOutput;
use TYPO3\CrowdinCli\Service\ProjectResolver;
use TYPO3\CrowdinCli\Service\TranslationUploader;

#[AsCommand(name: 'translations:upload', description: 'Upload translation files of a directory (as downloaded) back to a project, as suggestions')]
final class TranslationsUploadCommand extends Command
{
    public function __construct(
        private readonly TranslationUploader $uploader,
        private readonly ProjectResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID (or identifier)')
            ->addArgument('language', InputArgument::REQUIRED, 'Crowdin language ID, e.g. "fr"')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory holding the files, as created by translations:download')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Only upload files whose path in the directory starts with this value, e.g. "master/Resources/Private/Language/fr.locallang.xlf"')
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Approve the uploaded translations instead of suggesting them')
            ->addOption('allow-same-as-source', null, InputOption::VALUE_NONE, 'Also upload translations identical to their source text, which are skipped by default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be uploaded, nothing is sent')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (required with --format=json, unless --dry-run)');
        CommandOutput::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = new CommandOutput($input, $output);
        $io = $out->io;
        /** @var string $project */
        $project = $input->getArgument('project');
        /** @var string $language */
        $language = $input->getArgument('language');
        /** @var string $directory */
        $directory = $input->getArgument('directory');
        /** @var string|null $path */
        $path = $input->getOption('path');
        $autoApprove = (bool)$input->getOption('auto-approve');
        $allowSame = (bool)$input->getOption('allow-same-as-source');
        $dryRun = (bool)$input->getOption('dry-run');

        if ($out->json && !$input->getOption('yes') && !$dryRun) {
            return $out->fail('--format=json cannot ask for confirmation: add --yes to upload, or --dry-run.', Command::INVALID);
        }

        try {
            $resolved = $this->resolver->resolve($project);
            $localFiles = $this->uploader->findLocalFiles($directory, $path);
            if ($localFiles === []) {
                $io->warning('No file found in the directory' . ($path === null ? '.' : sprintf(' below "%s".', $path)));
                $out->emit($this->report($resolved->id, $language, $dryRun, $autoApprove, [], []));
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Comparing %d file(s) with the "%s" translations of %s...', count($localFiles), $language, $resolved->identifier));
            $projectFiles = $this->uploader->findProjectFiles($resolved->id);
            $candidates = [];
            foreach ($localFiles as $relativePath) {
                $candidate = $this->uploader->analyze($resolved->id, $language, $directory, $relativePath, $projectFiles, $allowSame);
                $candidates[] = $candidate;
                $io->writeln($this->describe($candidate));
            }
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        $uploadable = array_values(array_filter($candidates, static fn(UploadCandidate $c): bool => $c->isUploadable()));
        $total = array_sum(array_map(static fn(UploadCandidate $c): int => $c->toUpload, $uploadable));
        if ($uploadable === []) {
            $io->note('Nothing to upload.');
            $out->emit($this->report($resolved->id, $language, $dryRun, $autoApprove, $candidates, []));
            return Command::SUCCESS;
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%d translation(s) in %d file(s) would be uploaded to %s as %s.',
            $total,
            count($uploadable),
            $resolved->identifier,
            $autoApprove ? '<comment>approved translations</comment>' : 'suggestions',
        ));
        if ($dryRun) {
            $out->emit($this->report($resolved->id, $language, true, $autoApprove, $candidates, []));
            return Command::SUCCESS;
        }

        if (!$input->getOption('yes') && !$io->confirm($autoApprove ? 'Upload and APPROVE them?' : 'Upload them?', false)) {
            return Command::SUCCESS;
        }

        $failed = 0;
        $results = [];
        foreach ($uploadable as $i => $candidate) {
            $prefix = sprintf('[%d/%d] %s', $i + 1, count($uploadable), $candidate->relativePath);
            try {
                $this->uploader->upload($resolved->id, $language, $candidate, $autoApprove, $allowSame);
                $results[$candidate->relativePath] = ['status' => 'uploaded', 'error' => null];
                $io->writeln(sprintf('%s <info>uploaded</info> (%d)', $prefix, $candidate->toUpload));
            } catch (\Throwable $e) {
                $failed++;
                $results[$candidate->relativePath] = ['status' => 'failed', 'error' => $e->getMessage()];
                $io->writeln(sprintf('%s <error>failed</error>: %s', $prefix, $e->getMessage()));
            }
        }

        $out->emit($this->report($resolved->id, $language, false, $autoApprove, $candidates, $results));
        if ($failed > 0) {
            $io->error(sprintf('%d of %d file(s) could not be uploaded.', $failed, count($uploadable)));
            return Command::FAILURE;
        }
        $io->success(sprintf('%d file(s) uploaded.', count($uploadable)));

        return Command::SUCCESS;
    }

    /**
     * @param list<UploadCandidate> $candidates
     * @param array<string, array{status: string, error: string|null}> $results the outcome of the upload, by file
     * @return array<string, mixed>
     */
    private function report(int $projectId, string $language, bool $dryRun, bool $autoApprove, array $candidates, array $results): array
    {
        return [
            'project' => $projectId,
            'language' => $language,
            'dry_run' => $dryRun,
            'auto_approve' => $autoApprove,
            'files' => array_map(static function (UploadCandidate $c) use ($results): array {
                $result = $results[$c->relativePath] ?? null;
                // "pending" is what a dry run reports for the files it would upload
                $status = $result['status'] ?? match (true) {
                    $c->skipReason !== null => 'skipped',
                    !$c->isUploadable() => 'nothing_to_upload',
                    default => 'pending',
                };

                return [
                    'path' => $c->relativePath,
                    'status' => $status,
                    'skip_reason' => $c->skipReason,
                    'to_upload' => $c->toUpload,
                    'unchanged' => $c->unchanged,
                    'same_as_source' => $c->sameAsSource,
                    'untranslated' => $c->empty,
                    'error' => $result['error'] ?? null,
                ];
            }, $candidates),
        ];
    }

    private function describe(UploadCandidate $candidate): string
    {
        if ($candidate->skipReason !== null) {
            return sprintf('  <comment>skip</comment>  %s: %s', $candidate->relativePath, $candidate->skipReason);
        }

        $details = array_filter([
            $candidate->unchanged > 0 ? $candidate->unchanged . ' already up to date' : null,
            $candidate->sameAsSource > 0 ? $candidate->sameAsSource . ' same as source' : null,
            $candidate->empty > 0 ? $candidate->empty . ' untranslated' : null,
        ]);

        return sprintf(
            '  %s  %s: %d to upload%s',
            $candidate->toUpload > 0 ? '<info>send</info>' : 'none',
            $candidate->relativePath,
            $candidate->toUpload,
            $details === [] ? '' : ' (' . implode(', ', $details) . ')',
        );
    }
}
