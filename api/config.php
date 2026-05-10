<?php
/**
 * Amazonia Bowls Kiosk — live configuration
 *
 * ⚠️  This file contains your Mercado Pago access token.
 *     Never commit it to git, never share it, never paste it into chat.
 *     After testing, rotate the token in:
 *         https://www.mercadopago.com.mx/developers/panel/app -> Credenciales
 *
 * On the server, set permissions to 600 so only the web server can read it:
 *     chmod 600 api/config.php
 */

return [
    /* ---- Mercado Pago credentials (TEST mode for now) ---- */
    'mp_access_token'  => 'TEST-1183632055537297-050215-40f25fed862da78b7ff145e8e2161cc6-32391947',
    'mp_user_id'       => '32391947',  // <- this is the last segment of your access token

    /* ---- Point terminal ----
     * Filled in after Part C of the setup. Until then, keep terminal_enabled
     * false so create-order.php skips the terminal call and just queues the
     * order to the kitchen as "awaiting cash".
     */
    'mp_device_id'     => 'CHANGE_ME__POINT_DEVICE_ID',
    'terminal_enabled' => false,

    /* ---- Webhook ---- */
    'webhook_url'      => 'https://amazoniabowls.com/kiosk/api/mp-webhook.php',

    /* ---- Kitchen auth (leave blank while testing) ---- */
    'kitchen_key'      => '',

    /* ---- Database ---- */
    'db_path'          => __DIR__ . '/../data/kiosk.sqlite',

    /* ---- Currency ---- */
    'currency'         => 'MXN',
];
