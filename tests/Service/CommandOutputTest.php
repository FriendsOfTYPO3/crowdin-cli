<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CrowdinCli\Service\CommandOutput;

final class CommandOutputTest extends TestCase
{
    private BufferedOutput $buffer;

    protected function setUp(): void
    {
        $this->buffer = new BufferedOutput();
    }

    private function create(?string $format): CommandOutput
    {
        $command = new Command('test');
        CommandOutput::addOption($command);
        $input = new ArrayInput($format === null ? [] : ['--format' => $format], $command->getDefinition());

        return new CommandOutput($input, $this->buffer);
    }

    public function testDefaultsToText(): void
    {
        $out = $this->create(null);

        self::assertFalse($out->json);
        $out->emit(['a' => 1]);
        self::assertSame('', $this->buffer->fetch());
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->create('xml');
    }

    public function testEmitsJsonOnly(): void
    {
        $out = $this->create('json');
        $out->io->writeln('Progress...');
        $out->emit(['name' => 'Zoë / "x"']);

        self::assertTrue($out->json);
        self::assertSame(['name' => 'Zoë / "x"'], json_decode($this->buffer->fetch(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testWarningsJoinTheDocumentInJsonMode(): void
    {
        $out = $this->create('json');
        $out->warning('Careful');
        $out->emit(['done' => true]);

        self::assertSame(['done' => true, 'warnings' => ['Careful']], json_decode($this->buffer->fetch(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFailReturnsTheExitCode(): void
    {
        $out = $this->create('json');

        self::assertSame(Command::INVALID, $out->fail('Nope', Command::INVALID));
        self::assertSame(['error' => ['message' => 'Nope']], json_decode($this->buffer->fetch(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFailPrintsAnErrorInTextMode(): void
    {
        $out = $this->create('text');

        self::assertSame(Command::FAILURE, $out->fail('Nope'));
        self::assertStringContainsString('Nope', $this->buffer->fetch());
    }
}
