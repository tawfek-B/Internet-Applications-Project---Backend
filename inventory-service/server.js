'use strict';

const path = require('path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');
const { createWarehouseStore } = require('./warehouse-store');

const GRPC_PORT = process.env.GRPC_PORT || '50051';
const PROTO_PATH =
  process.env.PROTO_PATH ||
  path.join(__dirname, '..', 'proto', 'inventory.proto');

/** @type {import('./warehouse-store').createWarehouseStore extends () => Promise<infer S> ? S : never} */
let store;

const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: true,
  longs: String,
  enums: String,
  defaults: true,
  oneofs: true,
});

const inventoryProto = grpc.loadPackageDefinition(packageDefinition).inventory;

function getQuantity(call, callback) {
  const productId = Number(call.request.product_id);
  store
    .getQuantity(productId)
    .then((result) => callback(null, result))
    .catch((err) =>
      callback({ code: grpc.status.INTERNAL, message: err.message || String(err) })
    );
}

function normalizeReserveItems(items) {
  const reservationId = String(items.reservationId || '').trim();
  const rawItems = items.items || [];

  if (!reservationId) {
    return {
      error: {
        success: false,
        message: 'reservation_id is required',
        reservation_id: '',
      },
    };
  }

  if (!Array.isArray(rawItems) || rawItems.length === 0) {
    return {
      error: {
        success: false,
        message: 'at least one item is required',
        reservation_id: reservationId,
      },
    };
  }

  const normalized = [];
  for (const item of rawItems) {
    const productId = Number(item.product_id);
    const quantity = Number(item.quantity);

    if (!Number.isFinite(productId) || productId <= 0) {
      return {
        error: {
          success: false,
          message: `invalid product_id: ${item.product_id}`,
          reservation_id: reservationId,
        },
      };
    }

    if (!Number.isInteger(quantity) || quantity <= 0) {
      return {
        error: {
          success: false,
          message: `invalid quantity for product_id ${productId}`,
          reservation_id: reservationId,
        },
      };
    }

    normalized.push({ product_id: productId, quantity });
  }

  return { reservationId, normalized };
}

function reserve(call, callback) {
  const reservationId = String(call.request.reservation_id || '').trim();
  const parsed = normalizeReserveItems({
    reservationId,
    items: call.request.items || [],
  });

  if (parsed.error) {
    callback(null, parsed.error);
    return;
  }

  store
    .reserve(parsed.reservationId, parsed.normalized)
    .then((result) => callback(null, result))
    .catch((err) =>
      callback({ code: grpc.status.INTERNAL, message: err.message || String(err) })
    );
}

function release(call, callback) {
  const reservationId = String(call.request.reservation_id || '').trim();

  if (!reservationId) {
    callback(null, { success: false, message: 'reservation_id is required' });
    return;
  }

  store
    .release(reservationId)
    .then((result) => callback(null, result))
    .catch((err) =>
      callback({ code: grpc.status.INTERNAL, message: err.message || String(err) })
    );
}

function setQuantity(call, callback) {
  const productId = Number(call.request.product_id);
  const quantity = Number(call.request.quantity);

  if (!Number.isFinite(productId) || productId <= 0) {
    callback({
      code: grpc.status.INVALID_ARGUMENT,
      message: 'product_id must be a positive integer',
    });
    return;
  }

  if (!Number.isInteger(quantity) || quantity < 0) {
    callback({
      code: grpc.status.INVALID_ARGUMENT,
      message: 'quantity must be a non-negative integer',
    });
    return;
  }

  store
    .setQuantity(productId, quantity)
    .then((result) => callback(null, result))
    .catch((err) =>
      callback({ code: grpc.status.INTERNAL, message: err.message || String(err) })
    );
}

function invokeHandler(handler, request) {
  return new Promise((resolve, reject) => {
    handler({ request }, (err, response) => {
      if (err) {
        reject(err);
        return;
      }
      resolve(response);
    });
  });
}

function startHttpBridge() {
  const http = require('http');
  const HTTP_PORT = process.env.INVENTORY_HTTP_PORT || '50052';

  const server = http.createServer(async (req, res) => {
    res.setHeader('Content-Type', 'application/json');

    try {
      const url = new URL(req.url || '/', 'http://127.0.0.1');

      if (req.method === 'GET' && /^\/products\/\d+\/quantity$/.test(url.pathname)) {
        const productId = Number(url.pathname.split('/')[2]);
        const result = await invokeHandler(getQuantity, { product_id: productId });
        res.statusCode = 200;
        res.end(JSON.stringify(result));
        return;
      }

      if (req.method === 'GET' && url.pathname === '/products/quantities') {
        const idsParam = url.searchParams.get('ids') || '';
        const ids = idsParam
          .split(',')
          .map((part) => Number(part.trim()))
          .filter((id) => Number.isFinite(id) && id > 0);

        const quantities = {};
        for (const productId of ids) {
          const result = await invokeHandler(getQuantity, { product_id: productId });
          quantities[String(productId)] = Number(result.available);
        }

        res.statusCode = 200;
        res.end(JSON.stringify({ quantities }));
        return;
      }

      if (req.method === 'POST' && url.pathname === '/reservations') {
        const raw = await readRequestBody(req);
        const body = JSON.parse(raw || '{}');
        const result = await invokeHandler(reserve, {
          reservation_id: body.reservation_id,
          items: body.items,
        });
        res.statusCode = 200;
        res.end(JSON.stringify(result));
        return;
      }

      if (req.method === 'POST' && /^\/reservations\/[^/]+\/release$/.test(url.pathname)) {
        const reservationId = decodeURIComponent(url.pathname.split('/')[2]);
        const result = await invokeHandler(release, { reservation_id: reservationId });
        res.statusCode = 200;
        res.end(JSON.stringify(result));
        return;
      }

      if (req.method === 'PUT' && /^\/products\/\d+\/quantity$/.test(url.pathname)) {
        const productId = Number(url.pathname.split('/')[2]);
        const raw = await readRequestBody(req);
        const body = JSON.parse(raw || '{}');
        const result = await invokeHandler(setQuantity, {
          product_id: productId,
          quantity: body.quantity,
        });
        res.statusCode = 200;
        res.end(JSON.stringify(result));
        return;
      }

      res.statusCode = 404;
      res.end(JSON.stringify({ error: true, message: 'Not found' }));
    } catch (err) {
      const message = err.details || err.message || String(err);
      const code = typeof err.code === 'number' ? err.code : null;
      const status = code === grpc.status.INVALID_ARGUMENT ? 400 : 503;
      res.statusCode = status;
      res.end(JSON.stringify({ error: true, message }));
    }
  });

  const httpHost = process.env.INVENTORY_HTTP_BIND || '127.0.0.1';
  server.listen(Number(HTTP_PORT), httpHost, () => {
    console.log(
      `Inventory HTTP bridge listening on http://${httpHost}:${HTTP_PORT}`
    );
  });
}

function readRequestBody(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (chunk) => {
      data += chunk;
    });
    req.on('end', () => resolve(data));
    req.on('error', reject);
  });
}

async function main() {
  store = await createWarehouseStore();

  const server = new grpc.Server();
  server.addService(inventoryProto.InventoryService.service, {
    GetQuantity: getQuantity,
    Reserve: reserve,
    Release: release,
    SetQuantity: setQuantity,
  });

  const bindAddress = `0.0.0.0:${GRPC_PORT}`;
  server.bindAsync(
    bindAddress,
    grpc.ServerCredentials.createInsecure(),
    (err, port) => {
      if (err) {
        console.error('Failed to bind gRPC server:', err);
        process.exit(1);
      }

      console.log(
        `Inventory gRPC service listening on ${bindAddress} (bound port ${port}), warehouse=${store.label}`
      );
    }
  );

  startHttpBridge();
}

main().catch((err) => {
  console.error('Inventory service failed to start:', err);
  process.exit(1);
});
