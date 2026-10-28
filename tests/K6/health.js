import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failures = new Rate('health_check_failures');
const responseTime = new Trend('health_response_time');
const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');

export const options = {
    stages: [
        { duration: '15s', target: 5 },
        { duration: '30s', target: 5 },
        { duration: '10s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
        health_check_failures: ['rate<0.01'],
        health_response_time: ['p(95)<500'],
    },
};

export default function () {
    const response = http.get(`${baseUrl}/up`);
    const passed = check(response, {
        'health endpoint returns 200': (result) => result.status === 200,
    });

    failures.add(!passed);
    responseTime.add(response.timings.duration);
}
