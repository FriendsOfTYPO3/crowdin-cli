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
use TYPO3\CrowdinCli\Service\ProjectResolver;
use TYPO3\CrowdinCli\Service\TranslationDownloader;

#[AsCommand(name: 'translations:download', description: 'Download the translation files of a project in a language')]
final class TranslationsDownloadCommand extends Command
{
    public function __construct(
        private readonly TranslationDownloader $downloader,
        private readonly ProjectResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Crowdin project ID (or identifier)')
            ->addArgument('language', InputArgument::REQUIRED, 'Crowdin language ID, e.g. "fr"')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to extract the files to (default: "<project identifier>-<language>" in the current directory)')
            ->addOption('approved-only', null, InputOption::VALUE_NONE, 'Only export approved translations');
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
        /** @var string|null $outputDir */
        $outputDir = $input->getOption('output-dir');

        try {
            $resolved = $this->resolver->resolve($project);
            $directory = $outputDir ?? self::defaultDirectory($resolved->identifier, $language);

            $io->writeln(sprintf('Building the "%s" translations of %s, this may take a moment...', $language, $resolved->identifier));
            $files = $this->downloader->download($resolved->id, $language, $directory, (bool)$input->getOption('approved-only'));
        } catch (\Throwable $e) {
            return $out->fail($e->getMessage());
        }

        $out->emit([
            'project' => $resolved->id,
            'identifier' => $resolved->identifier,
            'language' => $language,
            'directory' => $directory,
            'files' => $files,
        ]);
        if ($out->json) {
            return Command::SUCCESS;
        }

        if ($files === []) {
            $io->warning('The downloaded archive contains no file.');
            return Command::SUCCESS;
        }

        if ($io->isVerbose()) {
            $io->listing($files);
        }
        $io->success(sprintf('%d file(s) extracted to %s', count($files), $directory));

        return Command::SUCCESS;
    }

    private static function defaultDirectory(string $identifier, string $language): string
    {
        $slug = static fn(string $value): string => trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-');

        return (getcwd() ?: '.') . '/' . $slug($identifier) . '-' . $slug($language);
    }
}
