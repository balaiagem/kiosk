<?php
/**
 * POST api/create-order.php
 * Body: { cart: [...] }
 * Response:
 *   { ok: true, order_id, short_id, total, terminal_pending: bool }
 *
 * Creates an order in the DB and (if configured) fires a payment intent on
 * the Mercado Pago Point terminal so the customer can tap their card.
 *
 * Server-side it recomputes the price from menu.json so the client can't
 * tamper with prices, and validates required modifier groups.
 */

require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$body = input_json();
$cart = $body['cart'] ?? null;
if (!is_array($cart) || empty($cart)) json_out(['ok' => false, 'error' => 'Carrito vacío'], 400);

// load menu
$menu_path = __DIR__ . '/../menu.json';
$menu = json_decode(file_get_contents($menu_path), true);
if (!$menu) json_out(['ok' => false, 'error' => 'menu.json no se pudo leer'], 500);
$mod_groups = is_array($menu['modifier_groups'] ?? null) ? $menu['modifier_groups'] : [];

$total_cents = 0;
$verified_lines = [];

foreach ($cart as $line) {
    $itemId = $line['itemId'] ?? null;
    $sizeId = $line['sizeId'] ?? null;
    $qty = max(1, (int)($line['qty'] ?? 1));
    $mods_in = is_array($line['mods'] ?? null) ? $line['mods'] : [];

    // find item + size in menu
    $item = null; $cat = null;
    foreach ($menu['categories'] as $c) {
        foreach ($c['items'] as $it) {
            if ($it['id'] === $itemId) { $item = $it; $cat = $c; break 2; }
        }
    }
    if (!$item) json_out(['ok' => false, 'error' => "Producto desconocido: $itemId"], 400);

    $size = null;
    foreach ($item['sizes'] as $s) if ($s['id'] === $sizeId) { $size = $s; break; }
    if (!$size) json_out(['ok' => false, 'error' => "Tamaño desconocido para $itemId"], 400);

    $line_total = $size['price'];
    $verified_mods = [];

    // group selected mods by group_id so we can validate min/max
    $by_group = [];
    foreach ($mods_in as $m) {
        $gid = $m['group_id'] ?? null;
        $oid = $m['id'] ?? null;
        if (!$gid || !$oid) continue;
        $by_group[$gid][] = $oid;
    }

    // validate against item's allowed modifier groups
    $allowed_group_ids = is_array($item['modifier_groups'] ?? null) ? $item['modifier_groups'] : [];
    foreach ($allowed_group_ids as $gid) {
        $group = $mod_groups[$gid] ?? null;
        if (!$group) continue;
        $picked = $by_group[$gid] ?? [];
        $count = count($picked);
        $min = (int)($group['min'] ?? 0);
        $max = (int)($group['max'] ?? 99);
        if ($count < $min) json_out(['ok' => false, 'error' => "Faltan opciones en {$group['name']} ($min mín.)"], 400);
        if ($count > $max) json_out(['ok' => false, 'error' => "Demasiadas opciones en {$group['name']} ($max máx.)"], 400);

        foreach ($picked as $oid) {
            $opt = null;
            foreach ($group['options'] as $o) if ($o['id'] === $oid) { $opt = $o; break; }
            if (!$opt) continue;
            $line_total += $opt['price'];
            $verified_mods[] = [
                'group_id'   => $gid,
                'group_name' => $group['name'],
                'id'         => $oid,
                'name'       => $opt['name'],
                'price'      => $opt['price'],
            ];
        }
    }

    // ignore any mods the client sent for groups not in the item's allow-list
    $line_total_cents = (int) round($line_total * 100) * $qty;
    $total_cents += $line_total_cents;

    $verified_lines[] = [
        'item_id'     => $item['id'],
        'item_name'   => $item['name'],
        'category_id' => $cat['id'],
        'size_id'     => $size['id'],
        'size_label'  => $size['label'],
        'size_price'  => $size['price'],
        'mods'        => $verified_mods,   // also kept under "toppings" key for older kitchen displays
        'toppings'    => $verified_mods,
        'qty'         => $qty,
        'line_total'  => $line_total,
    ];
}

if ($total_cents <= 0) json_out(['ok' => false, 'error' => 'Total inválido'], 400);

// create order row
$order_id = bin2hex(random_bytes(8));
$short = short_order_id($order_id);
$now = db_now();

$cart_json = json_encode(['lines' => $verified_lines], JSON_UNESCAPED_UNICODE);

$pdo = db();
$stmt = $pdo->prepare('INSERT INTO orders
    (id, short_id, status, total_cents, currency, cart_json, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$order_id, $short, 'pending_payment', $total_cents,
    $CONFIG['currency'] ?? 'MXN', $cart_json, $now, $now]);

// fire the terminal
$terminal_pending = false;
$response = [
    'ok'       => true,
    'order_id' => $order_id,
    'short_id' => $short,
    'total'    => $total_cents / 100,
];

if (!empty($CONFIG['terminal_enabled']) && !empty($CONFIG['mp_access_token']) && !empty($CONFIG['mp_device_id'])) {
    $mp = new MercadoPagoClient($CONFIG['mp_access_token']);
    $intent = $mp->createPaymentIntent(
        $CONFIG['mp_device_id'],
        $total_cents,
        $order_id,
        'Amazonia Bowls #' . $short
    );
    if (($intent['_http_code'] ?? 0) >= 200 && ($intent['_http_code'] ?? 0) < 300) {
        $intent_id = $intent['id'] ?? null;
        $pdo->prepare('UPDATE orders SET mp_payment_intent_id = ?, mp_device_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$intent_id, $CONFIG['mp_device_id'], db_now(), $order_id]);
        $terminal_pending = true;
    } else {
        // log error and fall back to "awaiting_cash"
        $pdo->prepare('UPDATE orders SET status = ?, error = ?, updated_at = ? WHERE id = ?')
            ->execute(['awaiting_cash', json_encode($intent, JSON_UNESCAPED_UNICODE), db_now(), $order_id]);
        $response['terminal_error'] = $intent['message'] ?? ('HTTP ' . ($intent['_http_code'] ?? '?'));
    }
} else {
    // no terminal configured — order goes to kitchen as awaiting cash
    $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
        ->execute(['awaiting_cash', db_now(), $order_id]);
}

$response['terminal_pending'] = $terminal_pending;
json_out($response);
