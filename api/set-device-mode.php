<?php
/**
 * GET api/set-device-mode.php?device_id=...&mode=PDV
 *
 * Helper to flip a Mercado Pago Point terminal between STANDALONE and PDV
 * (integration) operating modes when the option isn't easily accessible
 * in the terminal menu.
 *
 * Usage:
 *   https://amazoniabowls.com/kiosk/api/set-device-mode.php?device_id=DSPREAD_D20__12...&mode=PDV
 *
 * mode = PDV         -> integration mode (driven by the API)
 * mode = STANDALONE  -> default merchant mode (terminal prompts for amount)
 *
 * Delete this file once you're done configuring.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$device_id = $_GET['device_id'] ?? '';
$mode      = strtoupper($_GET['mode'] ?? '');

if (!$device_id) json_out(['ok' => false, 'error' => 'missing ?device_id='], 400);
if (!in_array($mode, ['PDV', 'STANDALONE'], true)) {
    json_out(['ok' => false, 'error' => 'mode must be PDV or STANDALONE'], 400);
}
if (empty($CONFIG['mp_access_token'])) {
    json_out(['ok' => false, 'error' => 'set mp_access_token in config.php first'], 500);
}

// PATCH /point/integration-api/devices/{device_id}
$ch = curl_init('https://api.mercadopago.com/point/integration-api/devices/' . rawurlencode($device_id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $CONFIG['mp_access_token'],
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode(['operating_mode' => $mode]),
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($resp, true);
json_out([
    'ok'         => $code >= 200 && $code < 300,
    'http_code'  => $code,
    'device_id'  => $device_id,
    'mode_set'   => $mode,
    'mp_response'=> $decoded ?: $resp,
]);
