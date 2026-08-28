<?php
/**
 * Khalti Payment Gateway helper — ePayment API v2 (hosted checkout).
 *
 * Design contract (PROJECT_REPORT §19):
 *  - initiate(): create a payment for a fixed NPR amount -> pidx + payment_url.
 *  - lookup():   the ONLY success authority — Khalti docs mandate treating
 *                only status "Completed" as paid; redirect payloads are never trusted.
 *  - All amounts are paisa integers (NPR * 100). Secret key lives in .env only.
 */
class KhaltiPayment {

    /**
     * Initiate a Khalti payment.
     * @param int    $bookingId  Our booking id (becomes purchase_order_id)
     * @param float  $amountNpr  Amount in rupees (converted to paisa internally)
     * @param string $orderName  Display name for the payment page
     * @return array ['ok'=>bool, 'pidx'=>?string, 'payment_url'=>?string, 'error'=>?string]
     */
    public static function initiate($bookingId, $amountNpr, $orderName) {
        $payload = json_encode([
            'return_url'          => APP_URL . '/public/payment/khalti-return.php',
            'website_url'         => APP_URL,
            'amount'              => (int) round(((float) $amountNpr) * 100),
            'purchase_order_id'   => 'BOOKING-' . (int) $bookingId,
            'purchase_order_name' => $orderName,
        ]);

        $ch = curl_init(KHALTI_BASE_URL . 'epayment/initiate/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Key ' . KHALTI_SECRET_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            error_log("[KhaltiPayment] initiate failed: HTTP=$code err=$err");
            return ['ok' => false, 'pidx' => null, 'payment_url' => null,
                    'error' => 'Payment gateway unreachable. Please try again.'];
        }
        $data = json_decode($body, true);
        if (empty($data['pidx']) || empty($data['payment_url'])) {
            error_log("[KhaltiPayment] initiate unexpected body: $body");
            return ['ok' => false, 'pidx' => null, 'payment_url' => null,
                    'error' => 'Payment gateway returned an unexpected response.'];
        }
        return ['ok' => true, 'pidx' => $data['pidx'],
                'payment_url' => $data['payment_url'], 'error' => null];
    }

    /**
     * Server-side lookup by pidx. Returns Khalti's raw status string
     * (Completed | Pending | Refunded | Expired | User canceled | ...).
     * @return array ['ok'=>bool, 'status'=>string, 'transaction_id'=>?string, 'total_amount'=>?int, 'error'=>?string]
     *              total_amount is the gateway-reported amount in PAISA, when present.
     */
    public static function lookup($pidx) {
        // Docs: lookup is POST {pidx} (application/json). NOTE: Expired/User canceled
        // responses return HTTP 400 WITH a valid status payload — they are real
        // lookup answers, not transport failures.
        $ch = curl_init(KHALTI_BASE_URL . 'epayment/lookup/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['pidx' => $pidx]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Key ' . KHALTI_SECRET_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || ($code !== 200 && $code !== 400)) {
            error_log("[KhaltiPayment] lookup failed: HTTP=$code err=$err");
            return ['ok' => false, 'status' => 'Unknown', 'total_amount' => null,
                    'transaction_id' => null, 'error' => 'Payment gateway unreachable.'];
        }
        $data = json_decode($body, true);
        if (!isset($data['status'])) {
            error_log("[KhaltiPayment] lookup unexpected body: $body");
            return ['ok' => false, 'status' => 'Unknown', 'total_amount' => null,
                    'transaction_id' => null, 'error' => 'Unexpected gateway response.'];
        }
        return ['ok' => true, 'status' => $data['status'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'total_amount' => isset($data['total_amount']) ? (int) $data['total_amount'] : null,
                'error' => null];
    }
}