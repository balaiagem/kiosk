<?php
/**
 * POST api/mp-webhook.php
 *
 * Mercado Pago calls this URL when a payment event happens. We look the
 * payment up by ID, find the matching order via external_reference, and
 * update its status accordingly.
 *
 * Configure this URL in your Mercado Pago developer panel:
 *   https://amazoniabowls.com/kiosk/api/mp-webhook.php
 */

require_once __DIR__ . '/lib/bootstrap.php';

// MP sends a couple of formats; we handle both.
$body = input_json();
$query = $_GET ?? [];

$payment_id = $body['data']['id'] ?? ($query['data.id'] ?? ($query['id'] ?? null));
$type = $body['type'] ?? ($query['type'] ?? null);

// Always log — useful for debugging
@file_put_contents(__DIR__ . '/../data/webhook.log',
    db_now() . " " . json_encode(['body'=>$body,'query'=>$query]) . "\n",
    FILE_APPEND);

if (!$payment_id) json_out(['ok' => true, 'note' => 'no payment id, ignored']);
if ($type && $type !== 'payment') json_out(['ok' => true, 'note' => 'not a payment event']);

if (empty($CONFIG['mp_access_token'])) json_out(['ok' => false, 'error' => 'mp not configured'], 500);

$mp = new MercadoPagoClient($CONFIG['mp_access_token']);
$payment = $mp->getPayment((string)$payment_id);
if (($payment['_http_code'] ?? 0) !== 200) {
    json_out(['ok' => false, 'error' => 'could not fetch payment', 'mp' => $payment], 502);
}

$external_ref = $payment['external_reference'] ?? null;
$status = $payment['status'] ?? null;       // approved | rejected | cancelled | in_process | refunded
if (!$external_ref) json_out(['ok' => true, 'note' => 'no external_reference']);

$pdo = db();
$row = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
$row->execute([$external_ref]);
$o = $row->fetch(PDO::FETCH_ASSOC);
if (!$o) json_out(['ok' => true, 'note' => 'order not found, ignored']);

$new_status = $o['status'];
$paid_at = null;
if ($status === 'approved') { $new_status = 'paid'; $paid_at = db_now(); }
elseif ($status === 'rejected') $new_status = 'rejected';
elseif ($status === 'cancelled') $new_status = 'cancelled';
// in_process / pending => leave as is

$stmt = $pdo->prepare('UPDATE orders SET status = ?, mp_payment_id = ?, paid_at = COALESCE(?, paid_at), updated_at = ? WHERE id = ?');
$stmt->execute([$new_status, (string)$payment_id, $paid_at, db_now(), $external_ref]);

json_out(['ok' => true, 'order' => $external_ref, 'status' => $new_status]);
