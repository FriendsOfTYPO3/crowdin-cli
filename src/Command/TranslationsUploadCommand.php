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
use TYPO3\CrowdinCli\Entity\UploadCandidate;
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
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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

        try {
            $resolved = $this->resolver->resolve($project);
            $localFiles = $this->uploader->findLocalFiles($directory, $path);
            if ($localFiles === []) {
                $io->warning('No file found in the directory' . ($path === null ? '.' : sprintf(' below "%s".', $path)));
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
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $uploadable = array_values(array_filter($candidates, static fn(UploadCandidate $c): bool => $c->isUploadable()));
        $total = array_sum(array_map(static fn(UploadCandidate $c): int => $c->toUpload, $uploadable));
        if ($uploadable === []) {
            $io->note('Nothing to upload.');
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
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        if (!$input->getOption('yes') && !$io->confirm($autoApprove ? 'Upload and APPROVE them?' : 'Upload them?', false)) {
            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($uploadable as $i => $candidate) {
            $prefix = sprintf('[%d/%d] %s', $i + 1, count($uploadable), $candidate->relativePath);
            try {
                $this->uploader->upload($resolved->id, $language, $candidate, $autoApprove, $allowSame);
                $io->writeln(sprintf('%s <info>uploaded</info> (%d)', $prefix, $candidate->toUpload));
            } catch (\Throwable $e) {
                $failed++;
                $io->writeln(sprintf('%s <error>failed</error>: %s', $prefix, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d file(s) could not be uploaded.', $failed, count($uploadable)));
            return Command::FAILURE;
        }
        $io->success(sprintf('%d file(s) uploaded.', count($uploadable)));

        return Command::SUCCESS;
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
