#!/usr/bin/env bash
# Runs the server in the foreground. Config: SQLITE_PATH, JWT_SECRET, HOST, PORT.
# Laravel Octane starts the Swoole server (php options are in config/octane.php): one worker in
# SWOOLE_BASE mode, no task workers, no worker recycling.
set -euo pipefail
cd "$(dirname "$0")"
export APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr OCTANE_SERVER=swoole
exec php -d extension=swoole -d memory_limit=-1 artisan octane:start \
  --server=swoole --host="${HOST:-127.0.0.1}" --port="${PORT:-3000}" \
  --workers=1 --task-workers=0 --max-requests=0
