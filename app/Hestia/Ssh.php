<?php

namespace App\Hestia;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class Ssh
{
    public function __construct(
        private readonly string $host,
        private readonly ?string $identity = null,
        private readonly int $port = 22,
    ) {}

    public function target(): string
    {
        return str_contains($this->host, '@') ? $this->host : "root@$this->host";
    }

    /**
     * @return list<string>
     */
    public function command(string ...$remote): array
    {
        return ['ssh', ...$this->options(), $this->target(), ...$remote];
    }

    /**
     * The value for rsync -e, which rsync splits like a shell would.
     */
    public function rsyncShell(): string
    {
        return implode(' ', ['ssh', ...array_map('escapeshellarg', $this->options())]);
    }

    /**
     * Runs resources/remote/<name>.sh on the server through bash, sending the script on stdin.
     *
     * @param  list<string>  $args
     */
    public function script(string $name, array $args, ?Closure $output = null): ProcessResult
    {
        return Process::forever()
            ->input(file_get_contents(base_path("resources/remote/$name.sh")))
            ->run($this->command('bash', '-s', '--', ...$args), $output);
    }

    /**
     * @return list<string>
     */
    private function options(): array
    {
        $options = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-p', (string) $this->port];
        if ($this->identity !== null) {
            array_push($options, '-i', $this->identity, '-o', 'IdentitiesOnly=yes');
        }

        return $options;
    }
}
