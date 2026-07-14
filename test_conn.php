<?php
function try_connect($host) {
    $port = 3306;
    $dbname = 'ev_charging_db';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        return "OK - connected via $host";
    } catch (PDOException $e) {
        return "FAIL via $host: " . $e->getMessage();
    }
}
echo "localhost  => " . try_connect('localhost') . "\n";
echo "127.0.0.1  => " . try_connect('127.0.0.1') . "\n";