<?php

namespace App\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Where a console command takes a password from. Never from argv (it would land in shell history and
 * `ps`): a hidden interactive prompt, or the first line of a file, or `-` for standard input.
 *
 * @mixin Command
 */
trait ReadsPassword
{
    /**
     * @return string|null the password, or null after an error message was written (use INVALID as exit code)
     */
    protected function readPassword(): ?string
    {
        $source = $this->option('password-file');

        if (is_string($source) && $source !== '') {
            return $this->passwordFromFile($source);
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No password source: run interactively, or pass --password-file=<file> (use "-" for standard input).');

            return null;
        }

        $password = (string) $this->secret('Password');

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->components->error('The passwords do not match.');

            return null;
        }

        return $password;
    }

    private function passwordFromFile(string $source): ?string
    {
        $line = $source === '-' ? $this->readStandardInput() : $this->readFile($source);

        if ($line === null) {
            $this->components->error($source === '-' ? 'Nothing to read on standard input.' : "Cannot read the password file: {$source}");

            return null;
        }

        // Only the trailing line break is dropped: spaces are part of a password.
        return rtrim($line, "\r\n");
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        if ((fileperms($path) & 0077) !== 0) {
            // A warning, not an error: the file may sit on a mount that cannot hold modes.
            $this->components->warn("The password file {$path} is readable by group or others; run chmod 600 on it and remove it after use.");
        }

        $handle = fopen($path, 'r');

        return $handle === false ? null : $this->firstLine($handle);
    }

    /** Overridable seam so tests need no real standard input. */
    protected function readStandardInput(): ?string
    {
        return $this->firstLine(STDIN);
    }

    /** @param  resource  $handle */
    private function firstLine($handle): ?string
    {
        // 4 KiB is far above any sane password; it only bounds a hostile or mistaken input.
        $line = fgets($handle, 4096);

        return $line === false ? null : $line;
    }
}
