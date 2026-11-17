# HestiaCP Companion

A simple app to manage custom templates for HestiaCP.

## Installation
You can download the latest artifact from the project [releases.](https://github.com/SergiX44/hestiacp-companion/releases)

It requires PHP 8.4 or newer on the command line, with the `dom` (`php8.4-xml`) and `openssl` extensions.

## Usage

To list all the available commands:
```sh
php ./hcpc
```

### Reverse proxy templates

Create Nginx templates that proxy a domain to a service listening on a local port:
```sh
sudo php ./hcpc reverse-proxy arcane 3552
```

This writes `proxy-arcane.tpl` and `proxy-arcane.stpl`, proxying to `http://127.0.0.1:3552` (WebSockets included). Where they go depends on the Hestia web system:

| Hestia web system   | Template folder                                      | Panel field      |
|---------------------|------------------------------------------------------|------------------|
| Nginx standalone    | `/usr/local/hestia/data/templates/web/nginx/php-fpm` | Web Template     |
| Nginx + Apache      | `/usr/local/hestia/data/templates/web/nginx`         | Proxy Template   |

Existing templates are never overwritten unless you pass `--force`, which keeps a `.bak-<timestamp>` copy of each.

Then create the web domain in HestiaCP, enable SSL / Let's Encrypt, select `proxy-arcane` in the panel field above and check the config with `sudo nginx -t`.

### Sync a directory with rclone

Mirror a local directory to an [rclone](https://rclone.org) remote, safely including SQLite databases that are in use:
```sh
php ./hcpc rclone:sync-path /home/containers/stacks remote:backups/stacks          # dry run
php ./hcpc rclone:sync-path /home/containers/stacks remote:backups/stacks --apply
php ./hcpc rclone:sync-path /home/containers/stacks remote:backups/stacks --apply -- --bwlimit 8M -v
```

- Without `--apply` every rclone call runs with `--dry-run`. Flags after `--` are passed to every rclone call.
- It is an `rclone sync`: files missing locally are deleted on the remote. Symlinks are stored as `.rclonelink` files.
- SQLite databases, found by their file header, are copied with SQLite's online backup API, checked with `PRAGMA quick_check` and uploaded in place of the live file. Their `-wal`, `-shm` and `-journal` files are never uploaded, and leftovers on the remote are deleted.
- Only one sync per destination runs at a time.
- Needs `rclone` with the remote configured, and the PHP `sqlite3` extension. Run it as a user that can read every file in the directory.

For example, a nightly root cron job backing up rootless Podman containers:
```sh
for dir in stacks .config .local/share/containers/storage/volumes; do
    php ./hcpc rclone:sync-path "/home/containers/$dir" "remote:containers/$dir" --apply
done
# UID/GID mappings, needed to restore volume ownership.
rclone copyto /etc/subuid remote:containers/system/subuid
rclone copyto /etc/subgid remote:containers/system/subgid
```

### Production PHP settings

Apply production settings to every PHP version installed under `/etc/php`:
```sh
sudo php ./hcpc php:optimize
```

It manages two files in each version's `conf.d` folders. Files there load after `php.ini`, so they override HestiaCP's settings and survive its updates.

| File | Where | Content |
|------|-------|---------|
| `98-disabled_function.ini` | CLI always; PHP-FPM if you choose | `disable_functions =`, which re-enables the functions HestiaCP disables (`exec`, `proc_open`, `shell_exec`, …) |
| `99-prod-optim-php.ini` | CLI and PHP-FPM | the settings below. The CLI copy keeps `memory_limit = -1` and adds `opcache.enable_cli = 1` |

On a fresh HestiaCP server, the CLI itself has those functions disabled, so hcpc cannot test or restart PHP-FPM yet. The first run only writes the CLI `98-disabled_function.ini` files and asks you to run the command again.

The next run shows the settings and lets you change them. It then asks whether to re-enable the functions for websites too: the default is no, and on later runs it is your previous answer. Then it:

1. writes the files, keeping a `.bak-<timestamp>` copy of every file it replaces or removes;
2. runs `php-fpm<version> -t` for each version whose PHP-FPM files changed, and restores every file if one fails;
3. restarts those versions' running `php<version>-fpm` services, as listed in the plan it shows you. If a restart fails, it restores every file and restarts the affected services with the previous settings.

Re-enabling functions for websites turns `exec`, `proc_open` and the like back on for every site of every user on the server.

| Setting | Default | Option |
|---------|---------|--------|
| `memory_limit` (PHP-FPM only) | `512M` | `--memory-limit` |
| `realpath_cache_size` | `4096K` | `--realpath-cache-size` |
| `realpath_cache_ttl` | `120` | `--realpath-cache-ttl` |
| `opcache.memory_consumption` (MB) | `512` | `--opcache-memory-consumption` |
| `opcache.interned_strings_buffer` (MB) | `32` | `--opcache-interned-strings-buffer` |
| `opcache.max_accelerated_files` | `100000` | `--opcache-max-accelerated-files` |
| `opcache.validate_timestamps` | `1` | `--opcache-validate-timestamps` |
| `opcache.revalidate_freq` | `2` | `--opcache-revalidate-freq` |
| `opcache.jit` | `tracing` | `--opcache-jit` |
| `opcache.jit_buffer_size` | `64M` | `--opcache-jit-buffer-size` |

These settings are always the same: `display_errors = Off`, `log_errors = On`, `expose_php = Off`, `opcache.enable = 1` and `opcache.save_comments = 1` (Laravel reads attributes from comments). The values you choose are kept: the next run starts from the current `99-prod-optim-php.ini`.

Without prompts (`-n`), pass the values as options. Pass `--fpm-functions=yes|no` to change the website answer; without it, the current state is kept. Pass `--apply` to make the changes; without it, the command only shows what it would change:
```sh
sudo php ./hcpc php:optimize -n --opcache-memory-consumption=256 --fpm-functions=no --apply
```

The command warns about other `conf.d` files that set `disable_functions`, such as an older `99-laravel-production.ini`, because they can override its choice. Remove them if you no longer need them.

### Move an account from another HestiaCP server

Copy a whole Hestia user (sites, DNS, mail, databases and cron jobs) from an old server to this one, optionally under a new user name:
```sh
sudo php ./hcpc hestia:migrate-user srv1.example.com alice              # checks only
sudo php ./hcpc hestia:migrate-user srv1.example.com alice --apply
sudo php ./hcpc hestia:migrate-user srv1.example.com alice alice2 --apply
```

It backs up the user on the old server with `v-backup-user`, copies the archive with `rsync`, compares its SHA-256 with the original, and restores it here with `v-restore-user`. Hestia gives the restored sites this server's IP and updates their DNS zones.

#### One-time SSH setup
The command runs as root and connects to `root@<host>` (use `user@host` or an SSH alias to change that, and `--port` for another port). Root on this server needs a key the old server accepts, and the old server's host key:
```sh
sudo ssh-keygen -t ed25519                     # only if root has no key yet
sudo cat /root/.ssh/id_ed25519.pub             # add this to /root/.ssh/authorized_keys on the old server
sudo ssh root@srv1.example.com true            # accept the old server's host key
```
To use another key, pass `--identity=/path/to/key`, and accept the host key with `sudo ssh -i /path/to/key root@srv1.example.com true`.

#### Checks before anything changes
- The user exists on the old server, and not here (neither as a Hestia nor as a Linux user).
- `rsync` is installed on both servers, and there is room here for the archive twice (the copy and its unpacked contents) plus the restored files. The home folder is checked on its own when it is on another disk.
- None of the user's domains, aliases, DNS zones or mail domains belong to another user here. Otherwise Hestia would stop the restore half-way.
- PHP versions the sites use but this server lacks are reported: Hestia would switch those sites to the `default` template.
- If a new backup would make the old server delete the user's oldest backup, you are warned and offered the newest existing one.

Without `--apply`, the command only runs these checks. Interactive runs ask before migrating.

#### Reusing or resuming
`--archive=<name>` uses a backup that is already on the old server, for example last night's, instead of making a new one. An interrupted transfer is kept as `<archive>.partial` in this server's backup folder; run the command again with the same `--archive` to resume it.

#### What it does not do
It does not change DNS or delete anything: the archive stays on both servers. At the end it compares the number of web domains, DNS zones, mail domains and databases on both servers, and lists what to check before you switch over.
