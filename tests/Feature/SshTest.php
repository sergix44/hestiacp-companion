<?php

use App\Hestia\Ssh;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('builds the ssh command with the default options', function () {
    expect((new Ssh('srv1.example.com'))->command('true'))->toBe([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-p', '22', 'root@srv1.example.com', 'true',
    ]);
});

it('adds the identity and keeps an explicit user', function () {
    expect((new Ssh('admin@srv1', '/home/s/.ssh/id_ed25519', 2222))->command('true'))->toBe([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-p', '2222',
        '-i', '/home/s/.ssh/id_ed25519', '-o', 'IdentitiesOnly=yes', 'admin@srv1', 'true',
    ]);
});

it('quotes options for rsync', function () {
    expect((new Ssh('srv1', '/home/s/my key'))->rsyncShell())->toBe(
        "ssh '-o' 'BatchMode=yes' '-o' 'ConnectTimeout=15' '-p' '22' '-i' '/home/s/my key' '-o' 'IdentitiesOnly=yes'"
    );
});

it('runs a remote script through bash on stdin', function () {
    Process::fake();

    (new Ssh('srv1'))->script('report', ['alice']);

    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, -5) === ['root@srv1', 'bash', '-s', '--', 'alice']
        && str_starts_with($process->input, "#!/usr/bin/env bash\n# hcpc remote: report"));
});
