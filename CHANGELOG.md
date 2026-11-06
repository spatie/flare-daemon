# Changelog

All notable changes to `flare-daemon` will be documented in this file.

## 0.3.2 - 2026-11-06

### Security

- Update the bundled ReactPHP HTTP runtime from 1.11.0 to 1.11.1 and rebuild the Composer-bin PHAR, addressing GHSA-g4f2-2pf3-2pwj and GHSA-x424-64qh-5j54 (HTTP response-header and malformed chunked-body denial of service). Both development and runtime lock files now use the patched version.

### Development and CI

- Upgrade the development-only Symfony Process dependency to 8.1.10 and test its PHP 8.4.1 lower boundary, plus PHP 8.4, 8.5 and 8.6, with lowest and stable dependency resolution (#21).
- Separately verify production-only Composer installs and both bundled and rebuilt PHARs on PHP 8.1, 8.2 and 8.3 (#21).
- Retain the PHP 8.6 test coverage and nullable test-helper correction from #19.
- Update development tools Laravel Pint (#20) and PHPStan (#22), and Docker registry login tooling (#18).

### Compatibility

Application installs, the standalone PHAR, and the Docker runtime continue to support **PHP 8.1 and higher**. Symfony Process and Pest are not included in the production dependency graph or bundled PHAR. Working on the source test suite now requires **PHP 8.4.1 or higher** and Helm; PHAR build tooling runs on PHP 8.4. No public daemon API or Laravel requirement changes are introduced.

**Full Changelog**: https://github.com/spatie/flare-daemon/compare/0.3.1...0.3.2

## 0.3.1 - 2026-09-24

### What's Changed

* Stop pausing delivery on upstream 403 responses by @AlexVanderbist in https://github.com/spatie/flare-daemon/pull/15
* Log pauses and resumes only when the pause state changes by @AlexVanderbist in https://github.com/spatie/flare-daemon/pull/16
* Pause shorter on rate limit 429s than on quota 429s by @AlexVanderbist in https://github.com/spatie/flare-daemon/pull/17

**Full Changelog**: https://github.com/spatie/flare-daemon/compare/0.3.0...0.3.1

## 0.3.0 - 2026-07-02

Support PHP 8.1 and up. The daemon, Docker image, Composer package, and Helm chart now install and run on PHP 8.1 and higher (previously 8.2 and higher). The daemon source was already compatible, so this lowers the declared floor and rebuilds the PHAR so its embedded platform check accepts 8.1.

## 0.2.1 - 2026-05-12

### What's Changed

Add configurable PHP memory limit for Docker and Helm deployments.

- Docker entrypoint now supports `PHP_MEMORY_LIMIT`, defaulting to `128M`.
- Helm chart exposes `phpMemoryLimit` and wires it to `PHP_MEMORY_LIMIT`.

**Full Changelog**: https://github.com/spatie/flare-daemon/compare/v0.2.0...0.2.1

## v0.2.0 - 2026-05-07

Release the PHAR-backed Composer bin and remove daemon runtime dependencies from consuming projects.

## 0.1.0 - 2026-05-06

### What's Changed

* Add Helm chart for Flare Daemon by @AlexVanderbist in https://github.com/spatie/flare-daemon/pull/9

### New Contributors

* @AlexVanderbist made their first contribution in https://github.com/spatie/flare-daemon/pull/9

**Full Changelog**: https://github.com/spatie/flare-daemon/compare/v0.0.1...0.1.0

## v0.0.1 - 2026-04-08

Initial daemon artifact release
