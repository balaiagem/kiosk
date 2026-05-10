# Amazonia Bowls — Self-Order Kiosk

A vanilla HTML/JS + PHP kiosk that lives at `amazoniabowls.com/kiosk/`. The customer orders on a tablet, taps their card on a Mercado Pago Point terminal, and the order pops up on a kitchen display screen.

```
amazoniabowls.com/kiosk/             ← the customer-facing kiosk (use this URL on the tablet)
amazoniabowls.com/kiosk/kitchen.html ← the kitchen display screen
```

## What's in here

| File | What it does |
| --- | --- |
| `index.html` + `assets/kiosk.*` | Touch-friendly customer kiosk |
| `kitchen.html` + `assets/kitchen.*` | Live kitchen display, polls every 3s |
| `menu.json` | Your menu — edit this directly to change items / prices |
| `api/create-order.php` | Receives an order, creates a Mercado Pago payment intent on the terminal |
| `api/order-status.php` | Kiosk polls this while customer pays |
| `api/orders.php` | Kitchen display polls this for active orders |
| `api/update-order.php` | Kitchen marks orders preparing → ready → done |
| `api/cancel-order.php` | Cancels an in-flight terminal payment |
| `api/mp-webhook.php` | Mercado Pago calls this when payment status changes |
| `api/list-devices.php` | One-time helper: lists your paired terminals |
| `api/lib/` | Shared PHP code (DB + Mercado Pago client) |
| `api/config.example.php` | Copy to `config.php` and fill in credentials |
| `data/` | SQLite database lives here (created automatically) |

No frameworks. No `npm install`. Drop the folder onto your hosting and it runs.

## Setup — step by step

### 1. Upload to your host

Upload the `kiosk/` folder so it lives at `public_html/kiosk/` on your cPanel server. Easiest way is the cPanel **File Manager → Upload** with the folder zipped, then **Extract** in place.

After upload, your tree on the server should look like:

```
public_html/kiosk/
├── index.html
├── kitchen.html
├── menu.json
├── assets/
├── api/
└── data/
```

### 2. Make `data/` writable

The PHP backend writes the SQLite file there. In cPanel File Manager, right-click `data/` → **Change Permissions** → set to `755` (or `775` if your server runs PHP as a different user).

### 3. Get your Mercado Pago credentials (México)

1. Go to https://www.mercadopago.com.mx/developers/panel
2. Create an "application" if you don't have one (call it "Kiosko Amazonia").
3. Under **Credenciales** copy the **Access Token** — you'll get one for **TEST** and one for **PRODUCCIÓN**. Use TEST while you set things up.
4. Note your **User ID** (Collector ID) — visible in the same panel.

### 4. Pair your Point terminal

1. On the terminal: log into the Mercado Pago app with your store account.
2. In the app menu look for **"Modo de integración"** / **"Integration mode"** (sometimes called "Modo PDV"). Turn it ON.
3. The terminal must be on the same Wi-Fi as the tablet (technically only needed for some flows, but recommended).
4. Once paired, fill in `api/config.php` with your access token and run **once** in your browser:

   `https://amazoniabowls.com/kiosk/api/list-devices.php`

   You'll get back JSON with one or more device IDs like `PAX_A910__SOMETHING`. Copy that into `config.php` as `mp_device_id`.

> ⚠️ **About your Point Mini / Pro in México**: The Mercado Pago Integration API officially supports the standalone smart terminals (Point Pro and Point Smart). The Bluetooth-only Point Mini sometimes runs in a different "mPOS" mode that the Integration API can't drive directly — if `list-devices.php` returns an empty array, your unit is in mPOS mode. Call Mercado Pago support and ask them to enable "Modo integración" on your account, or upgrade to a Point Pro / Smart. Until then, set `terminal_enabled => false` in `config.php` and orders will go to the kitchen as **awaiting cash** (customer pays at the counter).

### 5. Configure the kiosk

Copy `api/config.example.php` to `api/config.php` and edit:

```php
'mp_access_token' => 'APP_USR-...',     // from step 3
'mp_device_id'    => 'PAX_A910__...',   // from step 4
'webhook_url'     => 'https://amazoniabowls.com/kiosk/api/mp-webhook.php',
'terminal_enabled' => true,
```

Set permissions on `config.php` to `600` (only the web server can read it).

### 6. Register the webhook

Back in https://www.mercadopago.com.mx/developers/panel → your application → **Webhooks** → add:

```
https://amazoniabowls.com/kiosk/api/mp-webhook.php
```

Subscribe to the **payment** event. Save.

### 7. Edit your menu

Open `menu.json` and replace the placeholder bowls / smoothies / toppings with your real items and prices. Keep the same structure — `id`, `name`, `description`, `sizes`, etc. The kiosk reloads it fresh on every visit, so changes show up immediately.

### 8. Test it

1. Visit `https://amazoniabowls.com/kiosk/` on a desktop browser.
2. Build a test order, click **Pagar con tarjeta**.
3. The terminal should ring. Tap a TEST card from https://www.mercadopago.com.mx/developers/es/docs/checkout-api/integration-test/test-cards.
4. The kiosk should switch to "Pago aprobado".
5. Open `https://amazoniabowls.com/kiosk/kitchen.html` on a second tablet — the order appears.
6. Click **Empezar → Marcar listo → Entregado** to walk it through.
7. Once you're confident, swap the **TEST** access token for the **PRODUCCIÓN** one and reload.

## Setting up the Android tablet

On the customer-facing tablet (Android):

1. Install **Fully Kiosk Browser** (free, by Ozerov) from the Play Store. It locks the device into one URL and survives reboots.
2. Open Fully Kiosk → **Start URL** → `https://amazoniabowls.com/kiosk/`
3. Turn on:
   - **Auto Start on Boot**
   - **Screen always on**
   - **Disable status bar / navigation bar**
   - **Auto reload on idle: 90s** (matches the kiosk's built-in idle timer)
4. (Optional) Set a PIN to exit kiosk mode so customers can't escape into the tablet.

For the **kitchen tablet**, do the same but with start URL `https://amazoniabowls.com/kiosk/kitchen.html`.

## Costs & fees (México, ballpark)

- **Hosting**: $0 extra — uses your existing cPanel.
- **Mercado Pago Point terminal**: one-time purchase, around $1,500–$2,500 MXN depending on model (Mini < Pro < Smart).
- **Per-transaction fee**: ~3.49% + IVA for credit cards, less for debit. Check the Mercado Pago panel for your current rate — it varies by volume.
- **No monthly software fee**.

## Troubleshooting

**Terminal doesn't ring.**
- Check `data/webhook.log` — recent activity?
- Run `api/list-devices.php` again. Empty array → terminal isn't in integration mode (see step 4 caveat).
- Verify `terminal_enabled` is `true` in `config.php`.

**Kiosk shows "Falta api/config.php".**
- You forgot to copy `config.example.php` to `config.php`.

**Kitchen screen never updates.**
- Open browser dev tools on the kitchen tablet → Network tab — is `orders.php` returning 200? If not, check PHP error log in cPanel.
- `data/` folder must be writable.

**"orden no se pudo actualizar" when clicking the kitchen buttons.**
- The order was already finalized (rejected / cancelled). Refresh the page.

**Webhook not firing.**
- Mercado Pago can only reach an HTTPS URL with a valid certificate. Make sure `amazoniabowls.com` has SSL (Let's Encrypt via cPanel is free).
- Check the **Webhooks** panel in MP for delivery attempts and error codes.

## Security notes

- `api/config.php` contains your access token — never commit it to git, never share it. It's covered by the `.htaccess` in `api/`, but double-check by trying to fetch `https://amazoniabowls.com/kiosk/api/config.php` — you should get a **403**.
- The `data/` folder also has an `.htaccess` blocking direct HTTP access.
- The kiosk-side JavaScript never sees the access token; everything sensitive lives in PHP.

## Going further

Things you might want to add later (not built in):
- Daily sales report (read from the SQLite DB).
- Receipt printer (cheap thermal Bluetooth printers work — wire it into `kitchen.js`).
- Loyalty / phone number prompt at end of order.
- Multiple language support (the strings in `kiosk.js` are all in Spanish right now).

## Support

If you hit something this README doesn't cover, the most useful files to look at are:
- `data/webhook.log` — what Mercado Pago is sending you.
- Browser dev tools → Network — what the kiosk is sending the backend.
- cPanel → Errors — PHP errors from the backend.
