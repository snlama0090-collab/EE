<?php
$data = json_encode([
    "user_type" => "driver",
    "name" => "Test User",
    "email" => "testuser123@example.com",
    "phone" => "9765021922",
    "car_model" => "tata nexon ev",
    "battery_capacity" => "70",
    "charger_preference" => "dc_fast",
    "password" => "TestPass123",
    "confirm_password" => "TestPass123"
]);
$ch = curl_init('http://localhost/EE/api/auth/register.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
    echo "CURL ERROR: " . curl_error($ch) . "\n";
}
echo "RAW RESPONSE START>>>\n" . $response . "\n<<<RAW RESPONSE END\n";
curl_close($ch);