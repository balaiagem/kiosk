<?php
/**
 * POST api/update-order.php
 * Body: { order_id, status }   status ∈ preparing | ready | done | cancelled
 *
 * Used by the kitchen tablet to advance an order through the prep queue.
 */

require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

// optional auth — if config has kitchen_key, require ?key= or X-Kitchen-Key header
if (!empty($CONFIG['kitchen_key'])) {
    $key = $_GET['key'] ?? ($_SERVER['HTTP_X_KITCHEN_KEY'] ?? '');
    if (!hash_equals($CONFIG['kitchen_key'], $key)) json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$body = input_json();
$id = $body['order_id'] ?? '';
$status = $body['status'] ?? '';
$allowed = ['preparing', 'ready', 'done', 'cancelled'];
if (!in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'status inválido'], 400);
if (!$id) json_out(['ok' => false, 'error' => 'missing order_id'], 400);

$pdo = db();
$stmt = $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ? AND status NOT IN (?, ?)');
$stmt->execute([$status, db_now(), $id, 'pending_payment', 'rejected']);
if ($stmt->rowCount() === 0) json_out(['ok' => false, 'error' => 'orden no se pudo actualizar'], 409);

json_out(['ok' => true]);
