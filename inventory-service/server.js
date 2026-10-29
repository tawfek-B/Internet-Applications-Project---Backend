'use strict';

const path = require('path');
const fs = require('fs');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');
const Database = require('better-sqlite3');

const GRPC_PORT = process.env.GRPC_PORT || '50051';
const PROTO_PATH =
  process.env.PROTO_PATH ||
  path.join(__dirname, '..', 'proto', 'inventory.proto');
const DATA_DIR = path.join(__dirname, 'data');
const DB_PATH = path.join(DATA_DIR, 'inventory.db');

if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

const db = new Database(DB_PATH);
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
  CREATE TABLE IF NOT EXISTS stock (
    product_id INTEGER PRIMARY KEY,
    available INTEGER NOT NULL DEFAULT 0 CHECK (available >= 0)
  );

  CREATE TABLE IF NOT EXISTS reservations (
    id TEXT PRIMARY KEY,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS reservation_items (
    reservation_id TEXT NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    PRIMARY KEY (reservation_id, product_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
  );
`);

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
  const row = db
    .prepare('SELECT available FROM stock WHERE product_id = ?')
    .get(productId);

  callback(null, {
    product_id: productId,
    available: row ? row.available : 0,
  });
}

function reserve(call, callback) {
  const reservationId = String(call.request.reservation_id || '').trim();
  const items = call.request.items || [];

  if (!reservationId) {
    return callback(null, {
      success: false,
      message: 'reservation_id is required',
      reservation_id: '',
    });
  }

  if (!Array.isArray(items) || items.length === 0) {
    return callback(null, {
      success: false,
      message: 'at least one item is required',
      reservation_id: reservationId,
    });
  }

  const normalized = [];
  for (const item of items) {
    const productId = Number(item.product_id);
    const quantity = Number(item.quantity);

    if (!Number.isFinite(productId) || productId <= 0) {
      return callback(null, {
        success: false,
        message: `invalid product_id: ${item.product_id}`,
        reservation_id: reservationId,
      });
    }

    if (!Number.isInteger(quantity) || quantity <= 0) {
      return callback(null, {
        success: false,
        message: `invalid quantity for product_id ${productId}`,
        reservation_id: reservationId,
      });
    }

    normalized.push({ product_id: productId, quantity });
  }

  const getStock = db.prepare(
    'SELECT available FROM stock WHERE product_id = ?'
  );
  const updateStock = db.prepare(
    'UPDATE stock SET available = ? WHERE product_id = ?'
  );
  const insertReservation = db.prepare(
    'INSERT INTO reservations (id) VALUES (?)'
  );
  const insertItem = db.prepare(`
    INSERT INTO reservation_items (reservation_id, product_id, quantity)
    VALUES (?, ?, ?)
  `);

  try {
    // BEGIN IMMEDIATE acquires a reserved lock immediately to avoid races.
    db.exec('BEGIN IMMEDIATE');

    const existing = db
      .prepare('SELECT id FROM reservations WHERE id = ?')
      .get(reservationId);

    if (existing) {
      db.exec('ROLLBACK');
      return callback(null, {
        success: false,
        message: `reservation already exists: ${reservationId}`,
        reservation_id: reservationId,
      });
    }

    for (const item of normalized) {
      const row = getStock.get(item.product_id);
      const available = row ? row.available : 0;

      if (available < item.quantity) {
        db.exec('ROLLBACK');
        return callback(null, {
          success: false,
          message: `insufficient stock for product_id ${item.product_id}: requested ${item.quantity}, available ${available}`,
          reservation_id: reservationId,
        });
      }
    }

    insertReservation.run(reservationId);

    for (const item of normalized) {
      const row = getStock.get(item.product_id);
      updateStock.run(row.available - item.quantity, item.product_id);
      insertItem.run(reservationId, item.product_id, item.quantity);
    }

    db.exec('COMMIT');
    callback(null, {
      success: true,
      message: 'reserved',
      reservation_id: reservationId,
    });
  } catch (err) {
    try {
      db.exec('ROLLBACK');
    } catch (_) {
      // ignore rollback errors
    }
    callback({
      code: grpc.status.INTERNAL,
      message: err.message || String(err),
    });
  }
}

function release(call, callback) {
  const reservationId = String(call.request.reservation_id || '').trim();

  if (!reservationId) {
    return callback(null, {
      success: false,
      message: 'reservation_id is required',
    });
  }

  try {
    db.exec('BEGIN IMMEDIATE');

    const reservation = db
      .prepare('SELECT id FROM reservations WHERE id = ?')
      .get(reservationId);

    // Idempotent: already released or never existed.
    if (!reservation) {
      db.exec('COMMIT');
      return callback(null, {
        success: true,
        message: 'already released or not found',
      });
    }

    const items = db
      .prepare(
        'SELECT product_id, quantity FROM reservation_items WHERE reservation_id = ?'
      )
      .all(reservationId);

    const restoreStock = db.prepare(`
      INSERT INTO stock (product_id, available) VALUES (?, ?)
      ON CONFLICT(product_id) DO UPDATE SET available = available + excluded.available
    `);

    for (const item of items) {
      restoreStock.run(item.product_id, item.quantity);
    }

    db.prepare('DELETE FROM reservation_items WHERE reservation_id = ?').run(
      reservationId
    );
    db.prepare('DELETE FROM reservations WHERE id = ?').run(reservationId);

    db.exec('COMMIT');
    callback(null, {
      success: true,
      message: 'released',
    });
  } catch (err) {
    try {
      db.exec('ROLLBACK');
    } catch (_) {
      // ignore
    }
    callback({
      code: grpc.status.INTERNAL,
      message: err.message || String(err),
    });
  }
}

function setQuantity(call, callback) {
  const productId = Number(call.request.product_id);
  const quantity = Number(call.request.quantity);

  if (!Number.isFinite(productId) || productId <= 0) {
    return callback({
      code: grpc.status.INVALID_ARGUMENT,
      message: 'product_id must be a positive integer',
    });
  }

  if (!Number.isInteger(quantity) || quantity < 0) {
    return callback({
      code: grpc.status.INVALID_ARGUMENT,
      message: 'quantity must be a non-negative integer',
    });
  }

  try {
    db.prepare(`
      INSERT INTO stock (product_id, available) VALUES (?, ?)
      ON CONFLICT(product_id) DO UPDATE SET available = excluded.available
    `).run(productId, quantity);

    callback(null, {
      success: true,
      available: quantity,
    });
  } catch (err) {
    callback({
      code: grpc.status.INTERNAL,
      message: err.message || String(err),
    });
  }
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
      const status =
        code === grpc.status.INVALID_ARGUMENT ? 400 : 503;
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

function main() {
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
        `Inventory gRPC service listening on ${bindAddress} (bound port ${port}), db=${DB_PATH}`
      );
    }
  );

  startHttpBridge();
}

main();
