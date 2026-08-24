<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

Auth::requireLogin();

$user_type = Auth::getCurrentUserType();
$user_id   = Auth::getCurrentUserId();
$method    = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // ── GET: list / single fetch (ownership enforced on EVERY read) ──
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            if ($user_type === 'admin') {
                $stmt = $db->prepare("SELECT * FROM support_tickets WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $db->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_type = ? AND user_id = ?");
                $stmt->execute([$id, $user_type, $user_id]);
            }
            $ticket = $stmt->fetch();
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => ['ticket' => $ticket]]);
            exit;
        }

        if ($user_type === 'admin') {
            $status  = sanitize($_GET['status'] ?? '');
            $allowed = ['open', 'in_progress', 'resolved'];
            $sql = "SELECT t.*, COALESCE(u.name, o.name) AS submitter_name,
                           COALESCE(u.email, o.email) AS submitter_email
                    FROM support_tickets t
                    LEFT JOIN users u  ON t.user_type = 'driver' AND u.id = t.user_id
                    LEFT JOIN owners o ON t.user_type = 'owner'  AND o.id = t.user_id";
            $params = [];
            if (in_array($status, $allowed, true)) {
                $sql .= " WHERE t.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY FIELD(t.status,'open','in_progress','resolved'), t.created_at DESC LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['status' => 'success', 'data' => ['tickets' => $stmt->fetchAll()]]);
            exit;
        }

        // driver / owner: own tickets only
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_type = ? AND user_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$user_type, $user_id]);
        echo json_encode(['status' => 'success', 'data' => ['tickets' => $stmt->fetchAll()]]);
        exit;
    }

    // ── POST: create / reply / set_status ──
    if ($method === 'POST') {
        Csrf::validate();

        $input  = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        if ($action === 'create') {
            if ($user_type === 'admin') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Admins do not create support tickets.']);
                exit;
            }
            $category = sanitize($input['category'] ?? 'general');
            $subject  = sanitize($input['subject'] ?? '');
            $message  = sanitize($input['message'] ?? '');
            $allowed  = ['general', 'booking', 'payment', 'station', 'other'];
            if (!in_array($category, $allowed, true)) $category = 'general';
            if ($subject === '' || mb_strlen($subject) > 150) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Subject is required (max 150 characters).']);
                exit;
            }
            if ($message === '' || mb_strlen($message) > 5000) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Message is required (max 5000 characters).']);
                exit;
            }
            $stmt = $db->prepare("INSERT INTO support_tickets (user_type, user_id, category, subject, message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_type, $user_id, $category, $subject, $message]);
            $ticket_id = intval($db->lastInsertId());

            // bell entry — admins see all activity; submitter also gets own confirmation row
            $db->prepare("INSERT INTO activity_logs (action, resource_type, resource_id, details) VALUES ('support_ticket','support_ticket',?,?)")
               ->execute([$ticket_id, 'New support ticket: ' . $subject]);

            echo json_encode(['status' => 'success', 'message' => 'Ticket submitted.', 'data' => ['ticket_id' => $ticket_id]]);
            exit;
        }

        if ($action === 'reply') {
            if ($user_type !== 'admin') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Only admins can reply to tickets.']);
                exit;
            }
            $ticket_id = intval($input['ticket_id'] ?? 0);
            $reply     = sanitize($input['reply'] ?? '');
            if ($reply === '' || mb_strlen($reply) > 5000) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Reply is required (max 5000 characters).']);
                exit;
            }
            $stmt = $db->prepare("SELECT * FROM support_tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
                exit;
            }
            $newStatus = $ticket['status'] === 'open' ? 'in_progress' : $ticket['status'];
            $db->prepare("UPDATE support_tickets SET admin_reply = ?, replied_at = NOW(), admin_id = ?, status = ? WHERE id = ?")
               ->execute([$reply, $user_id, $newStatus, $ticket_id]);

            // notify the submitter via the existing bell infrastructure
            $details = 'Support reply to "' . $ticket['subject'] . '": ' . mb_substr($reply, 0, 120);
            if ($ticket['user_type'] === 'driver') {
                $db->prepare("INSERT INTO activity_logs (admin_id, user_id, action, resource_type, resource_id, details) VALUES (?,?,'support_reply','support_ticket',?,?)")
                   ->execute([$user_id, $ticket['user_id'], $ticket_id, $details]);
            } elseif ($ticket['user_type'] === 'owner') {
                $db->prepare("INSERT INTO activity_logs (admin_id, owner_id, action, resource_type, resource_id, details) VALUES (?,?,'support_reply','support_ticket',?,?)")
                   ->execute([$user_id, $ticket['user_id'], $ticket_id, $details]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Reply sent.', 'data' => ['ticket_id' => $ticket_id, 'status' => $newStatus]]);
            exit;
        }

        if ($action === 'set_status') {
            if ($user_type !== 'admin') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Only admins can change ticket status.']);
                exit;
            }
            $ticket_id = intval($input['ticket_id'] ?? 0);
            $status    = sanitize($input['status'] ?? '');
            if (!in_array($status, ['in_progress', 'resolved'], true)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid status.']);
                exit;
            }
            $stmt = $db->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
            $stmt->execute([$status, $ticket_id]);
            echo json_encode(['status' => 'success', 'message' => 'Status updated.', 'data' => ['ticket_id' => $ticket_id, 'status' => $status]]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
} catch (Exception $e) {
    log_message('ERROR', 'Support API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}