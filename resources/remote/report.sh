#!/usr/bin/env bash
# hcpc remote: report
# Prints one JSON object describing a Hestia user, for hcpc hestia:migrate-user.
# Usage: report.sh USER
set -Eeuo pipefail

hestia=${HESTIA:-/usr/local/hestia}
user=$1

setting() {
    local line
    line=$(grep -m 1 "^$1=" "$hestia/conf/hestia.conf" || true)
    line=${line#*=}
    line=${line//\'/}
    printf '%s' "${line//\"/}"
}

json_str() {
    local value=${1//\\/\\\\}
    printf '"%s"' "${value//\"/\\\"}"
}

list() {
    local out
    out=$("$hestia/bin/$1" "$user" json)
    [[ -n $out ]] || out='{}'
    printf '%s' "$out"
}

[[ -x $hestia/bin/v-backup-user ]] || { echo 'HestiaCP not found on the old server' >&2; exit 1; }
[[ -f $hestia/data/users/$user/user.conf ]] || { echo "Hestia user '$user' does not exist on the old server" >&2; exit 1; }

backup_dir=$(setting BACKUP)
backup_dir=${backup_dir:-/backup}

archives='{'
separator=''
while read -r size name; do
    archives+="$separator$(json_str "$name"):$size"
    separator=','
done < <(find "$backup_dir" -maxdepth 1 -type f -name "$user.*.tar" -printf '%s %f\n' 2> /dev/null)
archives+='}'

rsync=false
if command -v rsync > /dev/null; then
    rsync=true
fi

printf '{"backup_dir":%s,"backup_system":%s,"rsync":%s,"user":%s,"web":%s,"dns":%s,"mail":%s,"databases":%s,"archives":%s}\n' \
    "$(json_str "$backup_dir")" "$(json_str "$(setting BACKUP_SYSTEM)")" "$rsync" \
    "$(list v-list-user)" "$(list v-list-web-domains)" "$(list v-list-dns-domains)" \
    "$(list v-list-mail-domains)" "$(list v-list-databases)" "$archives"
