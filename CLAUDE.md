# CLAUDE.md

Independent package (`spatie/flare-daemon`). Own composer.json, own autoload (`Spatie\FlareDaemon\`), own tests.

## Commands

```bash
composer test                      # Pest tests
composer test --filter=ServerTest  # Single test file
composer analyse                   # PHPStan level 8
composer format                    # Pint code formatting
bash build.sh                      # Build daemon.phar
php src/daemon.php                 # Run daemon locally (needs composer install first)
php src/daemon.php --verbose       # Run with per-request DEBUG logging
php src/daemon.php --test          # Run with NullUpstream (no HTTP out), prints stats every 5s
```

## Smoke-testing a build

```bash
bash build.sh
php daemon.phar &
curl -s http://127.0.0.1:8787/health   # should return {"status":"ok"}
kill %1
```

## Smoke-testing with a real API key

Start the daemon (with `--verbose` for full per-payload logs), then run `tests/test.sh` with your Flare API key:

```bash
php src/daemon.php --verbose &
bash tests/test.sh YOUR_API_KEY              # uses http://127.0.0.1:8787 by default
bash tests/test.sh -u http://localhost:9000 YOUR_API_KEY  # custom daemon URL
kill %1
```

The script sends a normal error payload (no `X-Flare-Test` header) to exercise the real buffering/flushing path, then polls `/status` to confirm the buffer drained.

## Docker

```bash
docker build -t flare-daemon .
docker run -p 8787:8787 flare-daemon
```

## Testing patterns

- Tests use ReactPHP's event loop — everything is async. Use `waitFor()` / `waitUntil()` for timing, never `sleep()`.
- `createUpstreamFixture($handler)` spins up a fake upstream HTTP server. Returns `base_url` and `requests` ArrayObject.
- `createDaemonFixture($baseUrl, $options)` spins up a full daemon on an ephemeral port. Returns `daemon_url`, `client`, `ingest`, `server`, `quota_state`.
- Fixtures auto-clean via `rememberCloser()` / `rememberShutdown()` in afterEach — don't close them manually.
- Feature tests live in `tests/Feature/`, unit tests in `tests/Unit/`.

## Key design decisions

- Buffers are per API key × entity type (errors/traces/logs). Not a single shared queue.
- Test payloads (`X-Flare-Test: 1`) force an immediate flush and return the upstream response directly. Normal payloads return JSON `202 {"status":"accepted"}` immediately.
- 429 pauses that (key, type); 403 pauses all types for that key permanently. Normal items are dropped on pause, test items are kept.
- Upstream sends one payload per request (no batch API in v1).
- The errors CF worker is a transparent proxy — it passes through whatever status the real Flare API returns (currently 204). Traces/logs workers return a hardcoded 201. The daemon must treat any 2xx as success, not maintain an allowlist.

## Distribution / Positioning

- The daemon is intended to be installed by default through the shared Flare PHP client dependency chain, but not enabled automatically.
- Daemon transport is opt-in at runtime even when this package is present.
- The daemon is framework-agnostic. Laravel may add wrapper commands, but the daemon must work for standalone PHP and Laravel integrations.
- Composer/vendor-bin is the default application install path. PHAR and Docker are additional operator-facing distribution channels.
- Versioning should be treated as daemon-repo driven: one daemon version stream, mirrored across Packagist tags, PHAR releases, and Docker tags.
- Keep this file brief. Use `README.md` for fuller packaging and versioning context.

## Load testing

k6 must be installed (`brew install k6`). Start the daemon in test mode, then run k6 in a second terminal:

```bash
php src/daemon.php --test &
k6 run loadtest/loadtest.js
kill %1
```

`--test` mode uses `NullUpstream` — payloads are accepted and flushed through the full pipeline but no HTTP leaves the process. Stats (received, buffered, forwarded, pending, memory) are printed every 5 seconds.

The `/status` endpoint exposes lifetime counters: `total_received` (all 202 responses), `total_buffered` (passed the pause check), `total_forwarded` (sent upstream successfully), and `total_dropped` (received minus forwarded).

The k6 script ramps from 1→200 VUs over 80 seconds, posting realistic error payloads to `/v1/errors`. Pass `DAEMON_URL` and `API_KEY` env vars to customize.

See `loadtest/README.md` for details on memory measurement and custom scenarios.

## After every code change

Run `composer test` and `composer analyse` after every code change. Both must pass before considering the change complete.

## Code style

Follow Spatie PHP guidelines: @~/.dotfiles/spatie-guidelines-claude.md
