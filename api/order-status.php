<?php
/**
 * GET api/order-status.php?id=ORDER_ID
 *
 * Returns the current status of a single order so the kiosk can poll while
 * the customer pays at the terminal.
 *
 * Statuses returned:
 *   pending_payment  – terminal asked, customer hasn't tapped yet
 *   paid             – Mercado Pago confirmed the payment
 *   awaiting_cash    – terminal not used; customer must pay cash at counter
 *   rejected         – payment failed
 *   cancelled        – order was cancelled
 *
 * If the order is still pending_payment, we ask MP whether any payment
 * with our order_id as external_reference exists yet. This way the kiosk
 * works even when the webhook is delayed or blocked.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$id = $_GET['id'] ?? '';
if (!$id) json_out(['ok' => false, 'error' => 'missing id'], 400);

$pdo = db();

// Auto-cancel any pending_payment orders that are older than 5 minutes.
// Catches "customer walked away from terminal" and similar abandoned orders.
$pdo->prepare("
    UPDATE orders SET status = 'cancelled', updated_at = ?
    WHERE status = 'pending_payment'
      AND created_at < datetime('now', '-5 minutes')
")->execute([db_now()]);

$row = $pdo->prepare('SELECT id, short_id, status, total_cents, currency, mp_payment_intent_id, mp_device_id, mp_payment_id, error
    FROM orders WHERE id = ?');
$row->execute([$id]);
$o = $row->fetch(PDO::FETCH_ASSOC);
if (!$o) json_out(['ok' => false, 'error' => 'orden no encontrada'], 404);

// If still pending, search MP for any payment with our external_reference.
// This is what the webhook would tell us if it fired — we just ask directly.
if ($o['status'] === 'pending_payment' && !empty($CONFIG['mp_access_token'])) {
    $url = 'https://api.mercadopago.com/v1/payments/search?'
        . http_build_query([
            'external_reference' => $id,
            'sort'               => 'date_created',
            'criteria'           => 'desc',
            'limit'              => 1,
        ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $CONFIG['mp_access_token']],
        CURLOPT_TIMEOUT        => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($resp, true);
        $results = $data['results'] ?? [];
        if (!empty($results)) {
            $payment        = $results[0];
            $payment_status = $payment['status'] ?? null;
            $payment_id     = $payment['id'] ?? null;
            $newStatus = $o['status'];
            if      ($payment_status === 'approved')  $newStatus = 'paid';
            elseif  ($payment_status === 'rejected')  $newStatus = 'rejected';
            elseif  ($payment_status === 'cancelled') $newStatus = 'cancelled';
            elseif  ($payment_status === 'refunded')  $newStatus = 'cancelled';
            if ($newStatus !== $o['status']) {
                $pdo->prepare('UPDATE orders SET status = ?, mp_payment_id = COALESCE(?, mp_payment_id), paid_at = COALESCE(paid_at, ?), updated_at = ? WHERE id = ?')
                    ->execute([$newStatus, (string)$payment_id, $newStatus === 'paid' ? db_now() : null, db_now(), $id]);
                $o['status'] = $newStatus;
            }
        }
    }
}

json_out([
    'ok'       => true,
    'id'       => $o['id'],
    'short_id' => $o['short_id'],
    'status'   => $o['status'],
    'total'    => $o['total_cents'] / 100,
    'currency' => $o['currency'],
    'error'    => $o['error'] ?? null,
]);
