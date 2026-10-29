'use strict';

const grpc = require('@grpc/grpc-js');
const path = require('path');
const protoLoader = require('@grpc/proto-loader');

const HOST = process.env.INVENTORY_GRPC_HOST || '127.0.0.1:50051';
const PRODUCT_ID = Number(process.env.DEMO_PRODUCT_ID || 1);
const PROTO_PATH = path.join(__dirname, '..', 'proto', 'inventory.proto');

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

function call(method, request) {
  return new Promise((resolve, reject) => {
    client[method](request, { deadline: new Date(Date.now() + 5000) }, (err, res) => {
      if (err) {
        reject(err);
        return;
      }
      resolve(res);
    });
  });
}

async function main() {
  await call('SetQuantity', { product_id: PRODUCT_ID, quantity: 1 });

  const attempts = 10;
  const results = await Promise.allSettled(
    Array.from({ length: attempts }, (_, index) =>
      call('Reserve', {
        reservation_id: `race-demo-${Date.now()}-${index}`,
        items: [{ product_id: PRODUCT_ID, quantity: 1 }],
      })
    )
  );

  const successes = results.filter(
    (result) => result.status === 'fulfilled' && result.value.success
  ).length;

  console.log(JSON.stringify({ attempts, successes, expectedSuccesses: 1 }, null, 2));

  if (successes !== 1) {
    process.exitCode = 1;
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
