'use strict';

const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');
const mysql = require('mysql2/promise');

async function createSqliteStore() {
  const dataDir = path.join(__dirname, 'data');
  const dbPath = path.join(dataDir, 'inventory.db');

  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
  }

  const db = new Database(dbPath);
  const journalMode = process.env.INVENTORY_SQLITE_JOURNAL || 'WAL';
  db.pragma(`journal_mode = ${journalMode}`);
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

  return {
    label: `sqlite:${dbPath}`,
    async getQuantity(productId) {
      const row = db
        .prepare('SELECT available FROM stock WHERE product_id = ?')
        .get(productId);

      return {
        product_id: productId,
        available: row ? row.available : 0,
      };
    },
    async setQuantity(productId, quantity) {
      db.prepare(`
        INSERT INTO stock (product_id, available) VALUES (?, ?)
        ON CONFLICT(product_id) DO UPDATE SET available = excluded.available
      `).run(productId, quantity);

      return { success: true, available: quantity };
    },
    async reserve(reservationId, normalized) {
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
        db.exec('BEGIN IMMEDIATE');

        const existing = db
          .prepare('SELECT id FROM reservations WHERE id = ?')
          .get(reservationId);

        if (existing) {
          db.exec('ROLLBACK');

          return {
            success: false,
            message: `reservation already exists: ${reservationId}`,
            reservation_id: reservationId,
          };
        }

        for (const item of normalized) {
          const row = getStock.get(item.product_id);
          const available = row ? row.available : 0;

          if (available < item.quantity) {
            db.exec('ROLLBACK');

            return {
              success: false,
              message: `insufficient stock for product_id ${item.product_id}: requested ${item.quantity}, available ${available}`,
              reservation_id: reservationId,
            };
          }
        }

        insertReservation.run(reservationId);

        for (const item of normalized) {
          const row = getStock.get(item.product_id);
          updateStock.run(row.available - item.quantity, item.product_id);
          insertItem.run(reservationId, item.product_id, item.quantity);
        }

        db.exec('COMMIT');

        return {
          success: true,
          message: 'reserved',
          reservation_id: reservationId,
        };
      } catch (err) {
        try {
          db.exec('ROLLBACK');
        } catch (_) {
          // ignore
        }

        throw err;
      }
    },
    async release(reservationId) {
      const restoreStock = db.prepare(`
        INSERT INTO stock (product_id, available) VALUES (?, ?)
        ON CONFLICT(product_id) DO UPDATE SET available = available + excluded.available
      `);

      try {
        db.exec('BEGIN IMMEDIATE');

        const reservation = db
          .prepare('SELECT id FROM reservations WHERE id = ?')
          .get(reservationId);

        if (!reservation) {
          db.exec('COMMIT');

          return { success: true, message: 'already released or not found' };
        }

        const items = db
          .prepare(
            'SELECT product_id, quantity FROM reservation_items WHERE reservation_id = ?'
          )
          .all(reservationId);

        for (const item of items) {
          restoreStock.run(item.product_id, item.quantity);
        }

        db
          .prepare('DELETE FROM reservation_items WHERE reservation_id = ?')
          .run(reservationId);
        db.prepare('DELETE FROM reservations WHERE id = ?').run(reservationId);

        db.exec('COMMIT');

        return { success: true, message: 'released' };
      } catch (err) {
        try {
          db.exec('ROLLBACK');
        } catch (_) {
          // ignore
        }

        throw err;
      }
    },
  };
}

async function createMysqlStore() {
  const pool = mysql.createPool({
    host: process.env.INVENTORY_MYSQL_HOST || 'mysql',
    port: Number(process.env.INVENTORY_MYSQL_PORT || 3306),
    user: process.env.INVENTORY_MYSQL_USER || 'root',
    password: process.env.INVENTORY_MYSQL_PASSWORD || '',
    database: process.env.INVENTORY_MYSQL_DATABASE || 'inventory',
    waitForConnections: true,
    connectionLimit: 10,
  });

  const connection = await pool.getConnection();
  try {
    await connection.query(`
      CREATE TABLE IF NOT EXISTS stock (
        product_id BIGINT PRIMARY KEY,
        available INT NOT NULL DEFAULT 0,
        CHECK (available >= 0)
      )
    `);
    await connection.query(`
      CREATE TABLE IF NOT EXISTS reservations (
        id VARCHAR(64) PRIMARY KEY,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await connection.query(`
      CREATE TABLE IF NOT EXISTS reservation_items (
        reservation_id VARCHAR(64) NOT NULL,
        product_id BIGINT NOT NULL,
        quantity INT NOT NULL,
        PRIMARY KEY (reservation_id, product_id),
        CONSTRAINT fk_reservation_items_reservation
          FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
        CHECK (quantity > 0)
      )
    `);
  } finally {
    connection.release();
  }

  return {
    label: `mysql:${process.env.INVENTORY_MYSQL_DATABASE || 'inventory'}@${process.env.INVENTORY_MYSQL_HOST || 'mysql'}`,
    async getQuantity(productId) {
      const [rows] = await pool.query(
        'SELECT available FROM stock WHERE product_id = ?',
        [productId]
      );

      const row = rows[0];

      return {
        product_id: productId,
        available: row ? Number(row.available) : 0,
      };
    },
    async setQuantity(productId, quantity) {
      await pool.query(
        `INSERT INTO stock (product_id, available) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE available = VALUES(available)`,
        [productId, quantity]
      );

      return { success: true, available: quantity };
    },
    async reserve(reservationId, normalized) {
      const connection = await pool.getConnection();

      try {
        await connection.beginTransaction();

        const [existingRows] = await connection.query(
          'SELECT id FROM reservations WHERE id = ? FOR UPDATE',
          [reservationId]
        );

        if (existingRows.length > 0) {
          await connection.rollback();

          return {
            success: false,
            message: `reservation already exists: ${reservationId}`,
            reservation_id: reservationId,
          };
        }

        for (const item of normalized) {
          const [stockRows] = await connection.query(
            'SELECT available FROM stock WHERE product_id = ? FOR UPDATE',
            [item.product_id]
          );
          const available = stockRows[0] ? Number(stockRows[0].available) : 0;

          if (available < item.quantity) {
            await connection.rollback();

            return {
              success: false,
              message: `insufficient stock for product_id ${item.product_id}: requested ${item.quantity}, available ${available}`,
              reservation_id: reservationId,
            };
          }
        }

        await connection.query('INSERT INTO reservations (id) VALUES (?)', [
          reservationId,
        ]);

        for (const item of normalized) {
          const [stockRows] = await connection.query(
            'SELECT available FROM stock WHERE product_id = ? FOR UPDATE',
            [item.product_id]
          );
          const available = stockRows[0] ? Number(stockRows[0].available) : 0;

          await connection.query(
            'UPDATE stock SET available = ? WHERE product_id = ?',
            [available - item.quantity, item.product_id]
          );
          await connection.query(
            'INSERT INTO reservation_items (reservation_id, product_id, quantity) VALUES (?, ?, ?)',
            [reservationId, item.product_id, item.quantity]
          );
        }

        await connection.commit();

        return {
          success: true,
          message: 'reserved',
          reservation_id: reservationId,
        };
      } catch (err) {
        await connection.rollback();
        throw err;
      } finally {
        connection.release();
      }
    },
    async release(reservationId) {
      const connection = await pool.getConnection();

      try {
        await connection.beginTransaction();

        const [reservationRows] = await connection.query(
          'SELECT id FROM reservations WHERE id = ? FOR UPDATE',
          [reservationId]
        );

        if (reservationRows.length === 0) {
          await connection.commit();

          return { success: true, message: 'already released or not found' };
        }

        const [items] = await connection.query(
          'SELECT product_id, quantity FROM reservation_items WHERE reservation_id = ?',
          [reservationId]
        );

        for (const item of items) {
          await connection.query(
            `INSERT INTO stock (product_id, available) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE available = available + VALUES(available)`,
            [item.product_id, item.quantity]
          );
        }

        await connection.query(
          'DELETE FROM reservation_items WHERE reservation_id = ?',
          [reservationId]
        );
        await connection.query('DELETE FROM reservations WHERE id = ?', [
          reservationId,
        ]);

        await connection.commit();

        return { success: true, message: 'released' };
      } catch (err) {
        await connection.rollback();
        throw err;
      } finally {
        connection.release();
      }
    },
  };
}

async function createWarehouseStore() {
  const driver = (process.env.INVENTORY_DB_DRIVER || 'sqlite').toLowerCase();

  if (driver === 'mysql') {
    return createMysqlStore();
  }

  return createSqliteStore();
}

module.exports = { createWarehouseStore };
