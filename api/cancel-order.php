<?php
/**
 * POST api/cancel-order.php
 * Body: { order_id }
 *
 * Tells Mercado Pago to cancel the in-flight payment intent (releases the
 * terminal so the customer can try again) and marks the order as cancelled.
 */

require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$body = input_json();
$id = $body['order_id'] ?? '';
if (!$id) json_out(['ok' => false, 'error' => 'missing order_id'], 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT mp_payment_intent_id, mp_device_id, status FROM orders WHERE id = ?');
$stmt->execute([$id]);
$o = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$o) json_out(['ok' => false, 'error' => 'orden no encontrada'], 404);

if ($o['status'] === 'paid') {
    json_out(['ok' => false, 'error' => 'la orden ya fue pagada — no se puede cancelar desde aquí'], 409);
}

if (!empty($o['mp_payment_intent_id']) && !empty($o['mp_device_id']) && !empty($CONFIG['mp_access_token'])) {
    $mp = new MercadoPagoClient($CONFIG['mp_access_token']);
    $mp->cancelPaymentIntent($o['mp_device_id'], $o['mp_payment_intent_id']);
}

$pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
    ->execute(['cancelled', db_now(), $id]);

json_out(['ok' => true]);
