# A daemon to report error reports, performances traces and logs to Flare

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/flare-daemon.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-daemon)
[![Tests](https://github.com/spatie/flare-daemon/actions/workflows/run-tests.yml/badge.svg)](https://github.com/spatie/flare-daemon/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/flare-daemon.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-daemon)

A long-running PHP process that accepts error reports, traces, and logs from your application over a local HTTP connection and forwards them to [Flare](https://flareapp.io) asynchronously. This removes Flare delivery from the critical path of your requests.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/flare-daemon.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/flare-daemon)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## How it works

```
PHP app ──HTTP──▸ daemon (local) ──HTTP──▸ Flare ingress
```

Payloads are buffered per API key and entity type and flushed immediately after being accepted. If the daemon is unreachable, the Flare client falls back to direct delivery automatically.

## Architecture

The daemon is a single PHP process built on ReactPHP's event loop:

- **HTTP server** — listens for local requests on the configured address
- **Ingest** — validates incoming payloads, routes them to the right buffer
- **Buffers** — per API key, per entity type (errors/traces/logs), in-memory only
- **Flush cycle** — a periodic timer (every 1s) checks buffer age and size thresholds
- **Upstream** — sends buffered payloads to Flare ingress over HTTP
- **Quota state** — tracks 429/403 responses and pauses ingestion per key/type
- **Test payloads** — bypass the buffer entirely, make a synchronous upstream request, and return the upstream response to the caller
- **Composer.lock watcher** — optional periodic timer that triggers graceful shutdown on file changes
- **Signal handlers** — SIGINT/SIGTERM trigger graceful shutdown (drain buffers, then stop)

## Packaging & Versioning

### Default install, opt-in transport

The intended integration model is that the daemon is installed by default through Flare's shared PHP client packages, including Laravel integrations that depend on that client layer. The presence of the daemon package should not enable daemon transport automatically.

Runtime activation remains explicit. Installation and onboarding docs may steer new users toward enabling the daemon, especially for logging, but "installed by default" and "enabled by default" are separate decisions.

### Distribution channels

Composer is the default application install path. The daemon exposes a stable vendor binary for local execution, process managers, and framework wrappers.

PHAR, Docker, and Helm are additional operator-facing distribution channels:

- **Composer / vendor bin** — the default path for app installs, backed by the bundled PHAR
- **PHAR** — a standalone artifact for operators who want a single-file deployment
- **Docker** — a container artifact for containerized environments
- **Helm** — a Kubernetes DaemonSet chart published as an OCI chart to GitHub Container Registry

Laravel may add convenience wrappers around the daemon binary, but the daemon itself is framework-agnostic and must work for standalone PHP and Laravel users alike.

### Versioning model

The daemon should have one canonical version stream owned by this repository's Git tags. Packagist package versions, PHAR releases, Docker image tags, and Helm chart versions should all track that same daemon version. The Docker image tags are `v`-prefixed, while the Helm chart version is plain SemVer and uses a `v`-prefixed `appVersion`.

Client packages should depend on compatible daemon versions, but should not own the daemon's version number themselves. The daemon release cadence and operator-facing artifacts are defined at the daemon package level.

## Installation

### Composer / vendor bin

Flare's PHP client packages install the daemon package by default so applications can run the daemon from Composer's
vendor binary directory:

```bash
php vendor/bin/flare-daemon
```

The Composer package ships a bundled PHAR for this binary. Its internal ReactPHP runtime is isolated from the
application's Composer dependency graph.

When working from this repository, use `php src/daemon.php` for source development. The Composer binary expects the
bundled `build/daemon.phar` to be present.

### Docker

```bash
docker run -d --name flare-daemon -p 8787:8787 ghcr.io/spatie/flare-daemon
```

### Helm

The Helm chart runs Flare Daemon as a Kubernetes DaemonSet and exposes it through an in-cluster Service:

```bash
helm install flare-daemon \
    oci://ghcr.io/spatie/charts/flare-daemon \
    --version 1.2.3 \
    --namespace flare-daemon \
    --create-namespace
```

To install the chart directly from a local checkout of this repository:

```bash
helm install flare-daemon ./charts/flare-daemon \
    --namespace flare-daemon \
    --create-namespace
```

Applications can send daemon traffic to:

```text
http://flare-daemon.flare-daemon.svc.cluster.local:8787
```

The chart runs one daemon pod per Kubernetes node and defaults to `service.internalTrafficPolicy=Local`, so application pods use the daemon endpoint on their own node. If a node-local daemon is unavailable, Flare clients should fall back to direct delivery.

### PHAR

```bash
php build/daemon.phar
```

## Usage

### Verbose mode

By default the daemon logs lifecycle events (started, stopped) and a periodic summary of forwarded payloads. Pass `--verbose` (or `-v`) to also log every individual payload at `DEBUG` level:

```bash
php build/daemon.phar --verbose
```

```bash
docker run -d --name flare-daemon -p 8787:8787 ghcr.io/spatie/flare-daemon --verbose
```

### Configuration

All configuration is done through environment variables:

| Variable | Default | Description |
|---|---|---|
| `FLARE_DAEMON_LISTEN` | `127.0.0.1:8787` | Address to listen on |
| `FLARE_DAEMON_UPSTREAM` | `https://ingress.flareapp.io` | Flare ingress URL |
| `FLARE_DAEMON_BUFFER_BYTES` | `262144` (256 KB) | Size threshold per buffer (used by maintenance safety net) |
| `FLARE_DAEMON_FLUSH_AFTER_SECONDS` | `10` | Seconds before maintenance flushes oldest buffered items (safety net) |
| `FLARE_DAEMON_UPSTREAM_TIMEOUT_SECONDS` | `10` | Timeout in seconds for upstream requests |
| `FLARE_COMPOSER_LOCK` | _(none)_ | Path to `composer.lock` — daemon stops when the file changes |

### Smoke-testing with a real API key

Start the daemon, then use `tests/test.sh` to send a real error payload through the full buffering/flushing path:

```bash
php src/daemon.php --verbose &
bash tests/test.sh YOUR_API_KEY
```

The script checks `/health`, sends a normal error to `/v1/errors`, and polls `/status` until the buffer drains. Pass `-u URL` to target a different daemon address.

### Building

```bash
bash build.sh
```

This downloads [Box](https://github.com/box-project/box) (if needed) and compiles `build/daemon.phar`, which is used by
the Composer vendor binary, Docker image, and GitHub release asset.

The PHAR is built from `runtime/composer.json`, a small build-only Composer project that contains the daemon's ReactPHP
runtime dependencies. The root `composer.json` stays dependency-light so installing `spatie/flare-daemon` in an
application does not constrain the application's PSR-7 or ReactPHP dependency graph.

Maintainers can use [`RELEASING.md`](RELEASING.md) for the GitHub-release-driven artifact publishing process.

### Load testing

Start the daemon in test mode (accepts payloads, doesn't forward upstream), then run the k6 script:

```bash
php src/daemon.php --test &
k6 run loadtest/loadtest.js
```

On an M-series Mac the daemon sustains ~18,000 reports/sec with p95 latency under 11ms. See `loadtest/README.md` for details.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/spatie/.github/blob/main/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alex Vanderbist](https://github.com/alexvanderbist)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
