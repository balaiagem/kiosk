<?php
/**
 * Amazonia Bowls Kiosk — configuration
 *
 * Copy this file to config.php and fill in your values.
 * NEVER commit config.php to git or share it publicly — it holds your access token.
 *
 * Recommended file permissions on the server: chmod 600 config.php
 */

return [
    /* ---- Mercado Pago credentials (México) ----
     * Get these from https://www.mercadopago.com.mx/developers/panel
     * Use TEST credentials while you develop, PRODUCTION when you go live.
     */
    'mp_access_token'  => 'APP_USR-XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
    'mp_user_id'       => '0000000000', // your collector ID (numeric)

    /* ---- Point terminal ----
     * Run api/list-devices.php once after pairing to see your device IDs.
     * Format is usually:  "PAX_A910__SOMETHING" or "NEWLAND_N950__SOMETHING"
     */
    'mp_device_id'     => 'CHANGE_ME__POINT_DEVICE_ID',

    /* If you don't yet have a terminal and just want to test the order flow
     * with the kitchen display, set this to false. Orders go straight to the
     * kitchen with status "awaiting_cash" instead of triggering the terminal. */
    'terminal_enabled' => true,

    /* ---- Public URL where Mercado Pago can reach the webhook ----
     * Must be HTTPS and publicly reachable. Examples:
     *   https://amazoniabowls.com/kiosk/api/mp-webhook.php
     */
    'webhook_url'      => 'https://amazoniabowls.com/kiosk/api/mp-webhook.php',

    /* ---- Shared secret to protect kitchen / admin endpoints ----
     * Used to mark orders as ready/done. Generate any random string.
     * Open kitchen.html with ?key=THIS_VALUE on the kitchen tablet, or set
     * a cookie. For now we leave it empty (open) — turn it on if your
     * /kiosk URL is publicly indexable.
     */
    'kitchen_key'      => '',

    /* ---- Misc ----
     * Path to SQLite db file (writable by the PHP user).
     */
    'db_path'          => __DIR__ . '/../data/kiosk.sqlite',

    /* Currency — México is MXN, terminal expects amount in pesos (decimal). */
    'currency'         => 'MXN',
];
