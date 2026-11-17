<?php

use App\Commands\PhpOptimizeCommand;
use App\Php\ProductionSettings;
use App\Php\Runtime;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const PHP_ROOT = __DIR__.'/../Tmp/php';

/**
 * @param  array<string, list<string>>  $versions  e.g. ['8.4' => ['cli', 'fpm']]
 */
function phpTree(array $versions): void
{
    foreach ($versions as $version => $sapis) {
        foreach ($sapis as $sapi) {
            (new Filesystem)->ensureDirectoryExists(PHP_ROOT."/$version/$sapi/conf.d");
        }
    }

    config(['hestia.php_path' => PHP_ROOT]);
}

function fakeRuntime(array $disabled = [], string $version = '8.4', array $iniFiles = []): void
{
    app()->instance(Runtime::class, new class($disabled, $version, $iniFiles) extends Runtime
    {
        public function __construct(private array $disabled, private string $version, private array $iniFiles) {}

        public function disabledFunctions(): array
        {
            return $this->disabled;
        }

        public function version(): string
        {
            return $this->version;
        }

        public function iniFiles(): array
        {
            return $this->iniFiles;
        }
    });
}

/**
 * Fakes php-fpm and systemctl. Each call starts a fresh recording.
 */
function fakeSystem(array $failing = [], array $missing = [], array $active = ['8.3', '8.4'], array $restartFailing = []): void
{
    Process::swap(new Factory);
    processLog()->exchangeArray([]);
    Process::fake(function (PendingProcess $process) use ($failing, $missing, $active, $restartFailing) {
        $command = $process->command;
        processLog()->append($command);
        if (preg_match('/^php-fpm(\d+\.\d+)$/', $command[0], $match)) {
            return Process::result(exitCode: match (true) {
                in_array($match[1], $failing, true) => 1,
                in_array($match[1], $missing, true) => 127,
                default => 0,
            });
        }

        $version = preg_match('/^php(\d+\.\d+)-fpm$/', end($command), $match) ? $match[1] : null;

        return match ($command[1] ?? null) {
            'is-active' => Process::result(exitCode: in_array($version, $active, true) ? 0 : 3),
            'restart' => Process::result(exitCode: in_array($version, $restartFailing, true) ? 1 : 0),
            default => Process::result(),
        };
    });
}

function processLog(): ArrayObject
{
    static $log = new ArrayObject;

    return $log;
}

function ran(array $command): bool
{
    return in_array($command, processLog()->getArrayCopy(), true);
}

function standardTree(): void
{
    phpTree(['8.3' => ['cli', 'fpm'], '8.4' => ['cli', 'fpm']]);
    fakeRuntime();
}

function confFile(string $version, string $sapi, string $file): string
{
    return PHP_ROOT."/$version/$sapi/conf.d/$file";
}

afterEach(fn () => (new Filesystem)->deleteDirectory(PHP_ROOT));

it('reports the running PHP version and its loaded ini file', function () {
    $runtime = new Runtime;

    expect($runtime->version())->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
    if (php_ini_loaded_file()) {
        expect($runtime->iniFiles())->toContain(php_ini_loaded_file());
    }
});

it('re-enables functions for the CLI of every version and asks to run again', function () {
    phpTree(['8.3' => ['cli', 'fpm'], '8.4' => ['cli', 'fpm']]);
    fakeRuntime(['exec', 'proc_open']);
    Process::fake();

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Run the command again')
        ->assertExitCode(0);

    foreach (['8.3', '8.4'] as $version) {
        expect(file_get_contents(confFile($version, 'cli', '98-disabled_function.ini')))
            ->toBe(view('php.disabled-functions')->render());
    }
    expect(glob(PHP_ROOT.'/*/fpm/conf.d/*'))->toBe([]);
    Process::assertNothingRan();
});

it('only shows the plan without --apply when not interactive', function () {
    phpTree(['8.4' => ['cli', 'fpm']]);
    fakeRuntime(['exec']);

    $this->artisan('php:optimize', ['--no-interaction' => true])
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(0);

    expect(glob(PHP_ROOT.'/*/*/conf.d/*'))->toBe([]);
});

it('asks before writing when interactive', function () {
    phpTree(['8.4' => ['cli', 'fpm']]);
    fakeRuntime(['exec']);

    $this->artisan('php:optimize')
        ->expectsConfirmation('Write these files?', 'no')
        ->assertExitCode(0);

    expect(glob(PHP_ROOT.'/*/*/conf.d/*'))->toBe([]);
});

it('fails instead of looping when functions stay disabled', function () {
    phpTree(['8.4' => ['cli', 'fpm']]);
    file_put_contents(confFile('8.4', 'cli', '98-disabled_function.ini'), view('php.disabled-functions')->render());
    $culprit = confFile('8.4', 'cli', '99-zz-hardening.ini');
    $harmless = confFile('8.4', 'cli', '20-opcache.ini');
    file_put_contents($culprit, "disable_functions = exec\n");
    file_put_contents($harmless, "opcache.enable = 1\n");
    fakeRuntime(['exec'], '8.4', [$harmless, $culprit]);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain($culprit)
        ->doesntExpectOutputToContain($harmless)
        ->assertExitCode(1);
});

it('fails without write access', function () {
    phpTree(['8.4' => ['cli', 'fpm']]);
    fakeRuntime(['exec']);
    chmod(PHP_ROOT.'/8.4/cli/conf.d', 0555);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Run as root (with sudo).')
        ->assertExitCode(1);

    chmod(PHP_ROOT.'/8.4/cli/conf.d', 0755);
    expect(glob(PHP_ROOT.'/*/*/conf.d/*'))->toBe([]);
})->skip(fn () => posix_geteuid() === 0, 'needs a non-root user');

it('applies the default settings to every version', function () {
    standardTree();
    fakeSystem();

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);

    foreach (['8.3', '8.4'] as $version) {
        expect(file_get_contents(confFile($version, 'fpm', ProductionSettings::FILE)))->toBe(ProductionSettings::render(ProductionSettings::DEFAULTS, false))
            ->and(file_get_contents(confFile($version, 'cli', ProductionSettings::FILE)))->toBe(ProductionSettings::render(ProductionSettings::DEFAULTS, true))
            ->and(confFile($version, 'cli', '98-disabled_function.ini'))->toBeFile()
            ->and(confFile($version, 'fpm', '98-disabled_function.ini'))->not->toBeFile()
            ->and(ran(["php-fpm$version", '-t']))->toBeTrue()
            ->and(ran(['systemctl', 'restart', "php$version-fpm"]))->toBeTrue();
    }
});

it('takes values from options and keeps them on the next run', function () {
    standardTree();
    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true, '--opcache-memory-consumption' => '256'])->assertExitCode(0);

    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Nothing to change.')
        ->assertExitCode(0);

    expect(file_get_contents(confFile('8.4', 'fpm', ProductionSettings::FILE)))->toContain("opcache.memory_consumption = 256\n")
        ->and(glob(PHP_ROOT.'/*/*/conf.d/*.bak-*'))->toBe([]);
    Process::assertNothingRan();
});

it('rejects invalid values', function () {
    standardTree();
    fakeSystem();

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true, '--opcache-jit' => 'fast'])
        ->expectsOutputToContain('opcache.jit')
        ->assertExitCode(1);

    expect(glob(PHP_ROOT.'/*/*/conf.d/*'))->toBe([]);
});

it('asks for each value when the user wants to change them', function () {
    standardTree();
    fakeSystem();

    $command = $this->artisan('php:optimize')->expectsConfirmation('Change any value?', 'yes');
    foreach (ProductionSettings::DEFAULTS as $key => $default) {
        $key === 'opcache.jit'
            ? $command->expectsQuestion($key, 'fast')->expectsQuestion($key, 'off')
            : $command->expectsQuestion($key, $default);
    }
    $command->expectsConfirmation('Re-enable disabled functions for websites (PHP-FPM)?', 'no')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0)
        ->run();

    expect(file_get_contents(confFile('8.4', 'fpm', ProductionSettings::FILE)))->toContain("opcache.jit = off\n");
});

it('re-enables functions for websites only when asked', function () {
    standardTree();
    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true, '--fpm-functions' => 'yes'])->assertExitCode(0);
    expect(confFile('8.3', 'fpm', '98-disabled_function.ini'))->toBeFile()
        ->and(confFile('8.4', 'fpm', '98-disabled_function.ini'))->toBeFile();

    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->expectsOutputToContain('Nothing to change.');
    expect(confFile('8.4', 'fpm', '98-disabled_function.ini'))->toBeFile();

    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true, '--fpm-functions' => 'no'])->assertExitCode(0);
    expect(confFile('8.3', 'fpm', '98-disabled_function.ini'))->not->toBeFile()
        ->and(confFile('8.4', 'fpm', '98-disabled_function.ini'))->not->toBeFile()
        ->and(glob(PHP_ROOT.'/*/fpm/conf.d/98-disabled_function.ini.bak-*'))->toHaveCount(2);
});

it('defaults the website question to the current state', function () {
    standardTree();
    fakeSystem();
    file_put_contents(confFile('8.4', 'fpm', '98-disabled_function.ini'), view('php.disabled-functions')->render());

    // Real keystrokes: Enter for "Change any value?" and the website question, then "yes" to apply.
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "\n\nyes\n");
    rewind($stream);
    $input = new ArrayInput([]);
    $input->setStream($stream);
    $command = app(PhpOptimizeCommand::class);
    $command->setLaravel(app());

    expect($command->run($input, new BufferedOutput))->toBe(0)
        ->and(confFile('8.4', 'fpm', '98-disabled_function.ini'))->toBeFile()
        ->and(confFile('8.3', 'fpm', '98-disabled_function.ini'))->toBeFile();
});

it('rolls back when php-fpm -t fails', function () {
    standardTree();
    file_put_contents(confFile('8.4', 'fpm', ProductionSettings::FILE), "opcache.jit = off\n");
    fakeSystem(failing: ['8.4']);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('php-fpm8.4 -t failed')
        ->assertExitCode(1);

    expect(file_get_contents(confFile('8.4', 'fpm', ProductionSettings::FILE)))->toBe("opcache.jit = off\n")
        ->and(glob(PHP_ROOT.'/*/*/conf.d/*'))->toBe([confFile('8.4', 'fpm', ProductionSettings::FILE)])
        ->and(ran(['systemctl', 'restart', 'php8.3-fpm']))->toBeFalse()
        ->and(ran(['systemctl', 'restart', 'php8.4-fpm']))->toBeFalse();
});

it('skips the config test when php-fpm is not installed', function () {
    standardTree();
    fakeSystem(missing: ['8.3']);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('php-fpm8.3 not found')
        ->assertExitCode(0);

    expect(confFile('8.3', 'fpm', ProductionSettings::FILE))->toBeFile();
});

it('restarts only running PHP-FPM services', function () {
    standardTree();
    fakeSystem(active: ['8.4']);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);

    expect(ran(['systemctl', 'restart', 'php8.4-fpm']))->toBeTrue()
        ->and(ran(['systemctl', 'restart', 'php8.3-fpm']))->toBeFalse();
});

it('reports a failed restart', function () {
    standardTree();
    fakeSystem(restartFailing: ['8.4']);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Could not restart php8.4-fpm')
        ->assertExitCode(1);
});

it('handles CLI-only versions', function () {
    phpTree(['8.3' => ['cli'], '8.4' => ['cli', 'fpm']]);
    fakeRuntime();
    fakeSystem();

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);

    expect(confFile('8.3', 'cli', ProductionSettings::FILE))->toBeFile()
        ->and(confFile('8.3', 'cli', '98-disabled_function.ini'))->toBeFile()
        ->and(PHP_ROOT.'/8.3/fpm')->not->toBeDirectory()
        ->and(ran(['php-fpm8.3', '-t']))->toBeFalse()
        ->and(ran(['systemctl', 'is-active', '--quiet', 'php8.3-fpm']))->toBeFalse();
});

it('warns about other files that set disable_functions', function () {
    standardTree();
    fakeSystem();
    $legacy = confFile('8.4', 'fpm', '99-laravel-production.ini');
    file_put_contents($legacy, "disable_functions =\nmemory_limit = 512M\n");

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('may override')
        ->expectsOutputToContain($legacy)
        ->assertExitCode(0);

    expect(file_get_contents($legacy))->toBe("disable_functions =\nmemory_limit = 512M\n");
});

it('leaves PHP-FPM alone when only CLI files change', function () {
    standardTree();
    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);
    unlink(confFile('8.4', 'cli', ProductionSettings::FILE));

    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);

    expect(confFile('8.4', 'cli', ProductionSettings::FILE))->toBeFile()
        ->and(processLog()->getArrayCopy())->toBe([]);
});

it('only tests and restarts the PHP-FPM versions that changed', function () {
    standardTree();
    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);
    unlink(confFile('8.4', 'fpm', ProductionSettings::FILE));

    fakeSystem();
    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])->assertExitCode(0);

    expect(ran(['php-fpm8.4', '-t']))->toBeTrue()
        ->and(ran(['systemctl', 'restart', 'php8.4-fpm']))->toBeTrue()
        ->and(ran(['php-fpm8.3', '-t']))->toBeFalse()
        ->and(ran(['systemctl', 'restart', 'php8.3-fpm']))->toBeFalse();
});

it('lists the services it will restart in the plan', function () {
    standardTree();
    fakeSystem(active: ['8.4']);

    $this->artisan('php:optimize', ['--no-interaction' => true])
        ->expectsOutputToContain('restart php8.4-fpm')
        ->doesntExpectOutputToContain('restart php8.3-fpm')
        ->assertExitCode(0);

    expect(ran(['systemctl', 'restart', 'php8.4-fpm']))->toBeFalse();
});

it('rolls back and restarts with the previous settings when a restart fails', function () {
    standardTree();
    file_put_contents(confFile('8.4', 'fpm', ProductionSettings::FILE), "opcache.jit = off\n");
    fakeSystem(restartFailing: ['8.4']);

    $this->artisan('php:optimize', ['--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Could not restart php8.4-fpm')
        ->expectsOutputToContain('previous settings')
        ->assertExitCode(1);

    $restarts = array_filter(processLog()->getArrayCopy(), fn (array $command) => ($command[1] ?? null) === 'restart');
    expect(file_get_contents(confFile('8.4', 'fpm', ProductionSettings::FILE)))->toBe("opcache.jit = off\n")
        ->and(confFile('8.3', 'fpm', ProductionSettings::FILE))->not->toBeFile()
        ->and(array_values($restarts))->toBe([
            ['systemctl', 'restart', 'php8.3-fpm'],
            ['systemctl', 'restart', 'php8.4-fpm'],
            ['systemctl', 'restart', 'php8.3-fpm'],
            ['systemctl', 'restart', 'php8.4-fpm'],
        ]);
});
