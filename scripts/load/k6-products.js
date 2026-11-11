import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const duration = __ENV.DURATION || '30s';
const vus = Number(__ENV.VUS || 20);

const productListLatency = new Trend('product_list_latency', true);

export const options = {
    vus,
    duration,
    thresholds: {
        http_req_failed: ['rate<0.02'],
        product_list_latency: ['p(95)<2000'],
    },
};

export default function () {
    const listRes = http.get(`${baseUrl}/api/v1/products?per_page=15`, {
        tags: { name: 'product_list' },
    });

    productListLatency.add(listRes.timings.duration);

    check(listRes, {
        'list status 200 or 304': (r) => r.status === 200 || r.status === 304,
    });

    const etag = listRes.headers.Etag || listRes.headers.ETag;

    if (etag) {
        const cachedRes = http.get(`${baseUrl}/api/v1/products?per_page=15`, {
            headers: { 'If-None-Match': etag },
            tags: { name: 'product_list_etag' },
        });

        check(cachedRes, {
            'etag revalidation 304': (r) => r.status === 304,
        });
    }

    sleep(0.05);
}

export function handleSummary(data) {
    const metrics = data.metrics;

  const line = (key) => {
    const metric = metrics[key];
    if (!metric) {
      return `${key}: n/a`;
    }

    return `${key}: p50=${metric.values?.['p(50)'] ?? 'n/a'}ms p95=${metric.values?.['p(95)'] ?? 'n/a'}ms p99=${metric.values?.['p(99)'] ?? 'n/a'}ms`;
  };

    const text = [
        'k6 product catalog load test',
        `BASE_URL=${baseUrl}`,
        line('http_req_duration'),
        line('product_list_latency'),
        '',
        'Run once before optimizations (CATALOG_CACHE_ENABLED=false) and once after.',
    ].join('\n');

    return {
        stdout: `${text}\n`,
        'scripts/load/k6-summary.txt': text,
    };
}
