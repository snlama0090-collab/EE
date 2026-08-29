<?php
/**
 * EV Charging Station - Application Configuration
 * 
 * This file contains all configuration settings for the application
 * including database credentials, API keys, and other settings.
 */

// ===== ENVIRONMENT SETUP =====
define('ENV', 'development'); // development, production
define('DEBUG', true);

// ===== LOAD .ENV CREDENTIALS =====
require_once __DIR__ . '/../../vendor/autoload.php';
$envDir = dirname(__DIR__, 2); // EE project root
if (file_exists($envDir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($envDir);
    $dotenv->load();
}

define('GMAIL_USER', $_ENV['GMAIL_USER'] ?? '');
define('GMAIL_APP_PASSWORD', $_ENV['GMAIL_APP_PASSWORD'] ?? '');

/* ===== PAYMENT GATEWAY (Khalti) ===== */
define('PAYMENT_DRIVER', $_ENV['PAYMENT_DRIVER'] ?? 'simulated');   // simulated | khalti
define('KHALTI_BASE_URL', $_ENV['KHALTI_BASE_URL'] ?? 'https://dev.khalti.com/api/v2/'); // live: https://khalti.com/api/v2/
define('KHALTI_SECRET_KEY', $_ENV['KHALTI_SECRET_KEY'] ?? '');

// ===== DATABASE CONFIGURATION =====
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Leave empty for localhost without password
define('DB_NAME', 'ev_charging_db');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// ===== APPLICATION PATHS =====
// ponytail: __FILE__ arrives with mixed separators from require chains, so double-dirname
// resolved to EE/app instead of EE root — uploads/logs silently landed inside app/. __DIR__
// is natively normalized, making this robust on Windows and Linux.
define('APP_ROOT', dirname(__DIR__, 2));
define('PUBLIC_PATH', APP_ROOT . '/public');
define('LOGS_PATH', APP_ROOT . '/logs');

// ===== APPLICATION URLs =====
define('APP_URL', 'http://localhost/EE');

// ===== SESSION CONFIGURATION =====
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('SESSION_COOKIE_SECURE', false); // Set to true for HTTPS only
define('SESSION_COOKIE_HTTPONLY', true);
define('SESSION_COOKIE_SAMESITE', 'Lax');

// ===== SECURITY CONFIGURATION =====
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_HASH_COST', 10);

// ===== BOOKING CONFIGURATION =====
define('BOOKING_ARRIVAL_DEADLINE_MINUTES', 20); // Minutes to reach station

// ===== CHARGING CONFIGURATION =====
define('ELECTRICITY_RATE_PER_KWH', 10); // In NPR
define('BOOKING_BASE_FEE', 50); // In NPR

// ===== LOCATION CONFIGURATION =====
define('DEFAULT_SEARCH_RADIUS_KM', 5);

// ===== FILE UPLOAD CONFIGURATION =====
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB

// ===== API CONFIGURATION =====
define('API_RATE_LIMIT_REQUESTS', 100); // requests per hour
define('API_RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// ===== LOGIN THROTTLING (brute-force protection, enforced by app/helpers/LoginThrottle.php) =====
define('LOGIN_MAX_ATTEMPTS', 5);      // failed logins per email+IP before that pair locks out
define('LOGIN_IP_MAX_ATTEMPTS', 20);  // failed logins per IP across ALL emails (spray net)
define('LOGIN_LOCKOUT_WINDOW', 900);  // lockout window in seconds (shared by both layers)

// ===== LOGGING CONFIGURATION =====
define('LOG_PATH', LOGS_PATH . '/app.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 MB

// ===== VALIDATION CONFIGURATION =====
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_NUMBERS', true);
define('PASSWORD_REQUIRE_SPECIAL_CHARS', false);

define('NAME_MIN_LENGTH', 2);
define('NAME_MAX_LENGTH', 100);

// ===== FEATURE FLAGS =====
define('GOOGLE_CLIENT_ID', '34761081203-1gjrigkese1k489kc5gnap2kvvfro0he.apps.googleusercontent.com');

// ===== EMAIL (OTP via Gmail SMTP) =====
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS', 5);

// ===== TIMEZONE =====
date_default_timezone_set('Asia/Kathmandu');

// ===== ERROR REPORTING =====
if (ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

ini_set('error_log', LOG_PATH);

// ===== DATABASE CONNECTION CLASS =====
class Database {
    private static $instance = null;
    private $connection = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function connect() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            return $this->connection;
        } catch (PDOException $e) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Database connection failed. Please try again later.'
            ]);
            error_log('DB connection failed: ' . $e->getMessage());
            exit;
        }
    }

    public function getConnection() {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    public function disconnect() {
        $this->connection = null;
    }
}

// ===== HELPER FUNCTIONS =====

/**
 * Get database connection
 */
function getDB() {
    return Database::getInstance()->getConnection();
}

/**
 * Log a message
 */
function log_message($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] $message\n";
    
    if (!is_dir(LOGS_PATH)) {
        mkdir(LOGS_PATH, 0755, true);
    }
    
    // Standing issue #22: enforce LOG_MAX_SIZE. Rename to a timestamped archive
    // (forensic history kept, newest 5 archived files retained, older pruned).
    // PHP error_log() writes to the same file bypass this gate and are swept up
    // by the next log_message() call. Windows rename races: on failure we simply
    // keep appending and rotate on a later call - no data loss either way.
    if (file_exists(LOG_PATH) && filesize(LOG_PATH) >= LOG_MAX_SIZE) {
        @rename(LOG_PATH, LOGS_PATH . '/app-' . date('Ymd-His') . '.log');
        $archives = glob(LOGS_PATH . '/app-*.log');
        if (is_array($archives) && count($archives) > 5) {
            sort($archives);
            foreach (array_slice($archives, 0, count($archives) - 5) as $old) @unlink($old);
        }
    }
    file_put_contents(LOG_PATH, $log_message, FILE_APPEND);
}

/**
 * Hash a password
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_HASH_ALGO, ['cost' => PASSWORD_HASH_COST]);
}

/**
 * Verify a password
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate Gmail address only
 */
function validate_gmail($email) {
    return preg_match('/@gmail\.com$/i', trim($email)) === 1;
}

/**
 * Validate Nepali phone number
 * Accepts: +977 98XXXXXXXX, +97798XXXXXXXX, 98XXXXXXXX, 97XXXXXXXX
 */
function validate_phone($phone) {
    return preg_match('/^(?:\+977\s?)?9[78]\d{8}$/', trim($phone)) === 1;
}

/**
 * Generate random token
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Format currency
 */
function format_currency($amount) {
    return '₹' . number_format($amount, 2);
}

/**
 * Send JSON response
 */
function json_response($status, $message = '', $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}


?>