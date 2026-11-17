<?php

namespace App\Commands;

use App\Commands\Concerns\ConfirmsChanges;
use App\Php\Installations;
use App\Php\ProductionSettings;
use App\Php\Runtime;
use App\Support\FileTransaction;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class PhpOptimizeCommand extends Command
{
    use ConfirmsChanges;

    private const FUNCTIONS_FILE = '98-disabled_function.ini';

    protected $description = 'Apply production PHP settings to every installed PHP version';

    public function __construct()
    {
        $this->signature = 'php:optimize'
            .' {--apply : Apply the changes without asking}'
            .' {--fpm-functions= : Re-enable disabled functions for websites, yes or no (default: keep the current state)}';
        foreach (ProductionSettings::DEFAULTS as $key => $default) {
            $this->signature .= ' {--'.ProductionSettings::optionName($key)."= : $key (default: $default)}";
        }

        parent::__construct();
    }

    public function handle(Runtime $runtime): int
    {
        $php = new Installations(config('hestia.php_path'));

        if ($runtime->disabledFunctions()) {
            return $this->enableCliFunctions($php, $runtime);
        }

        return $this->optimize($php);
    }

    private function optimize(Installations $php): int
    {
        $values = $this->resolveValues($php);
        $fpmFunctions = $values === null ? null : $this->resolveFpmFunctions($php);
        if ($fpmFunctions === null) {
            return self::FAILURE;
        }

        $changes = $this->plannedChanges($php, $values, $fpmFunctions);
        $this->warnAboutOtherOverrides($php);
        if ($changes === []) {
            $this->components->info('Nothing to change.');

            return self::SUCCESS;
        }

        // Only versions whose PHP-FPM files change get tested and restarted.
        $fpmChanged = array_values(array_filter($php->versions(), function (string $version) use ($php, $changes) {
            $dir = $php->confDir($version, 'fpm');

            return $dir !== null && array_filter(array_keys($changes), fn (string $path) => str_starts_with($path, "$dir/"));
        }));
        $services = array_values(array_filter($fpmChanged, fn (string $version) => Process::run(['systemctl', 'is-active', '--quiet', "php$version-fpm"])->successful()));

        $this->components->info('Planned changes:');
        $this->listChanges($changes, $services);
        if (! $this->writable($changes)) {
            return self::FAILURE;
        }
        if (! $this->confirmed('Apply these changes?', 'apply')) {
            return self::SUCCESS;
        }

        $transaction = $this->apply($changes);
        if ($transaction === null) {
            return self::FAILURE;
        }

        if (! $this->testFpm($fpmChanged, $transaction)) {
            return self::FAILURE;
        }

        return $this->restartFpm($services, $transaction) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Precedence for each value: option, then the current FPM file of the lowest version, then the default.
     *
     * @return array<string, string>|null null when a value is invalid
     */
    private function resolveValues(Installations $php): ?array
    {
        $current = [];
        foreach ($php->versions() as $version) {
            $dir = $php->confDir($version, 'fpm');
            if ($dir !== null && is_file("$dir/".ProductionSettings::FILE)) {
                $current = ProductionSettings::fromIni("$dir/".ProductionSettings::FILE);
                break;
            }
        }

        $values = [];
        $rows = [];
        foreach (ProductionSettings::DEFAULTS as $key => $default) {
            $option = $this->option(ProductionSettings::optionName($key));
            [$value, $from] = match (true) {
                $option !== null => [$option, 'option'],
                isset($current[$key]) => [$current[$key], 'current'],
                default => [$default, 'default'],
            };
            $values[$key] = trim($value);
            $rows[] = [$key, $values[$key], $from];
        }

        if ($this->input->isInteractive()) {
            $this->table(['Setting', 'Value', 'From'], $rows);
            if ($this->confirm('Change any value?')) {
                foreach ($values as $key => $value) {
                    while (($error = ProductionSettings::validate($key, $answer = trim((string) $this->ask($key, $value)))) !== null) {
                        $this->components->error($error);
                    }
                    $values[$key] = $answer;
                }
            }
        }

        foreach ($values as $key => $value) {
            if (($error = ProductionSettings::validate($key, $value)) !== null) {
                $this->components->error($error);

                return null;
            }
        }

        return $values;
    }

    /**
     * Defaults to the current state, so re-running never silently changes the earlier answer.
     */
    private function resolveFpmFunctions(Installations $php): ?bool
    {
        $current = false;
        foreach ($php->versions() as $version) {
            $dir = $php->confDir($version, 'fpm');
            $current = $current || ($dir !== null && is_file("$dir/".self::FUNCTIONS_FILE));
        }

        $option = $this->option('fpm-functions');
        if ($option !== null) {
            if (! in_array($option, ['yes', 'no'], true)) {
                $this->components->error('--fpm-functions must be yes or no.');

                return null;
            }

            return $option === 'yes';
        }

        return $this->input->isInteractive()
            ? $this->confirm('Re-enable disabled functions for websites (PHP-FPM)?', $current)
            : $current;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, ?string> path => new contents, null to remove; files that would not change are left out
     */
    private function plannedChanges(Installations $php, array $values, bool $fpmFunctions): array
    {
        $functions = view('php.disabled-functions')->render();
        $wanted = [];
        foreach ($php->versions() as $version) {
            if ($dir = $php->confDir($version, 'cli')) {
                $wanted["$dir/".self::FUNCTIONS_FILE] = $functions;
                $wanted["$dir/".ProductionSettings::FILE] = ProductionSettings::render($values, cli: true);
            }
            if ($dir = $php->confDir($version, 'fpm')) {
                $wanted["$dir/".ProductionSettings::FILE] = ProductionSettings::render($values, cli: false);
                $wanted["$dir/".self::FUNCTIONS_FILE] = $fpmFunctions ? $functions : null;
            }
        }

        return array_filter($wanted, fn (?string $contents, string $path) => $contents === null
            ? is_file($path)
            : ! self::hasContents($path, $contents), ARRAY_FILTER_USE_BOTH);
    }

    private function warnAboutOtherOverrides(Installations $php): void
    {
        $others = [];
        foreach ($php->versions() as $version) {
            foreach (['cli', 'fpm'] as $sapi) {
                $dir = $php->confDir($version, $sapi);
                foreach ($dir === null ? [] : glob("$dir/*.ini") as $file) {
                    if (basename($file) !== self::FUNCTIONS_FILE && self::setsDisableFunctions($file)) {
                        $others[] = $file;
                    }
                }
            }
        }

        if ($others) {
            $this->components->warn('These files also set disable_functions and may override this command:');
            foreach ($others as $file) {
                $this->line("  $file");
            }
            $this->newLine();
        }
    }

    /**
     * @param  list<string>  $versions
     */
    private function testFpm(array $versions, FileTransaction $transaction): bool
    {
        foreach ($versions as $version) {
            $result = Process::run(["php-fpm$version", '-t']);

            if ($result->exitCode() === 127) {
                $this->components->warn("php-fpm$version not found, skipping its config test.");
            } elseif ($result->failed()) {
                $transaction->rollback();
                $this->components->error("php-fpm$version -t failed; all changes were rolled back.");
                $this->line(trim($result->errorOutput() ?: $result->output()));

                return false;
            } else {
                $this->components->twoColumnDetail("php-fpm$version -t", 'OK');
            }
        }

        return true;
    }

    /**
     * A restart that fails rolls back every change and restarts the services again with the previous settings.
     *
     * @param  list<string>  $versions  versions whose PHP-FPM service is running
     */
    private function restartFpm(array $versions, FileTransaction $transaction): bool
    {
        $restarted = [];
        foreach ($versions as $version) {
            $result = Process::run(['systemctl', 'restart', "php$version-fpm"]);
            if ($result->successful()) {
                $restarted[] = $version;
                $this->components->twoColumnDetail("php$version-fpm", 'restarted');

                continue;
            }

            $this->components->error("Could not restart php$version-fpm.");
            $this->line(trim($result->errorOutput()));
            $transaction->rollback();
            $this->components->warn('All changes were rolled back. Restarting with the previous settings:');
            foreach ([...$restarted, $version] as $affected) {
                $ok = Process::run(['systemctl', 'restart', "php$affected-fpm"])->successful();
                $this->components->twoColumnDetail("php$affected-fpm", $ok ? 'restarted' : '<fg=red>FAILED</>');
            }

            return false;
        }

        return true;
    }

    private function enableCliFunctions(Installations $php, Runtime $runtime): int
    {
        $contents = view('php.disabled-functions')->render();

        $changes = [];
        foreach ($php->versions() as $version) {
            $dir = $php->confDir($version, 'cli');
            if ($dir !== null && ! self::hasContents("$dir/".self::FUNCTIONS_FILE, $contents)) {
                $changes["$dir/".self::FUNCTIONS_FILE] = $contents;
            }
        }

        $own = $php->confDir($runtime->version(), 'cli');
        if ($changes === [] || ($own !== null && self::hasContents("$own/".self::FUNCTIONS_FILE, $contents))) {
            $this->components->error('Functions are still disabled although '.self::FUNCTIONS_FILE.' is in place. These loaded ini files set disable_functions:');
            foreach (array_filter($runtime->iniFiles(), fn (string $file) => self::setsDisableFunctions($file, nonEmpty: true)) as $file) {
                $this->line("  $file");
            }

            return self::FAILURE;
        }

        $this->components->info('These files re-enable the disabled functions for the PHP CLI:');
        $this->listChanges($changes);
        if (! $this->writable($changes)) {
            return self::FAILURE;
        }
        if (! $this->confirmed('Write these files?', 'apply')) {
            return self::SUCCESS;
        }

        if ($this->apply($changes) === null) {
            return self::FAILURE;
        }

        $this->components->info('Functions are re-enabled for the PHP CLI.');
        $this->components->warn('Run the command again to continue.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, ?string>  $changes  path => new contents, null to remove
     * @param  list<string>  $restarts  versions whose PHP-FPM service will be restarted
     */
    private function listChanges(array $changes, array $restarts = []): void
    {
        foreach ($changes as $path => $contents) {
            $this->line(($contents === null ? '  remove ' : '  write ').$path);
        }
        foreach ($restarts as $version) {
            $this->line("  restart php$version-fpm");
        }
        $this->newLine();
    }

    /**
     * @param  array<string, ?string>  $changes  path => new contents, null to remove
     */
    private function writable(array $changes): bool
    {
        $readOnly = array_filter(array_unique(array_map('dirname', array_keys($changes))), fn (string $dir) => ! is_writable($dir));
        if ($readOnly) {
            $this->components->error('Cannot write to these folders. Run as root (with sudo).');
            foreach ($readOnly as $dir) {
                $this->line("  $dir");
            }
        }

        return ! $readOnly;
    }

    /**
     * @param  array<string, ?string>  $changes  path => new contents, null to remove
     */
    private function apply(array $changes): ?FileTransaction
    {
        $transaction = new FileTransaction(date('Ymd-His'));

        try {
            foreach ($changes as $path => $contents) {
                $contents === null ? $transaction->delete($path) : $transaction->put($path, $contents);
            }
        } catch (RuntimeException $e) {
            $transaction->rollback();
            $this->components->error($e->getMessage());

            return null;
        }

        return $transaction;
    }

    private static function hasContents(string $path, string $contents): bool
    {
        return is_file($path) && file_get_contents($path) === $contents;
    }

    private static function setsDisableFunctions(string $file, bool $nonEmpty = false): bool
    {
        $pattern = $nonEmpty ? '/^\s*disable_functions\s*=\s*[^\s;]/m' : '/^\s*disable_functions\s*=/m';

        return is_readable($file) && preg_match($pattern, (string) file_get_contents($file)) === 1;
    }
}
