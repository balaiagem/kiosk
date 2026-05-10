<?php
/**
 * Common bootstrap for every API endpoint.
 * - Loads config.php
 * - Sets JSON headers + CORS
 * - Provides helpers: json_out(), input_json(), short_order_id()
 */

declare(strict_types=1);

// TEMPORARY DEBUG: surface PHP errors as JSON so we can diagnose 500s.
// Remove these 4 lines (or set $DEBUG = false) once everything works.
$DEBUG = true;
if ($DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'php_error' => $errstr, 'file' => basename($errfile), 'line' => $errline]);
        exit;
    });
    set_exception_handler(function ($e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'php_exception' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        exit;
    });
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CORS — same-origin in production. We allow * here for local testing.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$config_file = __DIR__ . '/../config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Falta api/config.php — copia config.example.php y rellena tus credenciales',
    ]);
    exit;
}
$CONFIG = require $config_file;

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function short_order_id(string $uuid): string {
    // last 4 chars uppercased — easy for the customer to read
    return strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $uuid), -4));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mp.php';
