import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// Run:  k6 run load-tests/product.js
// Other URL:  k6 run -e BASE_URL=http://127.0.0.1:8000/api/v1 load-tests/product.js
const BASE = __ENV.BASE_URL || 'http://localhost:8000/api/v1';
const PRODUCT_ID = __ENV.PRODUCT_ID || '1';

export const options = {
  stages: [
    { duration: '30s', target: 20 }, // ramp up to 20 virtual users
    { duration: '1m', target: 50 },  // hold the load at 50 users
    { duration: '30s', target: 0 },  // ramp down
  ],
  summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(50)<600', 'p(95)<1200', 'p(99)<1800'],
  },
};

const productListDuration = new Trend('product_list_duration', true);
const productShowDuration = new Trend('product_show_duration', true);
const notModifiedRate = new Rate('not_modified_304');

// Each virtual user remembers the last ETag it got for every URL, like a browser does.
const etags = {};

function getWithEtag(url, trend) {
  const headers = { Accept: 'application/json' };

  if (etags[url]) {
    headers['If-None-Match'] = etags[url];
  }

  const res = http.get(url, { headers });
  trend.add(res.timings.duration);
  notModifiedRate.add(res.status === 304);

  if (res.headers['Etag']) {
    etags[url] = res.headers['Etag'];
  }

  return res;
}

export default function () {
  const page = Math.floor(Math.random() * 20) + 1;
  const listRes = getWithEtag(`${BASE}/products?per_page=15&page=${page}`, productListDuration);
  check(listRes, {
    'list status 200 or 304': (r) => r.status === 200 || r.status === 304,
  });

  const showRes = getWithEtag(`${BASE}/products/${PRODUCT_ID}`, productShowDuration);
  check(showRes, {
    'show status 200 or 304': (r) => r.status === 200 || r.status === 304,
  });

  sleep(0.3);
}
