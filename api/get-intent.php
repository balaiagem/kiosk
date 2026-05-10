<?php
/**
 * GET api/get-intent.php?order_id=ORDER_ID
 *
 * Looks up the Mercado Pago payment intent we sent to the terminal for a
 * given internal order, and reports its current status as MP sees it.
 * Useful for debugging "terminal_pending: true but the terminal didn't ring".
 *
 * Delete once everything works.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$orderId = $_GET['order_id'] ?? '';
if (!$orderId) json_out(['ok' => false, 'error' => 'missing ?order_id='], 400);

$row = db()->prepare('SELECT id, short_id, status, mp_payment_intent_id, mp_device_id, error FROM orders WHERE id = ?');
$row->execute([$orderId]);
$o = $row->fetch(PDO::FETCH_ASSOC);
if (!$o) json_out(['ok' => false, 'error' => 'orden no encontrada'], 404);

$intent_id = $o['mp_payment_intent_id'];
$device_id = $o['mp_device_id'];

$mp_status = null;
if ($intent_id && $device_id && !empty($CONFIG['mp_access_token'])) {
    $url = 'https://api.mercadopago.com/point/integration-api/devices/'
        . rawurlencode($device_id) . '/payment-intents/' . rawurlencode($intent_id);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $CONFIG['mp_access_token']],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $mp_status = ['http_code' => $code, 'body' => json_decode($resp, true) ?: $resp];
}

json_out([
    'ok' => true,
    'order' => $o,
    'mp_intent_lookup' => $mp_status,
]);
