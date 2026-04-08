# Releasing Flare Daemon

## Validate before publishing

```bash
composer test
composer analyse
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
- `Update Changelog`, which writes the release notes into `CHANGELOG.md`

## Verify after publishing

- Confirm the GitHub release contains `daemon.phar` and `daemon.phar.sha256`
- Pull `ghcr.io/spatie/flare-daemon:vX.Y.Z`
- Run the image and confirm `GET /health` returns `{"status":"ok"}`
