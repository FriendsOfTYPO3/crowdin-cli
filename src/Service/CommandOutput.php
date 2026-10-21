<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the result of a command, either for humans or, with "--format=json", for scripts.
 *
 * In JSON mode, stdout only receives one JSON document: what is meant for humans (progress, notes) goes to stderr,
 * and warnings are returned in the "warnings" key of the document.
 */
final class CommandOutput
{
    public const FORMATS = ['text', 'json'];

    public readonly SymfonyStyle $io;
    public readonly bool $json;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        InputInterface $input,
        private readonly OutputInterface $output,
    ) {
        $format = $input->getOption('format');
        if (!in_array($format, self::FORMATS, true)) {
            throw new InvalidOptionException(sprintf('Invalid value for --format, use one of: %s.', implode(', ', self::FORMATS)));
        }
        $this->json = $format === 'json';
        $this->io = new SymfonyStyle($input, $this->json ? $this->humanOutput($output) : $output);
    }

    public static function addOption(Command $command): void
    {
        $command->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "' . implode('" or "', self::FORMATS) . '"', 'text');
    }

    public function warning(string $message): void
    {
        if ($this->json) {
            $this->warnings[] = $message;
        } else {
            $this->io->warning($message);
        }
    }

    /**
     * Ends a command in failure: the message is shown as an error, or returned as JSON.
     *
     * @return int the exit code
     */
    public function fail(string $message, int $exitCode = Command::FAILURE): int
    {
        if ($this->json) {
            $this->write(['error' => ['message' => $message]]);
        } else {
            $this->io->error($message);
        }

        return $exitCode;
    }

    /**
     * Prints the result in JSON mode, does nothing otherwise.
     *
     * @param array<string, mixed> $data
     */
    public function emit(array $data): void
    {
        if ($this->json) {
            $this->write($data);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        if ($this->warnings !== []) {
            $data['warnings'] = $this->warnings;
        }
        $this->output->writeln(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW
        );
    }

    private function humanOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput();
    }
}
