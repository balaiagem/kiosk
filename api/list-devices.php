<?php
/**
 * GET api/list-devices.php
 *
 * One-time helper: lists every Mercado Pago Point terminal paired with your
 * account. Run this once after pairing the device with your store, copy the
 * id into config.php as `mp_device_id`, then optionally remove this file.
 */

require_once __DIR__ . '/lib/bootstrap.php';

if (empty($CONFIG['mp_access_token'])) {
    json_out(['ok' => false, 'error' => 'set mp_access_token in config.php first'], 500);
}

$mp = new MercadoPagoClient($CONFIG['mp_access_token']);
$devices = $mp->listDevices();

json_out(['ok' => true, 'devices' => $devices]);
