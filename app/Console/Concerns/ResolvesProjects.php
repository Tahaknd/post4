<?php

namespace App\Console\Concerns;

use App\Models\Project;

/**
 * What the project:* commands share: the exit codes of the project family, reading a project id argument, and
 * errors on stderr (stdout is reserved for results). Exit codes, the same everywhere they apply:
 *   0 success, 1 failure or declined confirmation, 2 invalid argument, 3 project not found,
 *   4 the project was deleted but its events purge could not be queued.
 */
trait ResolvesProjects
{
    /** A well-formed id or key that matches no project (scripts treat it as "already gone"). */
    public const int NOT_FOUND = 3;

    /** The project is deleted but the purge of its events could not be queued (the sweeper will pick it up). */
    public const int PURGE_NOT_QUEUED = 4;

    /**
     * The project named by a numeric id argument, or the exit code to return (after printing the error).
     */
    protected function projectFromIdArgument(string $argument): Project|int
    {
        $id = (string) $this->argument($argument);

        // Anything else (or a value beyond bigint) would reach Postgres as a query error.
        if (! ctype_digit($id) || strlen($id) > 18) {
            return $this->failWith('Invalid project id.', self::INVALID);
        }

        return Project::find($id) ?? $this->failWith('Project not found.', self::NOT_FOUND);
    }

    /** Prints an error to stderr and returns the exit code to return. */
    protected function failWith(string $message, int $exitCode): int
    {
        $this->getOutput()->getErrorStyle()->error($message);

        return $exitCode;
    }

    protected function warnOnStderr(string $message): void
    {
        $this->getOutput()->getErrorStyle()->warning($message);
    }
}
