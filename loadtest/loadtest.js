import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// ── Configuration ───────────────────────────────────────────────────

const DAEMON_URL = __ENV.DAEMON_URL || 'http://127.0.0.1:8787';
const API_KEY = __ENV.API_KEY || 'test-load-test-key';

const acceptedReports = new Counter('accepted_reports');

export const options = {
    scenarios: {
        throughput_ramp: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: '10s', target: 10 },
                { duration: '20s', target: 50 },
                { duration: '20s', target: 100 },
                { duration: '20s', target: 200 },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<50', 'p(99)<100'],
    },
};

// ── Payload generation ──────────────────────────────────────────────

function uuid() {
    const hex = () => Math.random().toString(16).substring(2, 6);
    return `${hex()}${hex()}-${hex()}-4${hex().substring(1)}-${hex()}-${hex()}${hex()}${hex()}`;
}

function generatePayload() {
    return JSON.stringify({
        exceptionClass: 'RuntimeException',
        seenAtUnixNano: Date.now() * 1000000,
        message: `Load test error ${uuid()}`,
        code: 0,
        solutions: [],
        stacktrace: [
            {
                file: '/app/src/Http/Controllers/UserController.php',
                lineNumber: Math.floor(Math.random() * 500) + 1,
                method: 'show',
                class: 'App\\Http\\Controllers\\UserController',
                codeSnippet: {},
                arguments: [],
                isApplicationFrame: true,
            },
            {
                file: '/app/vendor/laravel/framework/src/Illuminate/Routing/Router.php',
                lineNumber: 729,
                method: 'runRoute',
                class: 'Illuminate\\Routing\\Router',
                codeSnippet: {},
                arguments: [],
                isApplicationFrame: false,
            },
        ],
        previous: [],
        openFrameIndex: null,
        applicationPath: '/app',
        trackingUuid: uuid(),
        handled: null,
        attributes: {
            'service.name': 'loadtest-app',
            'flare.language.name': 'PHP',
            'flare.language.version': '8.3.0',
            'flare.entry_point.type': 'web',
            'flare.entry_point.value': 'GET /api/users/123',
            'telemetry.sdk.name': 'spatie/flare-client-php',
            'telemetry.sdk.version': 'dev-main',
        },
        events: [],
        isLog: false,
        overriddenGrouping: null,
    });
}

// ── Setup ───────────────────────────────────────────────────────────

export function setup() {
    const health = http.get(`${DAEMON_URL}/health`);
    check(health, {
        'daemon is reachable': (r) => r.status === 200,
    });

    if (health.status !== 200) {
        throw new Error(`Daemon not reachable at ${DAEMON_URL} — start it with: php src/daemon.php --test`);
    }
}

// ── Test ────────────────────────────────────────────────────────────

export default function () {
    const payload = generatePayload();

    const res = http.post(`${DAEMON_URL}/v1/errors`, payload, {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Token': API_KEY,
        },
    });

    if (check(res, { 'status is 202': (r) => r.status === 202 })) {
        acceptedReports.add(1);
    }
}

// ── Teardown ────────────────────────────────────────────────────────

export function teardown() {
    const status = http.get(`${DAEMON_URL}/status`);
    console.log(`\nDaemon status at end of test: ${status.body}`);
}
