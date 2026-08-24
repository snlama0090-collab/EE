<?php
/**
 * Notifications API — bell dropdown data + mark-as-read.
 * GET  → { status, data:{ unread_count, items:[{action,details,created_at}] } }
 * POST { action:'mark_all_read' } → marks ONLY the current viewer's rows read.
 */
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Notifications.php';
require_once '../app/helpers/Csrf.php';

Auth::requireLogin();

$user_id = Auth::getCurrentUserId();
$user_type = Auth::getCurrentUserType();

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['status' => 'success', 'data' => Notifications::summary($db, $user_type, $user_id)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate();
        $input = json_decode(file_get_contents('php://input'), true);
        if (($input['action'] ?? '') !== 'mark_all_read') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            exit;
        }
        Notifications::markAllRead($db, $user_type, $user_id);
        echo json_encode(['status' => 'success', 'data' => ['unread_count' => 0]]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log('Notifications API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load notifications.']);
}