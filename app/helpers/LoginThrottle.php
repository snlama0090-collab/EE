<?php
/**
 * Login Throttle Helper
 * Two-layer brute-force protection for api/auth/login.php backed by the
 * login_attempts table:
 *   Layer 1: failures per email+IP pair    -> LOGIN_MAX_ATTEMPTS
 *   Layer 2: failures per IP across emails -> LOGIN_IP_MAX_ATTEMPTS (spray net)
 * Both layers share LOGIN_LOCKOUT_WINDOW as a sliding time window.
 */

require_once __DIR__ . '/../config/config.php';

class LoginThrottle {

    /**
     * Combined verdict of BOTH layers. Called BEFORE credential lookup so a
     * locked response never reveals whether the account exists.
     *
     * @return bool true = locked out, false = allowed to proceed
     */
    public static function check($db, $email, $ip) {
        // ponytail: lazy cleanup instead of a cron — expired rows pruned on every check
        self::cleanup($db);
        $w = (int)LOGIN_LOCKOUT_WINDOW;

        // Layer 1: this exact email+IP pair
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts
                              WHERE email = ? AND ip_address = ?
                                AND attempted_at > NOW() - INTERVAL $w SECOND");
        $stmt->execute([$email, $ip]);
        $pairFails = (int)$stmt->fetchColumn();

        // Layer 2: this IP across ALL emails (multi-account password-spray net)
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts
                              WHERE ip_address = ?
                                AND attempted_at > NOW() - INTERVAL $w SECOND");
        $stmt->execute([$ip]);
        $ipFails = (int)$stmt->fetchColumn();

        return $pairFails >= LOGIN_MAX_ATTEMPTS || $ipFails >= LOGIN_IP_MAX_ATTEMPTS;
    }

    /**
     * Record exactly one failed login attempt.
     */
    public static function recordFailure($db, $email, $ip, $user_type) {
        $stmt = $db->prepare(
            "INSERT INTO login_attempts (email, ip_address, user_type) VALUES (?, ?, ?)"
        );
        return $stmt->execute([$email, $ip, $user_type]);
    }

    /**
     * Clear failures for ONE email+IP pair after a successful login.
     * Deliberately pair-scoped, never IP-wide: a reset may only prune the
     * legitimate user's OWN rows, never an attacker's spray rows against
     * other emails from the same IP.
     */
    public static function reset($db, $email, $ip) {
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
        return $stmt->execute([$email, $ip]);
    }

    private static function cleanup($db) {
        $w = (int)LOGIN_LOCKOUT_WINDOW;
        try {
            $db->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL $w SECOND");
        } catch (Throwable $e) { /* non-fatal */ }
    }
}

?>