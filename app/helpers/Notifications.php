<?php
/**
 * Notifications helper — single source of truth for notification scoping.
 * Used by api/notifications.php and all three dashboard shells.
 * Scope rules MUST mirror what each role's notifications section shows.
 */
class Notifications
{
    /**
     * WHERE fragment + params limiting activity_logs to rows visible to this viewer.
     * Returns [whereSql, params] or null for unknown roles.
     */
    public static function scope($user_type, $user_id)
    {
        if ($user_type === 'driver') {
            return [
                "al.resource_type = 'booking' AND EXISTS (SELECT 1 FROM bookings b WHERE b.id = al.resource_id AND b.user_id = :uid)",
                [':uid' => $user_id]
            ];
        }
        if ($user_type === 'owner') {
            // ponytail: scope leak fixed — owners see only rows addressed to them (was: OR any station activity)
            return ['al.owner_id = :uid', [':uid' => $user_id]];
        }
        if ($user_type === 'admin') {
            return ['TRUE', []];
        }
        return null;
    }

    /** Unread count + recent items for the bell dropdown. */
    public static function summary($db, $user_type, $user_id, $limit = 5)
    {
        $limit = max(1, (int) $limit);
        $scope = self::scope($user_type, $user_id);
        if ($scope === null) return ['unread_count' => 0, 'items' => []];
        list($where, $params) = $scope;

        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM activity_logs al WHERE $where AND al.is_read = 0");
        $stmt->execute($params);
        $unread = (int) $stmt->fetch()['c'];

        // id DESC tiebreaker: created_at has 1-second resolution, so same-second rows need stable order
        $stmt = $db->prepare("SELECT action, details, created_at FROM activity_logs al WHERE $where ORDER BY al.created_at DESC, al.id DESC LIMIT $limit");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        return ['unread_count' => $unread, 'items' => $items];
    }

    /** Mark every row visible to this viewer as read. Non-destructive. */
    public static function markAllRead($db, $user_type, $user_id)
    {
        $scope = self::scope($user_type, $user_id);
        if ($scope === null) return 0;
        list($where, $params) = $scope;

        $stmt = $db->prepare("UPDATE activity_logs al SET al.is_read = 1, al.read_at = NOW() WHERE $where AND al.is_read = 0");
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}