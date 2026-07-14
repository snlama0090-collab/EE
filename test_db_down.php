<?php
header('Content-Type: application/json');
require_once 'app/config/config.php';

// This will trigger getDB() → Database::connect() → PDO exception → our new catch block
$db = Database::getInstance();
$db->connect();