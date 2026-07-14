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

// ===== DATABASE CONFIGURATION =====
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Leave empty for localhost without password
define('DB_NAME', 'ev_charging_db');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// ===== APPLICATION PATHS =====
define('APP_ROOT', dirname(dirname(__FILE__)));
define('APP_PATH', APP_ROOT . '/app');
define('PUBLIC_PATH', APP_ROOT . '/public');
define('UPLOADS_PATH', PUBLIC_PATH . '/assets/uploads');
define('LOGS_PATH', APP_ROOT . '/logs');

// ===== APPLICATION URLs =====
define('APP_URL', 'http://localhost/ev-charging-station');
define('API_URL', APP_URL . '/api');

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
define('BOOKING_BASE_FEE', 20); // In NPR

// ===== LOCATION CONFIGURATION =====
define('DEFAULT_LATITUDE', 27.7172); // Kathmandu
define('DEFAULT_LONGITUDE', 85.3240);
define('DEFAULT_SEARCH_RADIUS_KM', 5);
define('MAX_SEARCH_RADIUS_KM', 50);

// ===== FILE UPLOAD CONFIGURATION =====
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB

// ===== PAGINATION CONFIGURATION =====
define('ITEMS_PER_PAGE', 20);

// ===== API CONFIGURATION =====
define('API_RATE_LIMIT_ENABLED', true);
define('API_RATE_LIMIT_REQUESTS', 100); // requests per hour
define('API_RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// ===== LOGGING CONFIGURATION =====
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
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
define('GOOGLE_CLIENT_ID', '34761081203-1t4na3klvstmlgevj3rq3o9bdagsm2rs.apps.googleusercontent.com');

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