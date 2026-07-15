<?php
/**
 * Authentication Helper
 * Handles user sessions, login, logout, and permission checks
 */

require_once __DIR__ . '/../config/config.php';

class Auth {
    
    /**
     * Start a new session for user
     */
    public static function startSession($user_id, $user_type, $remember = false) {
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_type'] = $user_type;
        $_SESSION['login_time'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        
        if ($remember) {
            setcookie('remember_token', self::generateRememberToken($user_id, $user_type), 
                      time() + (30 * 24 * 60 * 60), '/', '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
        }
        
        log_message('INFO', "User $user_id ($user_type) logged in");
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
    }
    
    /**
     * Get current user ID
     */
    public static function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user type
     */
    public static function getCurrentUserType() {
        return $_SESSION['user_type'] ?? null;
    }
    
    /**
     * Check if user is of specific type
     */
    public static function isUserType($type) {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === $type;
    }
    
    /**
     * Check if session is valid and not expired
     */
    public static function isSessionValid() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        // Check session timeout
        if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }
        
        // ponytail: relaxed User-Agent check — shared hosting proxies (Cloudflare, etc.)
        // can modify headers mid-flight, causing false-positive logouts. Warn + update instead.
        if ($_SERVER['HTTP_USER_AGENT'] !== $_SESSION['user_agent']) {
            log_message('WARNING', "User-Agent changed for user " . $_SESSION['user_id'] . " — possible proxy, updating silently");
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }
        
        return true;
    }
    
    /**
     * Require user to be logged in (redirect if not)
     */
    public static function requireLogin() {
        if (!self::isSessionValid()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Require specific user type
     */
    public static function requireUserType($type) {
        self::requireLogin();
        
        if (!self::isUserType($type)) {
            http_response_code(403);
            die('Access Denied');
        }
    }
    
    /**
     * Logout user
     */
    public static function logout() {
        $user_id = $_SESSION['user_id'] ?? null;
        
        session_destroy();
        
        // Clear remember token
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY);
        }
        
        log_message('INFO', "User $user_id logged out");
    }
    
    /**
     * Generate remember token
     */
    private static function generateRememberToken($user_id, $user_type) {
        $token = generate_token();
        
        // Store in database
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, user_type, expires_at) 
                            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $stmt->execute([$user_id, hash('sha256', $token), $user_type]);
        
        return $token;
    }
    
    /**
     * Verify remember token
     */
    public static function verifyRememberToken($token) {
        $db = getDB();
        $hashed = hash('sha256', $token);
        
        $stmt = $db->prepare("SELECT user_id, user_type FROM remember_tokens 
                            WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$hashed]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Delete used token
            $db->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([$hashed]);
            
            // Start new session
            self::startSession($result['user_id'], $result['user_type']);
            return true;
        }
        
        return false;
    }
    
}

// Initialize session if not already done
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check and validate session on every page load
if (Auth::isLoggedIn() && !Auth::isSessionValid()) {
    Auth::logout();
    header('Location: ' . APP_URL . '/login.php?session=expired');
    exit;
}

// Auto-login with remember token if available
if (!Auth::isLoggedIn() && isset($_COOKIE['remember_token'])) {
    Auth::verifyRememberToken($_COOKIE['remember_token']);
}

?>