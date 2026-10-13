#!/usr/bin/env bash
# Runs once as root on a clean Ubuntu 24.04: installs PHP 8.3 (Ubuntu's own packages), Composer 2.10.3 (pinned
# phar, checksum verified) and builds the Swoole 6.2.3 extension from its source tarball (checksum pinned).
# Swoole is not enabled globally; start.sh and config/octane.php load it with `-d extension=swoole`.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates curl unzip build-essential autoconf pkg-config \
  php8.3-cli php8.3-dev php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-opcache

tmp="$(mktemp -d)"

COMPOSER_VERSION=2.10.3
COMPOSER_SHA256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
curl -fsSL -o "$tmp/composer.phar" "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"
echo "$COMPOSER_SHA256  $tmp/composer.phar" | sha256sum -c -
install -m 0755 "$tmp/composer.phar" /usr/local/bin/composer

SWOOLE_VERSION=6.2.3
SWOOLE_SHA256=dde8d2a4a6b5c5cd418aedd8561760baad59767a2f8a963b2c0e9eb9c86f4c8d
curl -fsSL -o "$tmp/swoole.tar.gz" "https://github.com/swoole/swoole-src/archive/refs/tags/v$SWOOLE_VERSION.tar.gz"
echo "$SWOOLE_SHA256  $tmp/swoole.tar.gz" | sha256sum -c -
tar -xzf "$tmp/swoole.tar.gz" -C "$tmp"
cd "$tmp/swoole-src-$SWOOLE_VERSION"
phpize
# Only the HTTP server is used: no OpenSSL, HTTP/2 client libs, curl/sqlite coroutine hooks or brotli.
./configure --disable-brotli --disable-zstd
make -j"$(nproc)"
make install
cd /
rm -rf "$tmp"
php -d extension=swoole -r 'echo "PHP ", PHP_VERSION, ", Swoole ", phpversion("swoole"), ", SQLite ", (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), "\n";'
