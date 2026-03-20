# Flare Daemon

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/flare-daemon.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-daemon)
[![Tests](https://img.shields.io/github/actions/workflow/status/spatie/flare-daemon/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/flare-daemon/actions/workflows/run-tests.yml)
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

## Installation

### Docker

```bash
docker run -d --name flare-daemon -p 8787:8787 spatie/flare-daemon
```

### PHAR

```bash
php daemon.phar
```

## Usage

### Verbose mode

By default the daemon logs lifecycle events (started, stopped) and a periodic summary of forwarded payloads. Pass `--verbose` (or `-v`) to also log every individual payload at `DEBUG` level:

```bash
php daemon.phar --verbose
```

```bash
docker run -d --name flare-daemon -p 8787:8787 spatie/flare-daemon --verbose
```

### Configuration

All configuration is done through environment variables:

| Variable | Default | Description |
|---|---|---|
| `FLARE_DAEMON_LISTEN` | `127.0.0.1:8787` | Address to listen on |
| `FLARE_DAEMON_UPSTREAM` | `https://ingress.flareapp.io` | Flare ingress URL |
| `FLARE_DAEMON_BUFFER_BYTES` | `262144` (256 KB) | Size threshold per buffer (used by maintenance safety net) |
| `FLARE_DAEMON_FLUSH_AFTER` | `10` | Seconds before maintenance flushes oldest buffered items (safety net) |
| `FLARE_DAEMON_UPSTREAM_TIMEOUT` | `10` | Timeout in seconds for upstream requests |
| `FLARE_COMPOSER_LOCK` | _(none)_ | Path to `composer.lock` — daemon stops when the file changes |

### Smoke-testing with a real API key

Start the daemon, then use `test.sh` to send a real error payload through the full buffering/flushing path:

```bash
php src/daemon.php --verbose &
bash test.sh YOUR_API_KEY
```

The script checks `/health`, sends a normal error to `/v1/errors`, and polls `/status` until the buffer drains. Pass `-u URL` to target a different daemon address.

### Building

```bash
bash build.sh
```

This downloads [Box](https://github.com/box-project/box) (if needed) and compiles the PHAR.

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
