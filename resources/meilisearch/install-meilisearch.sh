#!/bin/sh
# Install Meilisearch and set up Exment for it, on Linux with systemd (Ubuntu, Debian, RHEL, Amazon Linux...).
#
#   cd /var/www/exment
#   sudo sh vendor/exceedone/exment/resources/meilisearch/install-meilisearch.sh
#
# It does, and can be run again (what is there is kept):
#   1. Meilisearch: the binary in /usr/local/bin, the user "meilisearch", /var/lib/meilisearch,
#      /etc/meilisearch.conf with a new master key, and the service "meilisearch" (127.0.0.1:7700 only)
#   2. Exment: php artisan exment:meili-setup (.env, tables, system settings, index)
#   3. the queue worker as the service "exment-meili-worker"
#   4. the scheduler in the crontab of the web user (php artisan schedule:run every minute)
#
# Options:
#   --exment-dir=DIR   the folder of Exment (default: the current folder)
#   --user=USER        the user the web server runs PHP as (default: the owner of storage/)
#   --php=PATH         the php command (default: php in PATH)
#   --version=VER      the Meilisearch version (default below)
#   --index=NAME       the index name (default: exment_global)
#   --no-cron          do not add the scheduler to the crontab
set -eu

MEILI_VERSION="v1.10.3"
EXMENT_DIR="$(pwd)"
WEB_USER=""
PHP_BIN=""
INDEX=""
ADD_CRON=1

for arg in "$@"; do
    case "$arg" in
        --exment-dir=*) EXMENT_DIR="${arg#*=}" ;;
        --user=*) WEB_USER="${arg#*=}" ;;
        --php=*) PHP_BIN="${arg#*=}" ;;
        --version=*) MEILI_VERSION="${arg#*=}" ;;
        --index=*) INDEX="${arg#*=}" ;;
        --no-cron) ADD_CRON=0 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg (see --help)" >&2; exit 1 ;;
    esac
done

say() { printf '\n== %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "run it with sudo (it installs services)."
command -v sudo >/dev/null 2>&1 || fail "the sudo command is needed (to run PHP as the web user)."
command -v systemctl >/dev/null 2>&1 || fail "systemd (systemctl) is needed. Without it, follow the manual steps of the manual."
[ -f "$EXMENT_DIR/artisan" ] || fail "no artisan in $EXMENT_DIR: run it in the folder of Exment, or give --exment-dir=."
EXMENT_DIR="$(cd "$EXMENT_DIR" && pwd)"

[ -n "$WEB_USER" ] || WEB_USER="$(stat -c %U "$EXMENT_DIR/storage" 2>/dev/null || true)"
[ -n "$WEB_USER" ] && [ "$WEB_USER" != "root" ] || fail "cannot tell the web user (storage/ belongs to root): give --user=www-data (or apache, nginx...)."
id "$WEB_USER" >/dev/null 2>&1 || fail "no user $WEB_USER."
WEB_GROUP="$(id -gn "$WEB_USER")"

[ -n "$PHP_BIN" ] || PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ] || fail "no php command: give --php=/usr/bin/php8.3 (the one the web server uses)."

as_web() { (cd "$EXMENT_DIR" && sudo -u "$WEB_USER" --preserve-env=MEILI_MASTER_KEY -- "$@"); }

case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=aarch64 ;;
    *) fail "Meilisearch has no build for $(uname -m)." ;;
esac

echo "Exment: $EXMENT_DIR (user $WEB_USER, $PHP_BIN)"

# ---------------------------------------------------------------- 1. Meilisearch
say "1/4 Meilisearch $MEILI_VERSION"
if [ -x /usr/local/bin/meilisearch ] && /usr/local/bin/meilisearch --version 2>/dev/null | grep -q "${MEILI_VERSION#v}"; then
    echo "already installed"
else
    url="https://github.com/meilisearch/meilisearch/releases/download/${MEILI_VERSION}/meilisearch-linux-${ARCH}"
    tmp="$(mktemp)"
    if command -v curl >/dev/null 2>&1; then curl -fsSL -o "$tmp" "$url"; else wget -q -O "$tmp" "$url"; fi
    chmod 755 "$tmp"
    "$tmp" --version >/dev/null || fail "the downloaded file does not run."
    # stop the old one before replacing the binary
    systemctl stop meilisearch 2>/dev/null || true
    mv "$tmp" /usr/local/bin/meilisearch
    /usr/local/bin/meilisearch --version
fi

id meilisearch >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d /var/lib/meilisearch meilisearch
mkdir -p /var/lib/meilisearch
chown meilisearch:meilisearch /var/lib/meilisearch
chmod 750 /var/lib/meilisearch

if [ -f /etc/meilisearch.conf ] && grep -q '^MEILI_MASTER_KEY=' /etc/meilisearch.conf; then
    echo "keeping /etc/meilisearch.conf (and its master key)"
else
    if command -v openssl >/dev/null 2>&1; then key="$(openssl rand -hex 16)"; else key="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"; fi
    umask 077
    cat > /etc/meilisearch.conf <<EOF
MEILI_DB_PATH=/var/lib/meilisearch/data.ms
MEILI_HTTP_ADDR=127.0.0.1:7700
MEILI_ENV=production
MEILI_MASTER_KEY=${key}
MEILI_NO_ANALYTICS=true
EOF
    umask 022
    echo "made /etc/meilisearch.conf with a new master key"
fi
chown root:root /etc/meilisearch.conf
chmod 600 /etc/meilisearch.conf

cat > /etc/systemd/system/meilisearch.service <<'EOF'
[Unit]
Description=Meilisearch search engine
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
EnvironmentFile=/etc/meilisearch.conf
WorkingDirectory=/var/lib/meilisearch
ExecStart=/usr/local/bin/meilisearch
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=/var/lib/meilisearch

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable meilisearch >/dev/null 2>&1
systemctl restart meilisearch

HOST="http://$(sed -n 's/^MEILI_HTTP_ADDR=//p' /etc/meilisearch.conf)"
up=0
for i in $(seq 1 30); do
    if command -v curl >/dev/null 2>&1; then
        curl -fs "$HOST/health" 2>/dev/null | grep -q available && up=1 && break
    else
        wget -q -O- "$HOST/health" 2>/dev/null | grep -q available && up=1 && break
    fi
    sleep 1
done
[ "$up" -eq 1 ] || fail "Meilisearch did not start: see journalctl -u meilisearch"
echo "running on $HOST"

# ---------------------------------------------------------------- 2. Exment
say "2/4 Exment (php artisan exment:meili-setup)"
if [ ! -d "$EXMENT_DIR/vendor/meilisearch/meilisearch-php" ]; then
    command -v composer >/dev/null 2>&1 || fail "meilisearch/meilisearch-php is not installed and there is no composer: run \"composer require meilisearch/meilisearch-php\" in $EXMENT_DIR."
    as_web composer require meilisearch/meilisearch-php --no-interaction
fi
# the key goes in the environment, not on the command line (which every user can see)
MEILI_MASTER_KEY="$(sed -n 's/^MEILI_MASTER_KEY=//p' /etc/meilisearch.conf)"
export MEILI_MASTER_KEY
set -- --host="$HOST" --no-interaction
[ -n "$INDEX" ] && set -- "$@" --index="$INDEX"
as_web "$PHP_BIN" artisan exment:meili-setup "$@"
unset MEILI_MASTER_KEY

# ---------------------------------------------------------------- 3. queue worker
say "3/4 Queue worker (exment-meili-worker)"
env_value() { sed -n "s/^$1=[\"']\{0,1\}\([^\"' #]*\).*/\1/p" "$EXMENT_DIR/.env" | tail -n 1; }
SYNC_QUEUE="$(env_value MEILISEARCH_SYNC_QUEUE)"; SYNC_QUEUE="${SYNC_QUEUE:-default}"
REINDEX_QUEUE="$(env_value MEILISEARCH_REINDEX_QUEUE)"; REINDEX_QUEUE="${REINDEX_QUEUE:-$SYNC_QUEUE}"
QUEUES="$SYNC_QUEUE"
[ "$REINDEX_QUEUE" != "$SYNC_QUEUE" ] && QUEUES="$SYNC_QUEUE,$REINDEX_QUEUE"
cat > /etc/systemd/system/exment-meili-worker.service <<EOF
[Unit]
Description=Exment queue worker (Meilisearch sync)
After=network.target meilisearch.service mysql.service mariadb.service

[Service]
User=${WEB_USER}
Group=${WEB_GROUP}
WorkingDirectory=${EXMENT_DIR}
ExecStart=${PHP_BIN} artisan queue:work --queue=${QUEUES} --max-time=3600 --tries=3
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable exment-meili-worker >/dev/null 2>&1
systemctl restart exment-meili-worker
sleep 2
systemctl is-active --quiet exment-meili-worker || fail "the worker did not start: see journalctl -u exment-meili-worker"
echo "running (queues: $QUEUES)"

# ---------------------------------------------------------------- 4. scheduler
say "4/4 Scheduler"
if [ "$ADD_CRON" -eq 0 ]; then
    echo "skipped (--no-cron): run \"$PHP_BIN $EXMENT_DIR/artisan schedule:run\" every minute yourself"
elif crontab -u "$WEB_USER" -l 2>/dev/null | grep -q "schedule:run"; then
    echo "already in the crontab of $WEB_USER"
else
    command -v crontab >/dev/null 2>&1 || fail "no crontab command: install cron, or run with --no-cron."
    { crontab -u "$WEB_USER" -l 2>/dev/null || true; echo "* * * * * cd $EXMENT_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"; } | crontab -u "$WEB_USER" -
    echo "added to the crontab of $WEB_USER"
fi

say "Done"
echo "Search with Meilisearch is on. Check it any time with: sudo -u $WEB_USER $PHP_BIN artisan exment:meili-health --failed"
echo "The master key is in /etc/meilisearch.conf (root only). Do not open port 7700 to the Internet."
