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
