FROM node:20-bookworm-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 make g++ \
    && rm -rf /var/lib/apt/lists/*

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY server.js ./
COPY client.js ./

ENV GRPC_PORT=50051
ENV INVENTORY_HTTP_PORT=50052
ENV INVENTORY_HTTP_BIND=0.0.0.0
ENV PROTO_PATH=/proto/inventory.proto

EXPOSE 50051 50052

CMD ["node", "server.js"]
