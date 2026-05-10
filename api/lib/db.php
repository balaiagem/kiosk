<?php
/**
 * Tiny SQLite wrapper — no external deps, works on every shared host with PDO.
 */

declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    global $CONFIG;
    $path = $CONFIG['db_path'];
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS orders (
            id TEXT PRIMARY KEY,
            short_id TEXT NOT NULL,
            status TEXT NOT NULL,         -- pending_payment, paid, awaiting_cash, new, preparing, ready, done, cancelled, rejected
            total_cents INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT "MXN",
            cart_json TEXT NOT NULL,
            mp_payment_intent_id TEXT,
            mp_payment_id TEXT,
            mp_device_id TEXT,
            error TEXT,
            created_at TEXT NOT NULL,
            paid_at TEXT,
            updated_at TEXT NOT NULL
        );
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_intent ON orders(mp_payment_intent_id);');

    return $pdo;
}

function db_now(): string { return gmdate('Y-m-d H:i:s'); }
