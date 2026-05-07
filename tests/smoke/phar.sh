#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PORT="${FLARE_DAEMON_TEST_PORT:-8880}"
PID=""

cleanup() {
    if [[ -n "${PID}" ]]; then
        kill "${PID}" 2>/dev/null || true
        wait "${PID}" 2>/dev/null || true
    fi
}

trap cleanup EXIT

cd "${REPO_ROOT}"

if [[ -z "${FLARE_DAEMON_VERSION:-}" && -f build/daemon.phar ]]; then
    FLARE_DAEMON_VERSION="$(php -r 'echo trim((new Phar("build/daemon.phar"))["version.txt"]->getContent());')"
fi

FLARE_DAEMON_VERSION="${FLARE_DAEMON_VERSION:-dev}" bash build.sh >/dev/null

FLARE_DAEMON_LISTEN="127.0.0.1:${PORT}" php build/daemon.phar --test >/tmp/flare-daemon-phar.log 2>&1 &
PID=$!

for _ in {1..20}; do
    if curl -fsS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
        break
    fi

    sleep 0.5
done

curl -fsS "http://127.0.0.1:${PORT}/health" | grep '"status":"ok"'
