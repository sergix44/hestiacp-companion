<?php

namespace App\Commands;

use App\Commands\Concerns\ConfirmsChanges;
use App\Hestia\HestiaConf;
use App\Hestia\MigrationFailed;
use App\Hestia\RemoteAccount;
use App\Hestia\Ssh;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class HestiaMigrateUserCommand extends Command
{
    use ConfirmsChanges;

    private const USER_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const REMOTE_DIR_PATTERN = '/^\/[A-Za-z0-9._\/-]+$/';

    protected $signature = 'hestia:migrate-user
        {host : The old server: a host name, user@host or an SSH alias}
        {user : The Hestia user to move}
        {destination? : The user name on this server (default: the same)}
        {--identity= : SSH private key for the old server}
        {--port=22 : SSH port of the old server}
        {--archive= : Use this existing backup on the old server instead of making a new one}
        {--apply : Migrate without asking}';

    protected $description = 'Move a HestiaCP account from another server to this one';

    public function handle(): int
    {
        try {
            return $this->migrate();
        } catch (MigrationFailed $e) {
            $this->components->error($e->getMessage());
            foreach ($e->details as $line) {
                $this->line("  $line");
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());
        }

        return self::FAILURE;
    }

    private function migrate(): int
    {
        $user = $this->argument('user');
        $destination = $this->argument('destination') ?? $user;
        foreach ([$user, $destination] as $name) {
            if (! preg_match(self::USER_PATTERN, $name)) {
                throw new MigrationFailed("Invalid Hestia user name: $name");
            }
        }

        $archive = $this->option('archive');
        if ($archive !== null && ! self::isArchiveOf($user, $archive)) {
            throw new MigrationFailed("Invalid archive name: $archive", ["Expected $user.YYYY-MM-DD_HH-MM-SS.tar"]);
        }

        $port = $this->option('port');
        if (! ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new MigrationFailed('The SSH port must be a number between 1 and 65535.');
        }
        $ssh = new Ssh($this->argument('host'), $this->option('identity'), (int) $port);

        $backupDir = $this->checkThisServer($destination);
        $account = $this->readOldServer($ssh, $user);
        $this->checkOldServer($account, $archive);
        $this->checkDomains($account);
        $this->warnAboutDifferences($account, $archive);
        $restoredBytes = $account->diskMb() * 1024 * 1024;
        $this->checkDiskSpace($backupDir, $archive !== null ? $account->archives()[$archive] : $restoredBytes, $restoredBytes);

        $this->showPlan($ssh, $user, $destination, $archive, $account);
        if (! $this->confirmed("Migrate $user from {$this->argument('host')} now?", 'migrate')) {
            return self::SUCCESS;
        }

        if ($archive === null) {
            $archive = $this->backUp($ssh, $user);
            $this->checkDiskSpace($backupDir, $this->remoteSize($ssh, $account, $archive), $restoredBytes, [
                "Free some space, then run the command again with --archive=$archive to reuse this backup.",
            ]);
        }

        $local = "$backupDir/$archive";
        if (file_exists($local)) {
            throw new MigrationFailed("This server already has $archive; refusing to overwrite it.", [
                $local,
                'If it is the archive you want, restore it directly:',
                'sudo '.config('hestia.bin')."/v-restore-user $destination $archive",
            ]);
        }

        $this->transfer($ssh, $account, $archive, $local);
        $this->verify($ssh, $account, $archive, $local);
        $this->restore($destination, $archive);
        $this->report($ssh, $account, $user, $destination, $archive, $local);

        return self::SUCCESS;
    }

    private function backUp(Ssh $ssh, string $user): string
    {
        $this->components->info("[1/4] Backing up $user on {$ssh->target()} …");

        // backup.sh prints Hestia's progress on stderr and only the archive name on stdout.
        $result = $ssh->script('backup', [$user], function (string $type, string $output) {
            if ($type === 'err') {
                $this->output->write($output);
            }
        });
        if ($result->failed()) {
            throw new MigrationFailed("The backup on the old server failed (exit code {$result->exitCode()}).");
        }

        $archive = trim($result->output());
        if (! self::isArchiveOf($user, $archive)) {
            throw new MigrationFailed('Unexpected archive name from the old server.', [
                $archive,
                'If the old server made a backup, reuse it with --archive=<name> instead of making another one.',
            ]);
        }

        return $archive;
    }

    private function remoteSize(Ssh $ssh, RemoteAccount $account, string $archive): int
    {
        $result = Process::forever()->run($ssh->command('stat', '-c', '%s', '--', "{$account->backupDir()}/$archive"));
        if ($result->failed() || ! ctype_digit(trim($result->output()))) {
            throw new MigrationFailed("Cannot read the size of $archive on the old server.");
        }

        return (int) trim($result->output());
    }

    private function transfer(Ssh $ssh, RemoteAccount $account, string $archive, string $local): void
    {
        $this->components->info("[2/4] Transferring $archive …");

        $result = Process::forever()->run([
            // Owned by root and private: the archive holds databases, mail and keys of the account.
            'rsync', '-t', '--perms', '--chmod=F600', '--partial', '--info=progress2', '-e', $ssh->rsyncShell(),
            "{$ssh->target()}:{$account->backupDir()}/$archive", "$local.partial",
        ], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            throw new MigrationFailed("The transfer failed (rsync exit code {$result->exitCode()}). The partial copy is kept:", [
                "$local.partial",
                "Run the command again with --archive=$archive to resume it.",
            ]);
        }
    }

    private function verify(Ssh $ssh, RemoteAccount $account, string $archive, string $local): void
    {
        $this->components->info('[3/4] Checking SHA-256 …');

        $remote = Process::forever()->run($ssh->command('sha256sum', '--', "{$account->backupDir()}/$archive"));
        $remoteHash = strtok(trim($remote->output()), ' ');
        $localHash = hash_file('sha256', "$local.partial");

        if ($remote->failed() || $remoteHash !== $localHash) {
            @unlink("$local.partial");

            throw new MigrationFailed('The SHA-256 of the copy does not match the old server, so the copy was deleted.', [
                "Run the command again with --archive=$archive to download it again.",
            ]);
        }

        chmod("$local.partial", 0600);
        rename("$local.partial", $local);
        $this->components->twoColumnDetail('SHA-256', $localHash);
    }

    private function restore(string $destination, string $archive): void
    {
        $this->components->info("[4/4] Restoring $destination from $archive …");

        // Hestia extracts folders that the site users must be able to read.
        umask(0022);
        $restore = config('hestia.bin').'/v-restore-user';
        $result = Process::forever()->run([$restore, $destination, $archive], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            throw new MigrationFailed("The restore failed (exit code {$result->exitCode()}). Hestia may have created '$destination' partially. Remove it, then restore again:", [
                'sudo '.config('hestia.bin')."/v-delete-user $destination",
                "sudo $restore $destination $archive",
            ]);
        }
    }

    private function report(Ssh $ssh, RemoteAccount $account, string $user, string $destination, string $archive, string $local): void
    {
        $lists = ['web' => 'v-list-web-domains', 'dns' => 'v-list-dns-domains', 'mail' => 'v-list-mail-domains', 'databases' => 'v-list-databases'];
        $labels = ['web' => 'Web domains', 'dns' => 'DNS domains', 'mail' => 'Mail domains', 'databases' => 'Databases'];
        $old = $account->counts();

        $rows = [];
        $differs = false;
        foreach ($lists as $key => $command) {
            $new = count(json_decode(Process::run([config('hestia.bin')."/$command", $destination, 'json'])->output(), true) ?: []);
            $differs = $differs || $new !== $old[$key];
            $rows[] = [$labels[$key], $old[$key], $new];
        }

        $this->newLine();
        $this->table(['', 'Old server', 'This server'], $rows);
        if ($differs) {
            $this->components->warn('The restored account differs from the old server; check the restore output above.');
        }

        $this->components->info("Migrated $user from {$ssh->target()} to $destination.");
        $this->line('Before switching over:');
        $this->line('  - Point DNS for these domains to this server: '.(implode(', ', $account->domainNames()) ?: 'none'));
        $this->line('  - Check any PHP version warnings above, then test each site.');
        $this->line('  - Test mail and database access.');
        $this->line('The archive is kept on both servers:');
        $this->line("  $local");
        $this->line("  {$ssh->target()}:{$account->backupDir()}/$archive");
    }

    /**
     * @return string this server's backup folder
     */
    private function checkThisServer(string $destination): string
    {
        if (! is_executable(config('hestia.bin').'/v-restore-user')) {
            throw new MigrationFailed('HestiaCP was not found on this server.');
        }

        $backupDir = (new HestiaConf(config('hestia.conf')))->get('BACKUP', '/backup');
        if (! is_dir($backupDir) || ! is_writable($backupDir)) {
            throw new MigrationFailed('Cannot write to the backup folder. Run as root (with sudo).', [$backupDir]);
        }

        if (is_file(config('hestia.users_path')."/$destination/user.conf")) {
            throw new MigrationFailed("Hestia user '$destination' already exists here.");
        }
        if (Process::run(['getent', 'passwd', $destination])->successful()) {
            throw new MigrationFailed("Linux user '$destination' already exists here.");
        }

        if (Process::run(['ssh', '-V'])->failed()) {
            throw new MigrationFailed('ssh is not installed: apt install openssh-client');
        }
        if (Process::run(['rsync', '--version'])->failed()) {
            throw new MigrationFailed('rsync is not installed: apt install rsync');
        }

        return $backupDir;
    }

    private function readOldServer(Ssh $ssh, string $user): RemoteAccount
    {
        $this->components->info("Checking {$ssh->target()} …");
        $result = $ssh->script('report', [$user]);

        if ($result->exitCode() === 255) {
            $identity = $this->option('identity') !== null ? "-i {$this->option('identity')} " : '';
            $port = (int) $this->option('port') !== 22 ? "-p {$this->option('port')} " : '';

            throw new MigrationFailed("Cannot connect to {$ssh->target()}.", array_values(array_filter([
                trim($result->errorOutput()),
                "Check that this works first (it also accepts the server's host key): sudo ssh $identity$port{$ssh->target()} true",
            ])));
        }
        if ($result->failed()) {
            throw new MigrationFailed(trim($result->errorOutput()) ?: 'Checking the old server failed.');
        }

        return RemoteAccount::fromJson($result->output());
    }

    private function checkOldServer(RemoteAccount $account, ?string $archive): void
    {
        if (! $account->hasRsync()) {
            throw new MigrationFailed('rsync is not installed on the old server: apt install rsync');
        }
        if (! preg_match(self::REMOTE_DIR_PATTERN, $account->backupDir())) {
            throw new MigrationFailed('Unexpected backup folder on the old server.', [$account->backupDir()]);
        }

        $archives = array_keys($account->archives());
        if ($archive !== null && ! in_array($archive, $archives, true)) {
            throw new MigrationFailed("The old server has no archive $archive. Its archives:", $archives ?: ['(none)']);
        }
        if ($archive === null && ! $account->keepsLocalBackups()) {
            throw new MigrationFailed(
                'The old server only keeps backups on a remote host, so a new backup would not stay there to copy.',
                $archives
                    ? ['Use one of its existing archives with --archive=<name>:', ...$archives]
                    : ['Add "local" to its backup systems, or make a local backup there and pass it with --archive=<name>.'],
            );
        }
    }

    /**
     * v-search-domain-owner ignores web aliases, so the users' Hestia config is read directly.
     */
    private function checkDomains(RemoteAccount $account): void
    {
        $owners = [];
        foreach (['web', 'dns', 'mail'] as $type) {
            foreach (glob(config('hestia.users_path')."/*/$type.conf") ?: [] as $file) {
                $owner = basename(dirname($file));
                preg_match_all("/\\b(?:DOMAIN|ALIAS)='([^']*)'/", (string) file_get_contents($file), $matches);
                foreach (explode(',', implode(',', $matches[1])) as $name) {
                    if ($name !== '') {
                        $owners[$name] ??= $owner;
                    }
                }
            }
        }

        $taken = [];
        foreach ($account->domainNames() as $domain) {
            if (isset($owners[$domain])) {
                $taken[] = "$domain ($owners[$domain])";
            }
        }

        if ($taken) {
            throw new MigrationFailed('These domains already belong to users on this server:', $taken);
        }
    }

    private function warnAboutDifferences(RemoteAccount $account, ?string $archive): void
    {
        $installed = json_decode(Process::run([config('hestia.bin').'/v-list-web-templates-backend', 'json'])->output(), true) ?: [];
        foreach ($account->backends() as $domain => $backend) {
            if ($backend !== '' && ! in_array($backend, $installed, true)) {
                $this->components->warn("$domain uses $backend, which is not installed here; Hestia will use default.");
            }
        }

        $limit = $account->backupsLimit();
        if ($archive === null && $limit > 0 && count($account->archives()) >= $limit) {
            $this->components->warn("The old server keeps $limit backups of this user, so a new backup deletes the oldest:");
            $this->line('  '.$account->oldestArchive());
            $this->line('  To reuse the newest backup instead, pass --archive='.$account->newestArchive());
            $this->newLine();
        }
    }

    /**
     * The restore needs the archive, its unpacked copy and the restored files in the home folder.
     *
     * @param  list<string>  $advice
     */
    private function checkDiskSpace(string $backupDir, int $archiveBytes, int $restoredBytes, array $advice = []): void
    {
        $home = config('hestia.home_path');
        $lines = array_slice(preg_split('/\R/', trim(Process::run(['df', '--output=avail,target', '-B1', $backupDir, $home])->output())), 1);
        [$backupFree, $backupMount] = preg_split('/\s+/', trim($lines[0] ?? '0 ?'), 2) + [1 => '?'];
        [$homeFree, $homeMount] = preg_split('/\s+/', trim($lines[1] ?? $lines[0] ?? '0 ?'), 2) + [1 => '?'];

        $needs = $backupMount === $homeMount
            ? [$backupDir => [(int) $backupFree, 2 * $archiveBytes + $restoredBytes]]
            : [$backupDir => [(int) $backupFree, 2 * $archiveBytes], $home => [(int) $homeFree, $restoredBytes]];

        foreach ($needs as $dir => [$free, $needed]) {
            if ($free < $needed) {
                throw new MigrationFailed(sprintf(
                    'Not enough disk space: %d MB free, about %d MB needed in:',
                    intdiv($free, 1024 ** 2), (int) ceil($needed / 1024 ** 2),
                ), [$dir, ...$advice]);
            }
        }
    }

    private function showPlan(Ssh $ssh, string $user, string $destination, ?string $archive, RemoteAccount $account): void
    {
        $counts = $account->counts();
        $this->components->twoColumnDetail('From', "$user on {$ssh->target()}");
        $this->components->twoColumnDetail('To', "$destination on this server");
        $this->components->twoColumnDetail('Archive', $archive ?? 'a new backup');
        $this->components->twoColumnDetail('Contents', "{$counts['web']} web, {$counts['dns']} DNS, {$counts['mail']} mail domains, {$counts['databases']} databases");
        $this->newLine();
    }

    private static function isArchiveOf(string $user, string $archive): bool
    {
        return preg_match('/^'.preg_quote($user, '/').'\.\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.tar$/', $archive) === 1;
    }
}
