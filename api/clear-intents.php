<?php
/**
 * GET api/clear-intents.php
 *
 * Cancels any OPEN payment intent currently pending on the configured
 * Mercado Pago Point terminal. Useful when intents get stuck because
 * the terminal couldn't receive them.
 *
 * Delete once everything works.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$device_id = $CONFIG['mp_device_id'] ?? '';
$token     = $CONFIG['mp_access_token'] ?? '';
if (!$device_id || !$token) {
    json_out(['ok' => false, 'error' => 'set mp_device_id and mp_access_token in config.php'], 500);
}

// 1. Read all orders we have an intent for, that are still pending_payment
$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, short_id, mp_payment_intent_id FROM orders
     WHERE status = 'pending_payment' AND mp_payment_intent_id IS NOT NULL"
);
$stmt->execute();
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($pending as $o) {
    $url = 'https://api.mercadopago.com/point/integration-api/devices/'
        . rawurlencode($device_id) . '/payment-intents/' . rawurlencode($o['mp_payment_intent_id']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results[] = [
        'order_id'  => $o['id'],
        'short_id'  => $o['short_id'],
        'intent_id' => $o['mp_payment_intent_id'],
        'http_code' => $code,
        'response'  => json_decode($resp, true) ?: $resp,
    ];

    // mark order as cancelled in our DB
    $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
        ->execute(['cancelled', db_now(), $o['id']]);
}

json_out([
    'ok' => true,
    'count' => count($pending),
    'cleared' => $results,
]);
