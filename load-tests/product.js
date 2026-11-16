import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8000/api/v1';
const PRODUCT_ID = __ENV.PRODUCT_ID || '1';

const productIdsByPage = {};
const etags = {};

const productListDuration = new Trend('product_list_duration', true);
const productShowDuration = new Trend('product_show_duration', true);
const notModifiedRate = new Rate('not_modified_304');

const catalogCacheHits = new Counter('catalog_cache_hits');
const catalogCacheMisses = new Counter('catalog_cache_misses');
const catalogCacheHitRate = new Rate('catalog_cache_hit_rate');

const catalogCacheHitDuration = new Trend(
  'catalog_cache_hit_duration',
  true
);

const catalogCacheMissDuration = new Trend(
  'catalog_cache_miss_duration',
  true
);

const cacheEnabled = __ENV.CATALOG_CACHE_ENABLED || 'unknown';

export const options = {
  stages: [
    { duration: '30s', target: 20 },
    { duration: '1m', target: 50 },
    { duration: '30s', target: 0 },
  ],

  summaryTrendStats: [
    'avg',
    'min',
    'med',
    'p(95)',
    'p(99)',
    'max',
  ],

  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: [
      'p(50)<900',
      'p(95)<1800',
      'p(99)<2700',
    ],
  },
};

function getWithEtag(url, trend) {
  const headers = {
    Accept: 'application/json',
  };

  if (etags[url]) {
    headers['If-None-Match'] = etags[url];
  }

  const res = http.get(url, { headers });

  trend.add(res.timings.duration);

  notModifiedRate.add(res.status === 304);

  if (res.headers['Etag']) {
    etags[url] = res.headers['Etag'];
  }

  // Read catalog cache status from Laravel response header.
  const cacheStatus = res.headers['X-Catalog-Cache'];

  if (cacheStatus) {
    const status = cacheStatus.toUpperCase();

    if (status === 'HIT') {
      catalogCacheHits.add(1);
      catalogCacheHitRate.add(true);
      catalogCacheHitDuration.add(res.timings.duration);
    } else if (status === 'MISS') {
      catalogCacheMisses.add(1);
      catalogCacheHitRate.add(false);
      catalogCacheMissDuration.add(res.timings.duration);
    }
  }

  return res;
}

export default function () {
  const page = Math.floor(Math.random() * 20) + 1;

  const listUrl =
    `${ BASE }/products?per_page=15&page=${page}`;

  const listRes = getWithEtag(
    listUrl,
    productListDuration
  );

  check(listRes, {
    'list status 200 or 304': (r) =>
      r.status === 200 || r.status === 304,
  });

  let productId = PRODUCT_ID;

  if (listRes.status === 200) {
    const products = listRes.json('products') || [];

    if (products.length > 0) {
      productId =
        products[Math.floor(Math.random() * products.length)].id;

      productIdsByPage[page] = products.map(
        (product) => product.id
      );
    }
  } else if (productIdsByPage[page]) {
    const ids = productIdsByPage[page];

    productId = ids[
      Math.floor(Math.random() * ids.length)
    ];
  }

  const showUrl = `${ BASE }/products/${ productId }`;

  const showRes = getWithEtag(
    showUrl,
    productShowDuration
  );

  check(showRes, {
    'show status 200 or 304': (r) =>
      r.status === 200 || r.status === 304,
  });

  sleep(0.3);
}

export function handleSummary(data) {
  return {
    stdout: JSON.stringify({
      catalog_cache_enabled: cacheEnabled,

      cache_hits:
        data.metrics.catalog_cache_hits?.values.count || 0,

      cache_misses:
        data.metrics.catalog_cache_misses?.values.count || 0,

      cache_hit_rate:
        data.metrics.catalog_cache_hit_rate?.values.rate || 0,

      http_304_rate:
        data.metrics.not_modified_304?.values.rate || 0,

      http_request_rate:
        data.metrics.http_reqs?.values.rate || 0,

      http_request_duration:
        data.metrics.http_req_duration?.values || {},

      product_list_duration:
        data.metrics.product_list_duration?.values || {},

      product_show_duration:
        data.metrics.product_show_duration?.values || {},
      cache_hit_duration:
        data.metrics.catalog_cache_hit_duration?.values || {},

      cache_miss_duration:
        data.metrics.catalog_cache_miss_duration?.values || {},
    }, null, 2),
  };
}