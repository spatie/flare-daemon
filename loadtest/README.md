# Load Testing

Uses [k6](https://grafana.com/docs/k6/) to benchmark the Flare daemon's ingest throughput and memory usage.

## Prerequisites

```bash
brew install k6   # macOS
```

## Quick start

Start the daemon in test mode (accepts payloads but does not forward them upstream, prints stats every 5 seconds):

```bash
php src/daemon.php --test
```

In a second terminal, run the load test:

```bash
k6 run loadtest/loadtest.js
```

## What `--test` mode does

- Replaces the real upstream with a null upstream that instantly returns `204`
- Prints periodic stats to stdout: received/buffered/forwarded counts, pending buffer size, memory usage
- The full buffer and flush pipeline still runs — the only difference is no HTTP leaves the process

## Goals

### A. Throughput (reports/sec)

k6 reports `http_reqs` rate in its summary. This tells you the maximum ingest rate the daemon can sustain.

The default scenario ramps from 1 to 200 VUs over ~80 seconds. Adjust `stages` in `loadtest.js` to push higher.

### B. Memory per report

Watch the daemon's `memory_mb` stat output while k6 runs. Compare the baseline (idle) to peak (under load) and divide by the number of concurrent reports to estimate per-report overhead.

For a more precise measurement, set a very high flush interval so reports accumulate in the buffer:

```bash
FLARE_DAEMON_FLUSH_AFTER_SECONDS=9999 FLARE_DAEMON_BUFFER_BYTES=999999999 php src/daemon.php --test
```

Then send a known number of reports and compare memory before/after.

> Note: the daemon currently flushes each report immediately (no batch API yet). With NullUpstream these flushes resolve instantly, so buffer depth stays near zero under normal `--test` mode. The high flush interval trick above disables the _time-based_ safety net, but each `accept()` still triggers an immediate `scheduleFlush()`. To truly hold reports in the buffer for memory measurement, you'd need to pause upstream flushing (e.g. by temporarily commenting out `$this->scheduleFlush()` in `Ingest::accept()`).

## Configuration

| Environment variable | Default | Description |
|---|---|---|
| `DAEMON_URL` | `http://127.0.0.1:8787` | Daemon address (k6 side) |
| `API_KEY` | `test-load-test-key` | API key sent with each payload |

Example with custom values:

```bash
DAEMON_URL=http://localhost:9000 API_KEY=my-key k6 run loadtest/loadtest.js
```

## Custom scenarios

Edit `loadtest.js` to change the load profile. Some ideas:

**Constant rate** — sustained throughput at a fixed RPS:

```javascript
scenarios: {
    constant: {
        executor: 'constant-arrival-rate',
        rate: 500,
        timeUnit: '1s',
        duration: '60s',
        preAllocatedVUs: 100,
        maxVUs: 500,
    },
},
```

**Breakpoint** — keep ramping until errors appear:

```javascript
scenarios: {
    breakpoint: {
        executor: 'ramping-vus',
        stages: [
            { duration: '30s', target: 100 },
            { duration: '30s', target: 500 },
            { duration: '30s', target: 1000 },
            { duration: '30s', target: 2000 },
        ],
    },
},
```
