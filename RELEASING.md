# Releasing Flare Daemon

## Validate before publishing

```bash
composer test
composer analyse
helm lint charts/flare-daemon
bash tests/smoke/phar.sh
bash tests/smoke/composer-bin.sh
bash tests/smoke/docker.sh
```

## Publish a release

1. Create a GitHub release with a `vX.Y.Z` tag.
2. Publish the release.

Publishing the release triggers:

- `Publish Release`, which uploads `daemon.phar` and `daemon.phar.sha256` to the GitHub release
- `Publish Docker Image`, which pushes `ghcr.io/spatie/flare-daemon:vX.Y.Z`, `vX.Y`, `vX`, and `latest` to GitHub Container Registry
- `Publish Helm Chart`, which pushes `oci://ghcr.io/spatie/charts/flare-daemon` with chart version `X.Y.Z` and app version `vX.Y.Z`
- `Update Changelog`, which writes the release notes into `CHANGELOG.md`

The Helm chart workflow can also be run manually to retry publishing or publish a chart-only patch. Use a chart version without a leading `v`, and use the daemon image tag as the app version.

## Verify after publishing

- Confirm the GitHub release contains `daemon.phar` and `daemon.phar.sha256`
- Pull `ghcr.io/spatie/flare-daemon:vX.Y.Z`
- Pull `oci://ghcr.io/spatie/charts/flare-daemon --version X.Y.Z`
- Run the image and confirm `GET /health` returns `{"status":"ok"}`
