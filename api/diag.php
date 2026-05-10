<?php
/**
 * GET api/diag.php
 *
 * Lightweight diagnostic endpoint that doesn't require config.php or any
 * Mercado Pago calls. Use this to check that PHP is alive and that the
 * extensions we need are enabled on your hosting.
 *
 * Delete this file once everything works in production.
 */

header('Content-Type: application/json; charset=utf-8');

$info = [
    'ok'              => true,
    'php_version'     => PHP_VERSION,
    'extensions'      => [
        'curl'        => extension_loaded('curl'),
        'pdo'         => extension_loaded('pdo'),
        'pdo_sqlite'  => extension_loaded('pdo_sqlite'),
        'json'        => extension_loaded('json'),
        'mbstring'    => extension_loaded('mbstring'),
        'openssl'     => extension_loaded('openssl'),
    ],
    'config_php_exists' => file_exists(__DIR__ . '/config.php'),
    'data_dir_writable' => is_writable(__DIR__ . '/../data'),
    'menu_json_exists'  => file_exists(__DIR__ . '/../menu.json'),
    'mp_api_reachable'  => null,
];

// Test outbound HTTPS
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.mercadopago.com/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $info['mp_api_reachable'] = $code > 0 ? "HTTP $code" : "FAIL: $err";
} else {
    $info['mp_api_reachable'] = 'cURL not installed';
}

echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
