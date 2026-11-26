// Load test for webhook intake: k6 run -e BASE_URL=https://api.staging.trik.chat \
//   -e NUMBER_ID=<uuid> -e SECRET=<webhook secret> -e RATE=2000 tests/load/webhook-intake.js
// Targets (docs/plan/PLAN.md, ADR 0010): ack p99 < 50 ms, no errors, nothing lost.
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const RATE = Number(__ENV.RATE || 500);

export const options = {
  scenarios: {
    deliveries: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: __ENV.DURATION || '2m',
      preAllocatedVUs: Math.ceil(RATE / 10),
      maxVUs: RATE * 2,
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.001'],
    http_req_duration: ['p(99)<50'],
  },
};

const url = `${__ENV.BASE_URL}/webhooks/whatsapp/${__ENV.PROVIDER || 'fake'}/${__ENV.NUMBER_ID}`;
const headers = { 'Content-Type': 'application/json', 'X-Trik-Webhook-Secret': __ENV.SECRET };

export default function () {
  // 3 of 4 deliveries are statuses, like real traffic (sent, delivered, read per message)
  const inbound = Math.random() < 0.25;
  const value = inbound
    ? { messages: [{ from: '2010' + Math.floor(10000000 + Math.random() * 89999999), id: 'wamid.load.' + uuidv4(), timestamp: `${Math.floor(Date.now() / 1000)}`, type: 'text', text: { body: 'load test' } }] }
    : { statuses: [{ id: 'wamid.load.' + uuidv4(), status: 'delivered', timestamp: `${Math.floor(Date.now() / 1000)}`, recipient_id: '201012345678' }] };

  const body = JSON.stringify({
    object: 'whatsapp_business_account',
    entry: [{ id: 'WABA', changes: [{ field: 'messages', value: { messaging_product: 'whatsapp', metadata: { phone_number_id: 'LOAD' }, ...value } }] }],
  });

  check(http.post(url, body, { headers }), { 'acknowledged': (r) => r.status === 200 });
}
