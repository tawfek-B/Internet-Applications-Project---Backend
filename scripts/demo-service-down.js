'use strict';

const { spawnSync } = require('node:child_process');

const apiBase = process.env.API_BASE || 'http://localhost/api/v1';
const productId = process.env.DEMO_PRODUCT_ID || '1';

const response = spawnSync(
  'curl',
  ['-s', '-w', '\nHTTP_STATUS:%{http_code}\n', `${apiBase}/products/${productId}`],
  { encoding: 'utf8', shell: process.platform === 'win32' }
);

process.stdout.write(response.stdout || '');
process.stderr.write(response.stderr || '');

if (response.status !== 0) {
  process.exit(response.status ?? 1);
}
