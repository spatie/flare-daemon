# Flare Daemon Helm Chart

This chart installs Flare Daemon as a Kubernetes DaemonSet and exposes it through a ClusterIP Service.

## Install

```bash
helm install flare-daemon \
    oci://ghcr.io/spatie/charts/flare-daemon \
    --version 1.2.3 \
    --namespace flare-daemon \
    --create-namespace
```

To install the chart directly from a local checkout of this repository:

```bash
helm install flare-daemon ./charts/flare-daemon \
    --namespace flare-daemon \
    --create-namespace
```

## Configure Applications

Point Flare clients at the in-cluster service URL:

```text
http://flare-daemon.flare-daemon.svc.cluster.local:8787
```

The chart defaults `service.internalTrafficPolicy` to `Local`, so in-cluster traffic is routed only to daemon pods on the same node. If a node has no ready daemon endpoint, the service behaves as unavailable from pods on that node. This matches the daemon's intended fallback model: clients should fall back to direct delivery when the daemon is unreachable.

## Common Values

```yaml
image:
  tag: v1.2.3

args:
  - --verbose

listen:
  port: 8787

env:
  FLARE_DAEMON_UPSTREAM: https://ingress.flareapp.io
  FLARE_DAEMON_BUFFER_BYTES: "262144"
  FLARE_DAEMON_FLUSH_AFTER_SECONDS: "10"
  FLARE_DAEMON_UPSTREAM_TIMEOUT_SECONDS: "10"

resources:
  requests:
    cpu: 25m
    memory: 64Mi
  limits:
    memory: 256Mi
```

## Values

| Value | Default | Description |
| --- | --- | --- |
| `image.repository` | `ghcr.io/spatie/flare-daemon` | Container image repository |
| `image.tag` | `Chart.appVersion` | Container image tag |
| `args` | `[]` | Extra daemon command arguments such as `--verbose` |
| `listen.host` | `0.0.0.0` | Listen host inside the container |
| `listen.port` | `8787` | Listen port used by `FLARE_DAEMON_LISTEN`, the named container port, probes, and Service target port |
| `env.FLARE_DAEMON_UPSTREAM` | `https://ingress.flareapp.io` | Upstream Flare ingress URL |
| `env.FLARE_DAEMON_BUFFER_BYTES` | `"262144"` | Buffer size threshold |
| `env.FLARE_DAEMON_FLUSH_AFTER_SECONDS` | `"10"` | Maintenance flush age threshold |
| `env.FLARE_DAEMON_UPSTREAM_TIMEOUT_SECONDS` | `"10"` | Upstream request timeout |
| `service.internalTrafficPolicy` | `Local` | Node-local routing for in-cluster Service traffic |
| `resources` | requests `25m` CPU and `64Mi` memory, limit `256Mi` memory | Pod resource settings |
