<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Fetch driver bookings
$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, s.address, s.city,
           c.charger_type, c.wattage_kw, 
           cs.kwh_consumed, cs.actual_charge_time_minutes, cs.start_time as session_start
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 50
");
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();
?>

<div class="bookings-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2>📋 My Bookings History</h2>
        <button class="btn btn-secondary" onclick="loadSection('bookings')">
            <i class="fas fa-sync"></i> Refresh History
        </button>
    </div>

    <div class="dashboard-section-card">
        <?php if (count($bookings) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Station / Location</th>
                            <th>Charger Specifications</th>
                            <th>Estimated Duration</th>
                            <th>Cost / Billing</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): 
                            $status = $booking['status'];
                            $badge = 'badge-info';
                            if ($status === 'completed') $badge = 'badge-success';
                            elseif ($status === 'cancelled') $badge = 'badge-danger';
                            elseif ($status === 'charging') $badge = 'badge-warning';
                        ?>
                        <tr>
                            <td>#<strong><?php echo $booking['id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($booking['station_name']); ?></strong>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 2px;">
                                    <?php echo htmlspecialchars($booking['address']); ?>, <?php echo htmlspecialchars($booking['city']); ?>
                                </div>
                            </td>
                            <td>
                                <div>⚡ <?php echo htmlspecialchars($booking['charger_type']); ?></div>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 2px;">
                                    Wattage: <?php echo $booking['wattage_kw']; ?> kW
                                </div>
                            </td>
                            <td>
                                <?php if ($status === 'completed' && $booking['actual_charge_time_minutes']): ?>
                                    <div>Duration: <strong><?php echo $booking['actual_charge_time_minutes']; ?> mins</strong></div>
                                    <div style="font-size: 11px; color: var(--secondary); margin-top: 2px;">
                                        Energy: <?php echo number_format($booking['kwh_consumed'], 1); ?> kWh
                                    </div>
                                <?php else: ?>
                                    <div>Est: <strong><?php echo $booking['calculated_charge_time_minutes']; ?> mins</strong></div>
                                    <div style="font-size: 11px; color: var(--gray); margin-top: 2px;">
                                        Start Bat: <?php echo $booking['car_current_battery_percent']; ?>%
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status === 'completed' && $booking['payment_amount']): ?>
                                    <div style="color: var(--secondary); font-weight: 700;">NPR <?php echo number_format($booking['payment_amount'], 2); ?></div>
                                    <div style="font-size: 10px; color: var(--secondary); font-weight: 600;">PAID</div>
                                <?php else: ?>
                                    <div style="color: var(--dark); font-weight: 600;">NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></div>
                                    <div style="font-size: 10px; color: var(--gray);">ESTIMATE</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span>
                            </td>
                            <td>
                                <?php if ($status === 'booked'): ?>
                                    <button class="btn btn-danger btn-sm" onclick="cancelBooking(<?php echo $booking['id']; ?>)">
                                        Cancel
                                    </button>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--gray);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--gray);">
                <i class="fas fa-history" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                <h3>No Bookings Found</h3>
                <p>You have not made any bookings yet.</p>
                <button class="btn btn-primary btn-sm" style="margin-top: 16px;" onclick="loadSection('find-stations')">
                    Book Your First Session
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function cancelBooking(id) {
        showConfirm('Are you sure you want to cancel this reservation?', function() {
            doCancelBooking(id);
        }, { confirmLabel: 'Cancel Reservation', confirmClass: 'btn-danger' });
    }

    async function doCancelBooking(id) {
        try {
            const response = await fetch(`../../api/bookings.php?id=${id}`, {
                method: 'DELETE'
            });
            const result = await response.json();
            
            if (result.status === 'success') {
                showAlert('Reservation cancelled successfully.', 'success');
                loadSection('bookings');
            } else {
                showAlert(result.message || 'Failed to cancel reservation.', 'error');
            }
        } catch (e) {
            showAlert('Network error. Try again.', 'error');
        }
    }
</script>
