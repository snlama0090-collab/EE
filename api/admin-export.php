<?php
/**
 * Admin CSV export — one endpoint serving the Export buttons of
 * users/customers/orders/analytics/stations admin sections.
 *
 * Each type mirrors the EXACT query + limit of the admin_sections page it
 * serves, so the CSV is the displayed table, not a different slice.
 * Filters (q / role / status / approval) mirror the client-side filter
 * pills + search box on those pages; values are whitelist-validated.
 *
 * Auth: same gate as every admin_sections page — Auth::requireUserType('admin')
 * (guest -> 302 to login, authenticated non-admin -> 403).
 *
 * Rate limiting: shared per-IP throttle via api/_rate_limit.php, same as every
 * other api/*.php endpoint. Defense-in-depth: caps CSV exfiltration at
 * API_RATE_LIMIT_REQUESTS (100) per IP per hour if an admin session were
 * compromised. Skipped automatically when ENV !== 'production' (see
 * ApiRateLimiter), so the integration suite is unaffected.
 *
 * ponytail: standard shared cap, not a stricter export-specific one — the
 * limiter's api_rate_limits table is IP-keyed only (no endpoint dimension),
 * so a per-endpoint cap would need schema changes; and each export is
 * already bounded (~100-200 rows) by the mirrored page LIMITs.
 */
require_once '../app/config/config.php';
require_once __DIR__ . '/_rate_limit.php';
require_once '../app/helpers/Auth.php';

Auth::requireUserType('admin');

$type     = $_GET['type'] ?? '';
$q        = trim($_GET['q'] ?? '');
$role     = in_array($_GET['role'] ?? '', ['driver', 'owner'], true) ? $_GET['role'] : '';
$status   = in_array($_GET['status'] ?? '', ['active', 'inactive', 'charging', 'completed', 'cancelled'], true) ? $_GET['status'] : '';
$approval = in_array($_GET['approval'] ?? '', ['approved', 'pending', 'rejected'], true) ? $_GET['approval'] : '';

// LIKE pattern: escape wildcards so "50%" doesn't match "50x%"
$like = $q !== '' ? '%' . addcslashes($q, "\\%_") . '%' : '';

$db   = getDB();
$headers = [];
$rows    = [];

switch ($type) {
    case 'users':
        // Page: 50 newest drivers + 50 newest owners, merged, newest first.
        // ponytail: two queries + PHP merge mirrors users.php exactly — MariaDB
        // rejects ORDER BY/LIMIT inside UNION operands of a derived table (1064).
        $rows = [];
        if ($role !== 'owner') {
            $w = ''; $p = [];
            if ($status !== '') { $w .= ' AND status = ?'; $p[] = $status; }
            if ($like !== '')   { $w .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)'; array_push($p, $like, $like, $like); }
            $st = $db->prepare("SELECT name, email, 'driver' AS role, phone, NULL AS warning_count, status, created_at FROM users WHERE 1=1$w ORDER BY created_at DESC LIMIT 50");
            $st->execute($p);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($role !== 'driver') {
            $w = ''; $p = [];
            if ($status !== '') { $w .= ' AND status = ?'; $p[] = $status; }
            if ($like !== '')   { $w .= ' AND (company_name LIKE ? OR email LIKE ? OR phone LIKE ?)'; array_push($p, $like, $like, $like); }
            $st = $db->prepare("SELECT company_name AS name, email, 'owner' AS role, phone, warning_count, status, created_at FROM owners WHERE 1=1$w ORDER BY created_at DESC LIMIT 50");
            $st->execute($p);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($rows, function ($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
        $headers = ['Name', 'Email', 'Role', 'Phone', 'Warnings', 'Status', 'Joined'];
        $fmt = function ($r) {
            return [$r['name'], $r['email'], $r['role'], $r['phone'] ?? '', $r['role'] === 'owner' ? (int) $r['warning_count'] : '', $r['status'], date('M d, Y', strtotime($r['created_at']))];
        };
        break;

    case 'customers':
        $w = ''; $p = [];
        if ($status !== '') { $w .= ' AND status = ?'; $p[] = $status; }
        if ($like !== '')   { $w .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR car_model LIKE ?)'; array_push($p, $like, $like, $like, $like); }
        $sql = "SELECT name, email, phone, car_model, charger_preference, status, created_at FROM users WHERE 1=1$w ORDER BY created_at DESC LIMIT 100";
        $params = $p;
        $headers = ['Name', 'Email', 'Phone', 'Vehicle', 'Charger Pref', 'Status', 'Joined'];
        $fmt = function ($r) {
            return [$r['name'], $r['email'], $r['phone'] ?? '', $r['car_model'] ?? '', $r['charger_preference'] ? str_replace('_', ' ', $r['charger_preference']) : 'Any', $r['status'], date('M d, Y', strtotime($r['created_at']))];
        };
        break;


    case 'orders':
        $w = ''; $p = [];
        if ($status !== '') { $w .= ' AND b.status = ?'; $p[] = $status; }
        if ($like !== '')   { $w .= ' AND (u.name LIKE ? OR u.email LIKE ? OR s.name LIKE ? OR c.charger_type LIKE ?)'; array_push($p, $like, $like, $like, $like); }
        $sql = "SELECT u.name AS user_name, u.email AS user_email, s.name AS station_name,
                       c.charger_type, c.wattage_kw, b.status, b.estimated_total_cost,
                       b.calculated_charge_time_minutes, cs.kwh_consumed, cs.actual_charge_time_minutes, b.created_at
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                JOIN stations s ON c.station_id = s.id
                JOIN users u ON b.user_id = u.id
                LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
                WHERE 1=1$w
                ORDER BY b.created_at DESC
                LIMIT 100";
        $params = $p;
        $headers = ['User', 'User Email', 'Station', 'Charger', 'Duration', 'Cost', 'Status', 'Date'];
        $fmt = function ($r) {
            $duration = ($r['status'] === 'completed' && $r['actual_charge_time_minutes'])
                ? $r['actual_charge_time_minutes'] . ' min / ' . number_format((float) $r['kwh_consumed'], 1) . ' kWh'
                : $r['calculated_charge_time_minutes'] . ' min (est)';
            return [$r['user_name'], $r['user_email'], $r['station_name'], $r['charger_type'] . ' (' . $r['wattage_kw'] . 'kW)', $duration, number_format((float) ($r['estimated_total_cost'] ?? 0), 2), $r['status'], date('M d, H:i', strtotime($r['created_at']))];
        };
        break;

    case 'stations':
        $w = ''; $p = [];
        if ($approval !== '') { $w .= ' AND s.approval_status = ?'; $p[] = $approval; }
        if ($like !== '')     { $w .= ' AND (s.name LIKE ? OR o.company_name LIKE ? OR s.city LIKE ?)'; array_push($p, $like, $like, $like); }
        $sql = "SELECT s.name, o.company_name, s.city, s.num_chargers, s.is_active, s.approval_status, s.deactivated_at, s.deactivated_by
                FROM stations s
                JOIN owners o ON s.owner_id = o.id
                WHERE 1=1$w
                ORDER BY s.created_at DESC";
        $params = $p;
        $headers = ['Station', 'Owner', 'City', 'Chargers', 'Status', 'Approval', 'Deactivated'];
        $fmt = function ($r) {
            return [$r['name'], $r['company_name'], $r['city'] ?? '', (int) $r['num_chargers'], $r['is_active'] ? 'Active' : 'Inactive', $r['approval_status'],
                    $r['deactivated_at'] ? 'Deactivated by ' . ($r['deactivated_by'] ?? 'unknown') . ' - ' . date('M d, Y', strtotime($r['deactivated_at'])) : ''];
        };
        break;

    case 'analytics':
        // The displayed "Bookings per Day" table — daily rollup straight from bookings.
        $sql = "SELECT DATE(created_at) AS day, COUNT(*) AS bookings, COALESCE(SUM(payment_amount), 0) AS revenue
                FROM bookings
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY day DESC";
        $params = [];
        $headers = ['Date', 'Bookings', 'Revenue (NPR)'];
        $fmt = function ($r) {
            return [date('M d, Y', strtotime($r['day'])), (int) $r['bookings'], number_format((float) $r['revenue'], 2)];
        };
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unknown export type']);
        exit;
}

if ($type !== 'users') { // users case already fetched its rows (see comment there)
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filename = $type . '-export-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $fmt($row));
}
fclose($out);
