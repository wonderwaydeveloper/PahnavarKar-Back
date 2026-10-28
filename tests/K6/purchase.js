import http from 'k6/http';
import { check, fail } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failures = new Rate('staging_purchase_failures');
const responseTime = new Trend('staging_purchase_response_time');
const baseUrl = __ENV.STAGING_BASE_URL;
const token = __ENV.TEST_BEARER_TOKEN;
const planKey = __ENV.TEST_PLAN_KEY;
const host = baseUrl ? baseUrl.replace(/^https?:\/\//, '').split(/[/:]/)[0] : '';

if (
    __ENV.TARGET_ENV !== 'staging'
    || __ENV.ALLOW_STAGING_TESTS !== 'true'
    || __ENV.ALLOW_STAGING_PURCHASE !== 'true'
) {
    throw new Error('Purchases require TARGET_ENV=staging and both explicit staging opt-ins.');
}
if (!baseUrl || !(host === 'localhost' || host.startsWith('127.') || /(^|[.-])staging([.-]|$)/i.test(host))) {
    throw new Error('STAGING_BASE_URL must point to localhost or a staging host.');
}
if (!token || !planKey) {
    throw new Error('Set a dedicated staging TEST_BEARER_TOKEN and TEST_PLAN_KEY.');
}

export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
        staging_purchase_failures: ['rate<0.01'],
        staging_purchase_response_time: ['p(95)<1500'],
    },
};

export default function () {
    const url = baseUrl.replace(/\/+$/, '');
    const headers = {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };
    const quoteResponse = http.post(
        `${url}/api/v1/auth/subscription/quote`,
        JSON.stringify({ plan_key: planKey }),
        { headers },
    );

    if (!check(quoteResponse, { 'subscription quote succeeds': (result) => result.status === 200 })) {
        failures.add(true);
        responseTime.add(quoteResponse.timings.duration);
        return;
    }

    const quote = quoteResponse.json('quote');
    const expectedQuote = {
        gross_amount: quote.gross_amount,
        credit_amount: quote.credit_amount,
        discount_amount: quote.discount_amount,
        payable_amount: quote.payable_amount,
        duration_days: quote.duration_days,
        change_type: quote.change_type,
        source_subscription_id: quote.source_subscription_id || null,
        source_plan_key: quote.source_plan_key || null,
    };
    const idempotencyKey = `k6-staging-${__VU}-${__ITER}-${Date.now()}`;
    const purchaseResponse = http.post(
        `${url}/api/v1/auth/subscription/purchase`,
        JSON.stringify({ plan_key: planKey, expected_quote: expectedQuote }),
        { headers: { ...headers, 'Idempotency-Key': idempotencyKey } },
    );
    const passed = check(purchaseResponse, {
        'staging purchase creates an order': (result) => [201, 202].includes(result.status),
        'purchase response includes order reference': (result) => Boolean(result.json('order.reference')),
    });

    failures.add(!passed);
    responseTime.add(purchaseResponse.timings.duration);
    if (!passed) {
        fail(`Staging purchase failed with HTTP ${purchaseResponse.status}`);
    }
}
