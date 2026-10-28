import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failures = new Rate('otp_send_failures');
const responseTime = new Trend('otp_send_response_time');
const baseUrl = __ENV.STAGING_BASE_URL;
const phones = (__ENV.TEST_PHONES || '').split(',').map((phone) => phone.trim()).filter(Boolean);
const host = baseUrl ? baseUrl.replace(/^https?:\/\//, '').split(/[/:]/)[0] : '';

if (__ENV.TARGET_ENV !== 'staging' || __ENV.ALLOW_STAGING_TESTS !== 'true') {
    throw new Error('Set TARGET_ENV=staging and ALLOW_STAGING_TESTS=true to run API load scenarios.');
}
if (!baseUrl || !(host === 'localhost' || host.startsWith('127.') || /(^|[.-])staging([.-]|$)/i.test(host))) {
    throw new Error('STAGING_BASE_URL must point to localhost or a staging host.');
}
if (__ENV.OTP_TEST_MODE !== 'true' || phones.length < 2) {
    throw new Error('Use a non-delivering OTP test provider and at least two dedicated TEST_PHONES.');
}

export const options = {
    stages: [
        { duration: '15s', target: 2 },
        { duration: '30s', target: 2 },
        { duration: '10s', target: 0 },
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'],
        otp_send_failures: ['rate<0.01'],
        otp_send_response_time: ['p(95)<500'],
    },
};

http.setResponseCallback(http.expectedStatuses(202, 429));

export default function () {
    const phone = phones[(__VU - 1) % phones.length];
    const response = http.post(
        `${baseUrl.replace(/\/+$/, '')}/api/v1/auth/otp/send`,
        JSON.stringify({ phone, type: 'login' }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } },
    );
    const passed = check(response, {
        'OTP endpoint accepts or rate-limits request': (result) => [202, 429].includes(result.status),
    });

    failures.add(!passed);
    responseTime.add(response.timings.duration);
}
