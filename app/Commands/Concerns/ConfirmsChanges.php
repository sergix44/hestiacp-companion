<?php

namespace App\Commands\Concerns;

trait ConfirmsChanges
{
    /**
     * Interactive runs ask, --apply skips the question, and -n without --apply is a dry run.
     */
    private function confirmed(string $question, string $action): bool
    {
        if ($this->option('apply')) {
            return true;
        }
        if (! $this->input->isInteractive()) {
            $this->components->info("Dry run: nothing was changed. Pass --apply to $action.");

            return false;
        }

        return $this->confirm($question);
    }
}
