<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Fetch metrics
$stmt = $db->prepare("SELECT COUNT(id) as total_bookings FROM bookings WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_bookings = $stmt->fetch()['total_bookings'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(id) as total_favorites FROM favorites WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_favorites = $stmt->fetch()['total_favorites'] ?? 0;

// Fetch active bookings
$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, s.address, c.charger_number, c.charger_type, c.wattage_kw
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    WHERE b.user_id = ? AND b.status IN ('booked', 'pending_payment', 'charging')
    ORDER BY b.created_at DESC
    LIMIT 3
");
$stmt->execute([$user_id]);
$active_bookings = $stmt->fetchAll();
?>

<!-- STATS OVERVIEW CARDS -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon" style="color: #007AFF; background: rgba(0, 122, 255, 0.1);"><i class="fas fa-history"></i></div>
        <div class="metric-info">
            <h3>My Bookings</h3>
            <p><?php echo $total_bookings; ?></p>
        </div>
    </div>
    
    <div class="metric-card success">
        <div class="metric-icon" style="color: #FF3B30; background: rgba(255, 59, 48, 0.1);"><i class="fas fa-heart"></i></div>
        <div class="metric-info">
            <h3>Favorites</h3>
            <p><?php echo $total_favorites; ?> Stations</p>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
    
    <!-- EV STATE PANEL -->
    <div class="dashboard-section-card">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-car" style="margin-right:8px;color:var(--muted-foreground);"></i> My Electric Vehicle</h3>
        
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div>
                <span style="color: var(--gray); font-size: 13px;">Car Model</span>
                <div style="font-size: 16px; font-weight: 600;"><?php echo htmlspecialchars($user['car_model'] ?? 'Not Configured'); ?></div>
            </div>
            
            <div>
                <span style="color: var(--gray); font-size: 13px;">Battery Capacity</span>
                <div style="font-size: 16px; font-weight: 600;"><?php echo $user['car_full_capacity_kwh'] ? $user['car_full_capacity_kwh'] . ' kWh' : 'Not Configured'; ?></div>
            </div>

            <div>
                <span style="color: var(--gray); font-size: 13px;">Charger Preference</span>
                <div style="font-size: 16px; font-weight: 600; text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $user['charger_preference'] ?? 'Any')); ?></div>
            </div>
        </div>
    </div>

    <!-- ACTIVE RESERVATIONS -->
    <div class="dashboard-section-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3>⚡ Active Charging Reservations</h3>
            <button class="btn btn-secondary btn-sm" onclick="loadSection('bookings')">View All</button>
        </div>

        <?php if (count($active_bookings) > 0): ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <?php foreach ($active_bookings as $booking): 
                    $badge = $booking['status'] === 'charging' ? 'badge-warning' : 'badge-info';
                ?>
                <div data-booking-id="<?php echo $booking['id']; ?>" style="border: 1px solid var(--border); border-radius: 10px; padding: 16px; position: relative;">
                    <div style="position: absolute; right: 16px; top: 16px;" class="booking-status-area">
                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($booking['status']); ?></span>
                    </div>
                    <h4 style="margin-bottom: 6px; font-size: 15px;"><?php echo htmlspecialchars($booking['station_name']); ?></h4>
                    <p style="font-size: 12px; color: var(--gray); margin-bottom: 8px;"><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($booking['address']); ?></p>
                    
                    <div style="display:flex; gap: 16px; font-size: 12px; border-top: 1px dashed var(--border); padding-top: 8px; margin-top: 8px;">
                        <div>
                            <span style="color:var(--gray);">Charger:</span>
                            <strong>#<?php echo $booking['charger_number']; ?></strong>
                        </div>
                        <div>
                            <span style="color:var(--gray);">Type:</span>
                            <strong><?php echo htmlspecialchars($booking['charger_type']); ?></strong>
                        </div>
                        <div>
                            <span style="color:var(--gray);">Est. Cost:</span>
                            <strong style="color:#34C759;">NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></strong>
                        </div>
                    </div>

                    <?php if ($booking['status'] === 'booked'): ?>
                        <div style="font-size: 11px; margin-top: 8px; color: var(--danger); font-weight:600;">
                            🕒 Arrive before: <?php echo date('H:i', strtotime($booking['arrival_deadline'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                <i class="fas fa-plug" style="font-size: 48px; margin-bottom: 12px; opacity: 0.3;"></i>
                <p>No active charging sessions or bookings.</p>
                <button class="btn btn-primary btn-sm" style="margin-top: 12px;" onclick="loadSection('find-stations')">
                    Find Stations & Book
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

