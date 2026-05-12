# Changelog

All notable changes to `flare-daemon` will be documented in this file.

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
