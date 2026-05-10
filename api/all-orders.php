<?php
/**
 * GET api/all-orders.php
 *
 * Debug endpoint — lists every order in the DB with its status, regardless
 * of whether it would normally appear on the kitchen screen. Use to verify
 * what the backend actually has stored.
 *
 * Delete once everything works.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$rows = db()->query("
    SELECT id, short_id, status, total_cents, mp_payment_intent_id, mp_payment_id,
           created_at, updated_at, paid_at, error
    FROM orders
    ORDER BY created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

json_out([
    'ok' => true,
    'count' => count($rows),
    'orders' => $rows,
]);
