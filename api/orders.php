<?php
/**
 * GET api/orders.php?tab=active|done
 *
 * Returns the orders for the kitchen display, plus counts for both tabs.
 *
 *   tab=active (default) → 'paid', 'awaiting_cash', 'preparing', 'ready'
 *   tab=done             → 'done' orders updated in the last 24 hours (max 50)
 */

require_once __DIR__ . '/lib/bootstrap.php';

// Opportunistic cleanup: any pending_payment orders older than 5 minutes
// are abandoned. Mark them cancelled so they don't sit in the DB forever.
db()->prepare("
    UPDATE orders SET status = 'cancelled', updated_at = ?
    WHERE status = 'pending_payment'
      AND created_at < datetime('now', '-5 minutes')
")->execute([db_now()]);

$tab = $_GET['tab'] ?? 'active';

// Always compute counts for both tabs so the UI badges stay in sync
$counts = db()->query("
    SELECT
        SUM(CASE WHEN status IN ('paid', 'awaiting_cash', 'preparing', 'ready') THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'done' AND updated_at > datetime('now', '-24 hours') THEN 1 ELSE 0 END) AS done_count
    FROM orders
")->fetch(PDO::FETCH_ASSOC);

if ($tab === 'done') {
    $rows = db()->query("
        SELECT id, short_id, status, total_cents, currency, cart_json, created_at, updated_at
        FROM orders
        WHERE status = 'done' AND updated_at > datetime('now', '-24 hours')
        ORDER BY updated_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = db()->query("
        SELECT id, short_id, status, total_cents, currency, cart_json, created_at, updated_at
        FROM orders
        WHERE status IN ('paid', 'awaiting_cash', 'preparing', 'ready')
        ORDER BY created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$orders = [];
foreach ($rows as $r) {
    $cart = json_decode($r['cart_json'], true) ?: [];
    // Map DB status to kitchen UI status
    $kitchen_status = $r['status'];
    if ($kitchen_status === 'paid' || $kitchen_status === 'awaiting_cash') $kitchen_status = 'new';

    $orders[] = [
        'id'             => $r['id'],
        'short_id'       => $r['short_id'],
        'status'         => $kitchen_status,
        'payment_status' => $r['status'],
        'total'          => $r['total_cents'] / 100,
        'currency'       => $r['currency'],
        'lines'          => $cart['lines'] ?? [],
        'created_at'     => $r['created_at'],
        'updated_at'     => $r['updated_at'],
    ];
}

json_out([
    'ok'     => true,
    'tab'    => $tab,
    'counts' => [
        'active' => (int)($counts['active_count'] ?? 0),
        'done'   => (int)($counts['done_count'] ?? 0),
    ],
    'orders' => $orders,
    'now'    => db_now(),
]);
