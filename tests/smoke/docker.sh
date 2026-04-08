#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
IMAGE_TAG="flare-daemon:test"
PORT="${FLARE_DAEMON_TEST_PORT:-8879}"
CID=""

cleanup() {
    if [[ -n "${CID}" ]]; then
        docker rm -f "${CID}" >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT

docker build \
    --build-arg FLARE_DAEMON_VERSION=1.2.3 \
    -t "${IMAGE_TAG}" \
    "${REPO_ROOT}" >/dev/null

CID="$(docker run -d -p "${PORT}:8787" "${IMAGE_TAG}" --test --verbose)"

for _ in {1..20}; do
    if curl -fsS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
        break
    fi

    sleep 0.5
done

curl -fsS "http://127.0.0.1:${PORT}/health" | grep '"status":"ok"'

curl -fsS \
    -H 'Content-Type: application/json' \
    -H 'X-API-Token: smoke-test-key' \
    -d '{"message":"smoke"}' \
    "http://127.0.0.1:${PORT}/v1/errors" | grep '"status":"accepted"'

for _ in {1..20}; do
    if docker logs "${CID}" 2>&1 | grep -q 'payload forwarded upstream'; then
        break
    fi

    sleep 0.5
done

docker logs "${CID}" 2>&1 | grep 'daemon started'
docker logs "${CID}" 2>&1 | grep 'DEBUG'
docker logs "${CID}" 2>&1 | grep 'payload accepted'
docker logs "${CID}" 2>&1 | grep 'payload forwarded upstream'
