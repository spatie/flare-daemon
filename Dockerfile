FROM composer:2 AS build

ARG FLARE_DAEMON_VERSION=dev

WORKDIR /app
COPY . .
RUN composer check-platform-reqs --no-dev
RUN FLARE_DAEMON_VERSION=${FLARE_DAEMON_VERSION} bash ./build.sh

FROM php:8.2-cli-alpine

ARG FLARE_DAEMON_VERSION=dev

RUN apk add --no-cache curl \
    && addgroup -S -g 10001 flare \
    && adduser -S -D -H -h /app -s /sbin/nologin -u 10001 -G flare flare

WORKDIR /app
COPY --from=build /app/daemon.phar /app/daemon.phar
COPY docker/entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

ENV FLARE_DAEMON_LISTEN=0.0.0.0:8787
ENV FLARE_DAEMON_VERSION=${FLARE_DAEMON_VERSION}

LABEL org.opencontainers.image.title="Flare Daemon"
LABEL org.opencontainers.image.version="${FLARE_DAEMON_VERSION}"

EXPOSE 8787

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -sf http://127.0.0.1:8787/health || exit 1

USER flare

ENTRYPOINT ["/app/entrypoint.sh"]
