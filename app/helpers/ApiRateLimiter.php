<?php
/**
 * API Rate Limiter — general per-IP request throttling for all api/*.php endpoints.
 *
 * Backed by the api_rate_limits table. Uses a fixed window: counts requests
 * from an IP within the last API_RATE_LIMIT_WINDOW seconds, prunes when the
 * window expires. Mirrors LoginThrottle's structure for consistency.
 *
 * Login endpoint is exempt — LoginThrottle already covers login-specific
 * brute-force protection with tighter, pair-scoped limits. Applying both
 * would risk locking out legitimate users who mistype passwords.
 *
 * In development/testing (ENV !== 'production'), rate limiting is skipped
 * entirely so the integration suite (which makes many rapid API calls) is
 * not affected.
 */

require_once __DIR__ . '/../config/config.php';

class ApiRateLimiter
{
    /**
     * Check if the current IP has exceeded the rate limit.
     *
     * @param PDO    $db              Database connection
     * @param string $ip               Client IP address
     * @param bool   $forceProduction  When true, bypass the ENV check (for testing only)
     * @return array      ['limited' => bool, 'retry_after' => int seconds]
     */
    public static function check($db, $ip, $forceProduction = false)
    {
        // Skip rate limiting in non-production environments (development/testing)
        // unless explicitly forced (testing only)
        if (!$forceProduction && ENV !== 'production') {
            return ['limited' => false, 'retry_after' => 0];
        }

        // Loopback + RFC1918 private-range exemption: this deployment is a local
        // XAMPP instance not exposed to the public internet, and the integration
        // suite fires hundreds of rapid API calls from 127.0.0.1. filter_var()
        // with both flags returns false exactly for loopback (127.0.0.1, ::1),
        // private (10/8, 172.16/12, 192.168/16, fc00::/7) and other non-routable
        // addresses — i.e. everything that can never be an internet attacker.
        // Public IPs still hit the full query + cap below.
        if ($ip === '127.0.0.1' || $ip === '::1' ||
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['limited' => false, 'retry_after' => 0];
        }

        // Lazy cleanup of expired rows
        self::cleanup($db);

        $w = (int) API_RATE_LIMIT_WINDOW;
        $limit = (int) API_RATE_LIMIT_REQUESTS;

        // Count requests from this IP within the current window
        $stmt = $db->prepare("SELECT COUNT(*) FROM api_rate_limits
                              WHERE ip_address = ?
                                AND requested_at > NOW() - INTERVAL $w SECOND");
        $stmt->execute([$ip]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $limit) {
            // Find the oldest request in the window to calculate retry-after
            $stmt = $db->prepare("SELECT requested_at FROM api_rate_limits
                                  WHERE ip_address = ?
                                    AND requested_at > NOW() - INTERVAL $w SECOND
                                  ORDER BY requested_at ASC LIMIT 1");
            $stmt->execute([$ip]);
            $oldest = $stmt->fetchColumn();
            if ($oldest) {
                $retryAfter = $w - (time() - strtotime($oldest));
                $retryAfter = max(1, $retryAfter);
            } else {
                $retryAfter = $w;
            }
            return ['limited' => true, 'retry_after' => $retryAfter];
        }

        return ['limited' => false, 'retry_after' => 0];
    }

    /**
     * Record a request from the current IP.
     *
     * @param PDO    $db              Database connection
     * @param string $ip               Client IP address
     * @param bool   $forceProduction  When true, bypass the ENV check (for testing only)
     */
    public static function record($db, $ip, $forceProduction = false)
    {
        if (!$forceProduction && ENV !== 'production') {
            return;
        }

        $stmt = $db->prepare("INSERT INTO api_rate_limits (ip_address) VALUES (?)");
        $stmt->execute([$ip]);
    }

    /**
     * Prune expired rows from the rate limits table.
     */
    private static function cleanup($db)
    {
        $w = (int) API_RATE_LIMIT_WINDOW;
        try {
            $db->exec("DELETE FROM api_rate_limits WHERE requested_at < NOW() - INTERVAL $w SECOND");
        } catch (Throwable $e) { /* non-fatal */ }
    }
}

?>
