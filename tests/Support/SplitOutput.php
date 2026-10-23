<?php

namespace Tests\Support;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An output with a separate stderr (a BufferedOutput alone merges both streams), so a test can prove
 * what a command writes to stdout versus stderr.
 */
final class SplitOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $error;

    public function __construct()
    {
        parent::__construct();
        $this->error = new BufferedOutput;
    }

    public function stdout(): string
    {
        return $this->fetch();
    }

    public function stderr(): string
    {
        return $this->error instanceof BufferedOutput ? $this->error->fetch() : '';
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->error;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->error = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('Sections are not supported by SplitOutput.');
    }
}
