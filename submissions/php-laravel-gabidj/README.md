# PHP + Laravel Octane (Swoole)

The same API as [`php-swoole-mattsplat`](../php-swoole-mattsplat), but as a real Laravel app: routing, middleware,
exception handling and the database layer are Laravel's, and Octane runs it on Swoole.

| | |
|---|---|
| Language | PHP 8.3.6 (Ubuntu 24.04's `php8.3-cli` package), opcache + tracing JIT |
| Framework | Laravel 13.36.0 with Laravel Octane 2.21.0 (`composer.lock` pins every package) |
| Server | Swoole 6.2.3, built from the source tarball in `install.sh` (sha256 pinned) |
| SQLite driver | Laravel's `DB` facade over `pdo_sqlite` (`php8.3-sqlite3`), Ubuntu's SQLite 3.45.1 |
| **Nginx or direct** | **Direct**: serves `0.0.0.0:80` itself |

## Running it

```bash
sudo bash install.sh   # apt: php8.3 + extensions; Composer 2.10.3 (checksum pinned); compiles swoole.so
bash build.sh          # composer install --no-dev (classmap-authoritative autoloader) into vendor/
SQLITE_PATH=... JWT_SECRET=... HOST=0.0.0.0 PORT=80 bash start.sh
```

`start.sh` runs `php artisan octane:start` in the foreground. Swoole is not enabled globally: `start.sh` and the
`swoole.php_options` in `config/octane.php` load it with `-d extension=swoole`.

## Optimizations, and why

- **One worker, `SWOOLE_BASE` mode, no task workers, no worker recycling.** On one vCPU, more workers only add
  context switches and SQLite lock contention. In `SWOOLE_BASE` mode the epoll reactor runs in the worker itself, with no
  hand-off to a separate worker process. `--max-requests=0` stops Octane from restarting the worker every 500 requests.
- **Direct instead of Nginx**, so Nginx's CPU stays available to PHP. `max_connection` is 65535 and the backlog 4096.
  Swoole sets no idle timeout, so keep-alive connections stay open (checked: still open after 130 s idle).
  Octane's default 10 MB `socket_buffer_size` is lowered to 2 MB, which matters with ~15,000 connections.
- **Octane keeps the app booted** between requests, so a request costs a route match, the middleware and a query,
  not a framework boot. The DB connection also persists: Octane's `DisconnectFromDatabases` and `CollectGarbage`
  listeners are left off.
- **A stripped-down Laravel.** No `web`/`api` middleware groups (no sessions, cookies, CSRF, throttling), and the
  default global middleware stack is cleared. `config/` only holds what is used. JSON responses are built directly with
  `JsonResponse` and unescaped slashes/unicode. Errors go through one exception renderer that returns the spec's
  `{"error": ...}` bodies.
- **SQL**: the reference queries. A like is one statement,
  `INSERT ... SELECT ?, ? WHERE EXISTS (post) ON CONFLICT DO NOTHING`. The post-existence check runs only when nothing
  was inserted, to tell "already liked" from "no such post".
- **Durability**: `journal_mode=WAL`, `synchronous=NORMAL` (rule 6), autocommit. `INSERT ... RETURNING` is read to
  completion before the 201 is sent, so the row is committed first. Other pragmas: 64 MiB page cache, 256 MiB `mmap`,
  in-memory temp store, 5 s busy timeout (all in `config/database.php`).
- **No caching across requests** (rule 5): every request queries SQLite. Nothing is stored between requests.
- **JWTs are verified on every request** (`app/Http/Middleware/AuthenticateJwt.php`) with `hash_hmac` and a
  constant-time `hash_equals`. Only HS256 is accepted, `exp` is required and checked, and nothing is cached. Auth runs
  before the post id is validated, as the spec requires.
- **opcache + tracing JIT**, with `validate_timestamps=0` for the long-running process.
- Post bodies are trimmed of the same whitespace as JavaScript's `String.prototype.trim()`, and the 500-character limit
  uses `mb_strlen`, which counts characters like SQLite's `length(body)` CHECK.
- `APP_KEY` has a fixed placeholder default in `config/app.php`: nothing is encrypted, but Octane pre-warms the
  encrypter, which refuses to boot without a key.

## Results

Local run only: an Ubuntu 24.04 arm64 container limited to 1 CPU and 2 GB (Docker Desktop on a Mac), `nofile` 65535,
with k6 on the host through Docker's port forwarding. `bench/load.js` with the default 60 s ramp and a 90 s hold.

| Users | p95 | p99 | Failed requests | CPU (of 1 vCPU) | RSS |
|---|---|---|---|---|---|
| 2,500 | 4.7 ms | 7.7 ms | 0.02% (EOF) | ~22% | ~190 MB |

The few failures at 2,500 users are `EOF` on idle keep-alive connections. The server itself keeps idle connections
open (see above), so they most likely come from Docker Desktop's port proxy, but I haven't proven it. I didn't measure
higher user counts, and the droplet's vCPU is slower than this one, so expect lower numbers there.

## License

MIT, under the repo's [license](../../LICENSE).
