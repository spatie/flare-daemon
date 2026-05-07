# Releasing Flare Daemon

## Validate before publishing

```bash
composer test
composer analyse
helm lint charts/flare-daemon
bash build.sh
bash tests/smoke/phar.sh
bash tests/smoke/composer-bin.sh
bash tests/smoke/docker.sh
```

The Composer vendor binary runs the committed `build/daemon.phar`. If daemon source or runtime dependencies changed,
run `bash build.sh` and include the updated `build/daemon.phar` in the release commit before tagging.
The PHAR is built from `runtime/composer.json`; update `runtime/composer.lock` when changing daemon runtime dependencies.

## Publish a release

1. Create a GitHub release with an `X.Y.Z` or `vX.Y.Z` tag.
2. Publish the release.

Publishing the release triggers:

- `Publish Release`, which uploads `build/daemon.phar` as `daemon.phar` and `daemon.phar.sha256` to the GitHub release
- `Publish Docker Image`, which pushes `ghcr.io/spatie/flare-daemon:vX.Y.Z`, `vX.Y`, `vX`, and `latest` to GitHub Container Registry
- `Publish Helm Chart`, which pushes `oci://ghcr.io/spatie/charts/flare-daemon` with chart version `X.Y.Z` and app version `vX.Y.Z`
- `Update Changelog`, which writes the release notes into `CHANGELOG.md`

The Docker and Helm workflows normalize both tag formats to the same published artifacts. The Docker image is always pushed as `ghcr.io/spatie/flare-daemon:vX.Y.Z`; the Helm chart is pushed as version `X.Y.Z` with app version `vX.Y.Z`.

The Helm chart workflow can also be run manually to retry publishing or publish a chart-only patch. Use a chart version without a leading `v`, and use the daemon image tag as the app version.

## Verify after publishing

- Confirm the GitHub release contains `daemon.phar` and `daemon.phar.sha256`
- Pull `ghcr.io/spatie/flare-daemon:vX.Y.Z`
- `helm pull oci://ghcr.io/spatie/charts/flare-daemon --version X.Y.Z`
- Run the image and confirm `GET /health` returns `{"status":"ok"}`
