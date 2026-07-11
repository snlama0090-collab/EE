<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

Auth::logout();

// Check if redirect parameter is provided, default to homepage
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : '../index.html';

header('Location: ' . $redirect);
exit;
?>