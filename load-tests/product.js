import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8000/api/v1';

export const options = {
  stages: [
    { duration: '30s', target: 20 },
    { duration: '1m',  target: 50 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(50)<600', 'p(95)<1200', 'p(99)<1800'],
  },
};

const productListDuration = new Trend('product_list_duration');
const productShowDuration = new Trend('product_show_duration');

export default function () {
    
  const listRes = http.get(`${BASE}/products?per_page=15`, {
    headers: { Accept: 'application/json' },
  });
  productListDuration.add(listRes.timings.duration);
  check(listRes, {
    'list status 200 or 304': (r) => r.status === 200 || r.status === 304,
  });

  const showRes = http.get(`${BASE}/products/1`, {
    headers: {
      Accept: 'application/json',
      'If-None-Match': listRes.headers['ETag'] || '',
    },
  });
  productShowDuration.add(showRes.timings.duration);
  check(showRes, {
    'show status 200 or 304': (r) => r.status === 200 || r.status === 304,
  });

  sleep(0.3);
}