<?php
/**
 * Shared rate-limit check for all api/*.php endpoints.
 * Include this file after require_once '../app/config/config.php';
 *
 * Returns HTTP 429 with Retry-After header if the client IP has exceeded
 * API_RATE_LIMIT_REQUESTS within API_RATE_LIMIT_WINDOW seconds.
 */

require_once __DIR__ . '/../app/helpers/ApiRateLimiter.php';

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$db = getDB();

$rateCheck = ApiRateLimiter::check($db, $clientIp);
if ($rateCheck['limited']) {
    http_response_code(429);
    header('Retry-After: ' . $rateCheck['retry_after']);
    echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.']);
    exit;
}

// Record this request
ApiRateLimiter::record($db, $clientIp);
