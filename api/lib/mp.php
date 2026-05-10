<?php
/**
 * Mercado Pago Point API client (México).
 *
 * Docs:
 *   https://www.mercadopago.com.mx/developers/es/docs/mp-point/integration-api/intro
 *
 * Two endpoints we use:
 *   POST /point/integration-api/devices/{device_id}/payment-intents   -> create a payment on the terminal
 *   GET  /point/integration-api/devices                               -> list paired terminals
 *
 * Caveat for México: the Integration API is supported on the standalone smart
 * terminals (Point Smart, Point Pro). The Bluetooth-only Point Mini has a
 * different flow (mPOS / app-based). If POST returns "device_id not found",
 * confirm with Mercado Pago support which integration mode your unit supports.
 */

declare(strict_types=1);

class MercadoPagoClient {
    private string $token;
    private string $base = 'https://api.mercadopago.com';

    public function __construct(string $token) { $this->token = $token; }

    public function listDevices(): array {
        return $this->request('GET', '/point/integration-api/devices');
    }

    /**
     * Create a payment intent on the given terminal.
     * @param int    $amountCents   amount in cents (MXN). 12000 == $120.00
     * @param string $externalRef   our internal order id
     * @param string $deviceId      paired terminal id
     */
    public function createPaymentIntent(string $deviceId, int $amountCents, string $externalRef, ?string $description = null): array {
        // Mexico's Point Integration API only accepts `amount` and `additional_info`.
        // `description` and `payment` (installments/type) are NOT permitted in the body.
        // We also try a few hopeful keys to skip the intermediate "Tarjeta" tap;
        // MP may reject them with "Additional property not allowed", in which case
        // remove the offending key here.
        $body = [
            'amount' => $amountCents,
            'additional_info' => [
                'external_reference'    => $externalRef,
                'print_on_terminal'     => true,
                // Experimental — undocumented; may be ignored or rejected
                'auto_return'           => 'all',
                'self_service'          => true,
                'skip_method_selection' => true,
            ],
        ];
        return $this->request(
            'POST',
            '/point/integration-api/devices/' . rawurlencode($deviceId) . '/payment-intents',
            $body
        );
    }

    public function cancelPaymentIntent(string $deviceId, string $intentId): array {
        return $this->request(
            'DELETE',
            '/point/integration-api/devices/' . rawurlencode($deviceId)
              . '/payment-intents/' . rawurlencode($intentId)
        );
    }

    public function getPayment(string $paymentId): array {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    private function request(string $method, string $path, ?array $body = null): array {
        $ch = curl_init($this->base . $path);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . bin2hex(random_bytes(8)),
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['_http_code' => 0, '_error' => $err ?: 'curl failure'];
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) $decoded = ['_raw' => $resp];
        $decoded['_http_code'] = $code;
        return $decoded;
    }
}
