import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failures = new Rate('authenticated_api_failures');
const responseTime = new Trend('authenticated_api_response_time');
const baseUrl = __ENV.STAGING_BASE_URL;
const token = __ENV.TEST_BEARER_TOKEN;
const host = baseUrl ? baseUrl.replace(/^https?:\/\//, '').split(/[/:]/)[0] : '';

if (__ENV.TARGET_ENV !== 'staging' || __ENV.ALLOW_STAGING_TESTS !== 'true') {
    throw new Error('Set TARGET_ENV=staging and ALLOW_STAGING_TESTS=true to run API load scenarios.');
}
if (!baseUrl || !(host === 'localhost' || host.startsWith('127.') || /(^|[.-])staging([.-]|$)/i.test(host))) {
    throw new Error('STAGING_BASE_URL must point to localhost or a staging host.');
}
if (!token) {
    throw new Error('Set TEST_BEARER_TOKEN to a dedicated staging account token.');
}

export const options = {
    stages: [
        { duration: '15s', target: 5 },
        { duration: '30s', target: 5 },
        { duration: '10s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
        authenticated_api_failures: ['rate<0.01'],
        authenticated_api_response_time: ['p(95)<500'],
    },
};

export default function () {
    const response = http.get(`${baseUrl.replace(/\/+$/, '')}/api/v1/auth/me`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const passed = check(response, {
        'authenticated profile endpoint returns 200': (result) => result.status === 200,
        'authenticated profile response includes user': (result) => Boolean(result.json('user.id')),
    });

    failures.add(!passed);
    responseTime.add(response.timings.duration);
}
