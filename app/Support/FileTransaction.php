<?php

namespace App\Support;

use RuntimeException;

final class FileTransaction
{
    /** @var list<array{string, ?string}> each change as [path, backup path or null for a new file] */
    private array $changes = [];

    public function __construct(private readonly string $stamp) {}

    public function put(string $path, string $contents): void
    {
        $backup = null;
        if (file_exists($path)) {
            $backup = "$path.bak-$this->stamp";
            if (! @copy($path, $backup)) {
                throw new RuntimeException("Could not back up $path");
            }
        }

        // Recorded before writing, so a rollback also undoes a partial write.
        $this->changes[] = [$path, $backup];

        if (@file_put_contents($path, $contents) === false || ! @chmod($path, 0644)) {
            throw new RuntimeException("Could not write $path");
        }
    }

    public function delete(string $path): void
    {
        $backup = "$path.bak-$this->stamp";
        if (! @rename($path, $backup)) {
            throw new RuntimeException("Could not remove $path");
        }

        $this->changes[] = [$path, $backup];
    }

    public function rollback(): void
    {
        $failed = [];
        foreach (array_reverse($this->changes) as [$path, $backup]) {
            $restored = $backup === null
                ? ! file_exists($path) || @unlink($path)
                : @rename($backup, $path);

            if (! $restored) {
                $failed[] = $path;
            }
        }
        $this->changes = [];

        if ($failed) {
            throw new RuntimeException('Could not restore: '.implode(', ', $failed));
        }
    }

    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return array_values(array_filter(array_column($this->changes, 1)));
    }
}
