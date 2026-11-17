<?php

namespace App\Commands;

use Exception;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SQLite3;

class RcloneSyncPathCommand extends Command
{
    protected $signature = 'rclone:sync-path
        {source : Local directory to sync}
        {destination : rclone destination, e.g. pcloud:backups/stacks}
        {flags?* : Extra rclone flags for every rclone call, given after "--" (e.g. -- --bwlimit 8M)}
        {--apply : Sync for real; without it rclone runs with --dry-run}';

    protected $description = 'Sync a directory with rclone, uploading consistent copies of live SQLite databases';

    private const LOCK_DIR = '/run/lock';

    private const SQLITE_HEADER = "SQLite format 3\0";

    private const SIDECARS = ['-wal', '-shm', '-journal'];

    public function handle(): int
    {
        if (! class_exists(SQLite3::class)) {
            return $this->failWith('This command needs the PHP sqlite3 extension (e.g. apt install php-sqlite3).');
        }

        $source = realpath($this->argument('source'));
        if ($source === false || ! is_dir($source)) {
            return $this->failWith("Source directory not found: {$this->argument('source')}");
        }
        $destination = $this->argument('destination');

        $lockFile = self::LOCK_DIR.'/hcpc-rclone-'.sha1($destination).'.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            return $this->failWith("Cannot open lock file $lockFile");
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->failWith("Another sync to $destination is already running.");
        }

        if (! $this->option('apply')) {
            $this->components->warn('Dry run: rclone runs with --dry-run, nothing is uploaded or deleted. Pass --apply to sync.');
        }

        $temp = sys_get_temp_dir().'/hcpc-sync-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temp, 0700);

        try {
            $this->sync($source, $destination, $temp);
        } catch (Exception $e) {
            return $this->failWith($e->getMessage());
        } finally {
            File::deleteDirectory($temp);
        }

        $this->components->info('Sync completed: '.date(DATE_ATOM));

        return self::SUCCESS;
    }

    private function sync(string $source, string $destination, string $temp): void
    {
        $snapshots = "$temp/snapshots";
        $databases = $this->snapshotDatabases($source, $snapshots);

        $excludes = '';
        $sidecars = '';
        foreach ($databases as $database) {
            $excludes .= self::literalFilter($database)."\n";
            foreach (self::SIDECARS as $suffix) {
                $excludes .= self::literalFilter($database.$suffix)."\n";
                $sidecars .= $database.$suffix."\0";
            }
        }
        File::put("$temp/excludes.txt", $excludes);
        File::put("$temp/sidecars.txt", $sidecars);

        $this->components->twoColumnDetail("Sync <fg=gray>$source</>", $destination);
        $this->rclone(['sync', $source, $destination, '--links', '--exclude-from', "$temp/excludes.txt"]);

        // Replace each live database on the remote with its consistent snapshot.
        foreach ($databases as $database) {
            $this->rclone(['copyto', "$snapshots/$database", self::remotePath($destination, $database)]);
        }

        // Discard WAL/SHM/journal files left on the remote by earlier syncs.
        if ($databases) {
            $this->rclone(['delete', $destination, '--files-from0', "$temp/sidecars.txt"]);
        }
    }

    /**
     * @return list<string> paths of the databases found, relative to $source
     */
    private function snapshotDatabases(string $source, string $snapshots): array
    {
        $databases = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || ! $entry->isFile() || ! self::isSqlite($path)) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($source)), '/');
            // The exclude list is newline separated.
            if (strpbrk($relative, "\r\n") !== false) {
                throw new RuntimeException("Unsupported SQLite path with a newline: $path");
            }

            $this->components->task("SQLite snapshot <fg=gray>$relative</>", fn () => self::snapshot($path, "$snapshots/$relative"));
            $databases[] = $relative;
        }

        return $databases;
    }

    private static function isSqlite(string $path): bool
    {
        $header = file_get_contents($path, length: strlen(self::SQLITE_HEADER));
        if ($header === false) {
            throw new RuntimeException("Cannot read $path");
        }

        return $header === self::SQLITE_HEADER;
    }

    private static function snapshot(string $database, string $copy): void
    {
        File::ensureDirectoryExists(dirname($copy), 0700);

        $live = new SQLite3($database, SQLITE3_OPEN_READONLY);
        $live->enableExceptions(true);
        $live->busyTimeout(60_000);
        $saved = new SQLite3($copy);
        $saved->enableExceptions(true);

        try {
            $live->backup($saved);
            $check = $saved->querySingle('PRAGMA quick_check');
            if ($check !== 'ok') {
                throw new RuntimeException("SQLite quick_check failed for $database: $check");
            }
        } finally {
            $saved->close();
            $live->close();
        }
    }

    private function rclone(array $arguments): void
    {
        $flags = $this->argument('flags');
        if (! $this->option('apply')) {
            $flags[] = '--dry-run';
        }

        $result = Process::forever()->run(['rclone', ...$arguments, ...$flags], function (string $type, string $output) {
            $this->output->write($output);
        });

        if ($result->failed()) {
            $hint = $result->exitCode() === 127 ? ' Is rclone installed?' : '';
            throw new RuntimeException("rclone {$arguments[0]} failed with exit code {$result->exitCode()}.$hint");
        }
    }

    // A filter rule that matches exactly this path: anchored at the sync root, glob characters escaped.
    private static function literalFilter(string $path): string
    {
        return '/'.addcslashes($path, '\\[]{}*?');
    }

    private static function remotePath(string $destination, string $path): string
    {
        return str_ends_with($destination, ':') || str_ends_with($destination, '/')
            ? $destination.$path
            : "$destination/$path";
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
