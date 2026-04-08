#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/flare-daemon-composer-bin.XXXXXX")"
PORT="${FLARE_DAEMON_TEST_PORT:-8878}"
PID=""

cleanup() {
    if [[ -n "${PID}" ]]; then
        kill "${PID}" 2>/dev/null || true
        wait "${PID}" 2>/dev/null || true
    fi

    rm -rf "${WORK_DIR}"
}

trap cleanup EXIT

cat >"${WORK_DIR}/composer.json" <<JSON
{
    "repositories": [
        {
            "type": "path",
            "url": "${REPO_ROOT}",
            "options": {
                "symlink": false
            }
        }
    ],
    "require": {
        "spatie/flare-daemon": "*"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

composer install --working-dir="${WORK_DIR}" --no-interaction --prefer-dist >/dev/null

FLARE_DAEMON_LISTEN="127.0.0.1:${PORT}" php "${WORK_DIR}/vendor/bin/flare-daemon" --test >"${WORK_DIR}/daemon.log" 2>&1 &
PID=$!

for _ in {1..20}; do
    if curl -fsS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
        break
    fi

    sleep 0.5
done

curl -fsS "http://127.0.0.1:${PORT}/health" | grep '"status":"ok"'
