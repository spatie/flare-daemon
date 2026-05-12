#!/bin/sh

shutdown() {
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null
        wait "$child"
    fi
    exit 0
}

trap shutdown TERM INT

php -d memory_limit="${PHP_MEMORY_LIMIT:-128M}" /app/daemon.phar "$@" &
child=$!

wait "$child"
