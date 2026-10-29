'use strict';

const dns = require('dns');

dns.setDefaultResultOrder('ipv4first');

const path = require('path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');

const HOST = process.env.INVENTORY_GRPC_HOST || '127.0.0.1:50051';
const TIMEOUT_MS = Number(process.env.INVENTORY_TIMEOUT_MS || 3000);
const PROTO_PATH =
  process.env.PROTO_PATH ||
  path.join(__dirname, '..', 'proto', 'inventory.proto');

const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: true,
  longs: String,
  enums: String,
  defaults: true,
  oneofs: true,
});

const inventoryProto = grpc.loadPackageDefinition(packageDefinition).inventory;
const client = new inventoryProto.InventoryService(
  HOST,
  grpc.credentials.createInsecure()
);

function deadline() {
  return new Date(Date.now() + TIMEOUT_MS);
}

function printJson(payload) {
  process.stdout.write(JSON.stringify(payload) + '\n');
}

function fail(err) {
  const codeName =
    typeof err.code === 'number'
      ? grpc.status[err.code] || String(err.code)
      : String(err.code || 'UNKNOWN');

  printJson({
    error: true,
    code: codeName,
    message: err.details || err.message || String(err),
  });
  process.exit(1);
}

function usage() {
  printJson({
    error: true,
    code: 'USAGE',
    message:
      "Usage: node client.js get <productId> | reserve <reservationId> '<json items>' | release <reservationId> | set <productId> <quantity>",
  });
  process.exit(1);
}

function promisify(method, request) {
  return new Promise((resolve, reject) => {
    method.call(client, request, { deadline: deadline() }, (err, response) => {
      if (err) {
        reject(err);
        return;
      }
      resolve(response);
    });
  });
}

async function main() {
  const [cmd, ...args] = process.argv.slice(2);

  if (!cmd) {
    usage();
  }

  try {
    switch (cmd) {
      case 'get': {
        const productId = Number(args[0]);
        if (!Number.isFinite(productId)) {
          usage();
        }
        const res = await promisify(client.GetQuantity, {
          product_id: productId,
        });
        printJson({
          product_id: Number(res.product_id),
          available: Number(res.available),
        });
        break;
      }

      case 'reserve': {
        const reservationId = args[0];
        const itemsRaw = args[1];
        if (!reservationId || itemsRaw === undefined) {
          usage();
        }

        let items;
        try {
          items = JSON.parse(itemsRaw);
        } catch (parseErr) {
          printJson({
            error: true,
            code: 'INVALID_ARGUMENT',
            message: `invalid items JSON: ${parseErr.message}`,
          });
          process.exit(1);
        }

        if (!Array.isArray(items)) {
          printJson({
            error: true,
            code: 'INVALID_ARGUMENT',
            message: 'items must be a JSON array',
          });
          process.exit(1);
        }

        const res = await promisify(client.Reserve, {
          reservation_id: reservationId,
          items: items.map((item) => ({
            product_id: Number(item.product_id),
            quantity: Number(item.quantity),
          })),
        });
        printJson({
          success: Boolean(res.success),
          message: res.message,
          reservation_id: res.reservation_id,
        });
        break;
      }

      case 'release': {
        const reservationId = args[0];
        if (!reservationId) {
          usage();
        }
        const res = await promisify(client.Release, {
          reservation_id: reservationId,
        });
        printJson({
          success: Boolean(res.success),
          message: res.message,
        });
        break;
      }

      case 'set': {
        const productId = Number(args[0]);
        const quantity = Number(args[1]);
        if (!Number.isFinite(productId) || !Number.isFinite(quantity)) {
          usage();
        }
        const res = await promisify(client.SetQuantity, {
          product_id: productId,
          quantity,
        });
        printJson({
          success: Boolean(res.success),
          available: Number(res.available),
        });
        break;
      }

      default:
        usage();
    }
  } catch (err) {
    fail(err);
  }
}

main();
