<?php
// TEMPORARY DIAGNOSTIC — deleted immediately after use. Not part of the deliverable.
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';
Auth::requireUserType('driver');
header('Content-Type: text/plain');
echo 'USER_ID=' . Auth::getCurrentUserId() . "\n";
echo 'PUBLIC_PATH=' . PUBLIC_PATH . "\n";
echo 'CONFIG_FILE=' . __FILE__ . "\n";
echo 'FILES_DUMP=' . print_r($_FILES, true) . "\n";
$pfpDir = PUBLIC_PATH . "/assets/uploads/pfp";
echo 'DIR=' . $pfpDir . "\n";
echo 'IS_DIR=' . var_export(is_dir($pfpDir), true) . "\n";
echo 'IS_WRITABLE=' . var_export(is_writable($pfpDir), true) . "\n";
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $target = $pfpDir . '/probe_test.jpg';
    $result = move_uploaded_file($_FILES['avatar']['tmp_name'], $target);
    echo 'MOVE_RESULT=' . var_export($result, true) . "\n";
    echo 'EXISTS_AFTER_MOVE=' . var_export(file_exists($target), true) . "\n";
} else {
    echo 'UPLOAD_BLOCK_SKIPPED (no avatar or error != OK)' . "\n";
}