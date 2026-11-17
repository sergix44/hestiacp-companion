#!/usr/bin/env bash
# hcpc remote: backup
# Backs up a Hestia user and prints the new archive's file name, for hcpc hestia:migrate-user.
# Usage: backup.sh USER
set -Eeuo pipefail

hestia=${HESTIA:-/usr/local/hestia}
user=$1

backup_dir=$(grep -m 1 '^BACKUP=' "$hestia/conf/hestia.conf" || true)
backup_dir=${backup_dir#*=}
backup_dir=${backup_dir//\'/}
backup_dir=${backup_dir//\"/}
backup_dir=${backup_dir:-/backup}

archives() {
    find "$backup_dir" -maxdepth 1 -type f -name "$user.*.tar" -printf '%f\n' | sort
}

# Archive names carry their timestamp, so the newest new name is this backup.
# Comparing names avoids relying on file times, which some filesystems round.
before=$(archives)
"$hestia/bin/v-backup-user" "$user" >&2
newest=$(comm -13 <(printf '%s\n' "$before") <(archives) | awk 'END { print }')
[[ -n $newest ]] || { echo 'Backup finished but no new archive was found' >&2; exit 1; }
printf '%s\n' "$newest"
