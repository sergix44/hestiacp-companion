<?php

namespace App\Hestia;

use RuntimeException;

/**
 * The account report printed by resources/remote/report.sh on the old server.
 */
final class RemoteAccount
{
    /**
     * @param  array<string, mixed>  $report
     */
    private function __construct(private readonly array $report) {}

    public static function fromJson(string $json): self
    {
        $report = json_decode($json, true);
        if (! is_array($report)) {
            throw new RuntimeException('Unexpected report from the old server');
        }

        return new self($report);
    }

    public function backupDir(): string
    {
        return (string) ($this->report['backup_dir'] ?? '');
    }

    public function keepsLocalBackups(): bool
    {
        return in_array('local', array_map('trim', explode(',', (string) ($this->report['backup_system'] ?? ''))), true);
    }

    public function hasRsync(): bool
    {
        return ($this->report['rsync'] ?? false) === true;
    }

    public function diskMb(): int
    {
        return (int) ($this->user()['U_DISK'] ?? 0);
    }

    public function backupsLimit(): int
    {
        return (int) ($this->user()['BACKUPS'] ?? 0);
    }

    /**
     * @return array<string, int> archive name => size in bytes, oldest first
     */
    public function archives(): array
    {
        $archives = array_map('intval', $this->report['archives'] ?? []);
        ksort($archives);

        return $archives;
    }

    public function newestArchive(): ?string
    {
        return array_key_last($this->archives());
    }

    public function oldestArchive(): ?string
    {
        return array_key_first($this->archives());
    }

    /**
     * @return array<string, string> web domain => PHP backend template
     */
    public function backends(): array
    {
        return array_map(fn (array $domain) => (string) ($domain['BACKEND'] ?? ''), $this->list('web'));
    }

    /**
     * @return list<string> every web domain and alias, DNS domain and mail domain
     */
    public function domainNames(): array
    {
        $names = [];
        foreach ($this->list('web') as $domain => $web) {
            $names[] = (string) $domain;
            foreach (explode(',', (string) ($web['ALIAS'] ?? '')) as $alias) {
                if (trim($alias) !== '') {
                    $names[] = trim($alias);
                }
            }
        }

        return array_values(array_unique([
            ...$names,
            ...array_map('strval', array_keys($this->list('dns'))),
            ...array_map('strval', array_keys($this->list('mail'))),
        ]));
    }

    /**
     * @return array{web: int, dns: int, mail: int, databases: int}
     */
    public function counts(): array
    {
        return [
            'web' => count($this->list('web')),
            'dns' => count($this->list('dns')),
            'mail' => count($this->list('mail')),
            'databases' => count($this->list('databases')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        $user = $this->report['user'] ?? [];

        return is_array($user) && $user !== [] ? (array) reset($user) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function list(string $key): array
    {
        $list = $this->report[$key] ?? [];

        return is_array($list) ? $list : [];
    }
}
