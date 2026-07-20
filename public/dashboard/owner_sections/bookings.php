<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Get bookings for owner's stations
$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, c.charger_number, c.charger_type, c.wattage_kw, 
           u.name as user_name, u.phone as user_phone, cs.kwh_consumed, 
           cs.start_time as session_start, cs.end_time as session_end
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
    WHERE s.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 100
");
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();
?>

<div class="bookings-container">
    <div class="listing-header">
        <div class="listing-title">
            <h1><i class="fas fa-clipboard-list" style="margin-right:8px;color:var(--muted-foreground);"></i> Reservation Logs & Active Sessions</h1>
            <p>Monitor and manage all booking activity across your stations</p>
        </div>
        <div class="listing-actions">
            <button class="btn btn-secondary" onclick="loadSection('bookings')">
                <i class="fas fa-sync"></i> Refresh List
            </button>
        </div>
    </div>

    <div class="listing-table">
        <?php if (count($bookings) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Driver Details</th>
                        <th>Station / Charger</th>
                        <th>Energy Params</th>
                        <th>Cost Summary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): 
                        $status = $booking['status'];
                        $status_badge = 'badge-info';
                        if ($status === 'completed') $status_badge = 'badge-success';
                        elseif ($status === 'cancelled') $status_badge = 'badge-danger';
                        elseif ($status === 'charging') $status_badge = 'badge-warning';
                    ?>
                    <tr>
                        <td>
                            <div class="cell-avatar">
                                <div class="avatar"><?php echo strtoupper(substr($booking['user_name'], 0, 1)); ?></div>
                                <div class="info">
                                    <span class="name"><?php echo htmlspecialchars($booking['user_name']); ?></span>
                                    <span class="sub"><i class="fas fa-phone" style="margin-right:4px;"></i> <?php echo htmlspecialchars($booking['user_phone'] ?? 'N/A'); ?> · #<?php echo $booking['id']; ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($booking['station_name']); ?></div>
                            <div style="font-size: 11px; color: var(--muted-foreground); margin-top: 2px;">
                                <span class="charger-badge">#<?php echo $booking['charger_number']; ?></span>
                                <?php echo htmlspecialchars($booking['charger_type']); ?> · <?php echo $booking['wattage_kw']; ?>kW
                            </div>
                        </td>
                        <td>
                            <div><span style="font-size: 11px; color: var(--muted-foreground);">Start Bat</span> <strong style="color: var(--success);"><?php echo $booking['car_current_battery_percent']; ?>%</strong></div>
                            <?php if ($status === 'completed'): ?>
                                <div style="font-size: 11px; color: var(--muted-foreground); margin-top: 4px;">
                                    <span>Consumed <strong style="color: var(--foreground);"><?php echo number_format($booking['kwh_consumed'], 2); ?> kWh</strong></span>
                                </div>
                            <?php elseif ($status === 'charging'): ?>
                                <div style="font-size: 11px; color: var(--warning); margin-top: 4px; font-weight: 600;">
                                    <i class="fas fa-bolt"></i> In Progress
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="amount">Est. NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></div>
                            <?php if ($booking['payment_amount']): ?>
                                <div style="font-size: 12px; color: var(--success); font-weight: 600; margin-top: 2px;">
                                    Paid: NPR <?php echo number_format($booking['payment_amount'], 2); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>"><?php echo $status; ?></span>
                        </td>
                        <td>
                            <?php if ($status === 'booked'): ?>
                                <button class="btn btn-primary btn-sm" onclick="updateSession(<?php echo $booking['id']; ?>, 'start_session')">
                                    <i class="fas fa-play"></i> Start
                                </button>
                            <?php elseif ($status === 'charging'): ?>
                                <button class="btn btn-sm" style="background:linear-gradient(135deg, #FF9500 0%, #E68500 100%); color: #fff; border: none;" onclick="updateSession(<?php echo $booking['id']; ?>, 'complete_session')">
                                    <i class="fas fa-stop"></i> Bill
                                </button>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--muted-foreground);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--gray);">
                <i class="fas fa-receipt" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                <h3>No Reservations Found</h3>
                <p>When drivers book chargers at your stations, they will show up here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

