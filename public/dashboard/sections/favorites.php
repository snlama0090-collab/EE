<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Handle remove favorite via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $station_id = intval($_POST['station_id'] ?? 0);
    
    try {
        $stmt = $db->prepare("DELETE FROM favorites WHERE user_id = ? AND station_id = ?");
        $stmt->execute([$user_id, $station_id]);
        echo json_encode(['status' => 'success', 'message' => 'Removed from favorites.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to remove favorite.']);
    }
    exit;
}

// Fetch favorite stations
$stmt = $db->prepare("
    SELECT f.station_id, s.name as station_name, s.address, s.city, s.latitude, s.longitude,
           s.average_rating, COUNT(c.id) as charger_count
    FROM favorites f
    JOIN stations s ON f.station_id = s.id
    LEFT JOIN chargers c ON s.id = c.station_id
    WHERE f.user_id = ?
    GROUP BY s.id
    ORDER BY f.created_at DESC
");
$stmt->execute([$user_id]);
$favorites = $stmt->fetchAll();
?>

<div class="favorites-container">
    <h2>❤️ My Favorite Stations</h2>
    <p style="color:var(--gray); margin-bottom:24px;">Quickly access and book your preferred charging locations.</p>

    <?php if (count($favorites) > 0): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <?php foreach ($favorites as $station): ?>
                <div class="station-card" style="display: flex; flex-direction: column; align-items: stretch; justify-content: space-between; border-left: 5px solid #FF6B6B;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 4px;"><?php echo htmlspecialchars($station['station_name']); ?></h3>
                            <p style="font-size: 12px; color: var(--gray);"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($station['address']); ?>, <?php echo htmlspecialchars($station['city']); ?></p>
                        </div>
                        <button onclick="removeFavorite(<?php echo $station['station_id']; ?>)" style="background: none; border: none; color: #FF3B30; cursor: pointer; font-size: 18px; padding: 4px;">
                            <i class="fas fa-heart"></i>
                        </button>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin: 16px 0; border-top: 1px dashed var(--border); padding-top: 12px;">
                        <span>⭐ <?php echo number_format($station['average_rating'] ?? 5.0, 1); ?>/5</span>
                        <span>🔌 <?php echo $station['charger_count']; ?> Chargers</span>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-primary" style="flex:1; justify-content:center;" onclick="bookFavorite(<?php echo $station['station_id']; ?>)">
                            Book Station
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dashboard-section-card" style="text-align: center; padding: 48px; color: var(--gray);">
            <i class="fas fa-heart" style="font-size: 64px; margin-bottom: 16px; opacity: 0.2; color: #FF3B30;"></i>
            <h3>No Favorites Added Yet</h3>
            <p>Go to "Find Stations", view details, and click the heart icon to save stations here.</p>
            <button class="btn btn-primary btn-sm" style="margin-top: 16px;" onclick="loadSection('find-stations')">
                Browse Stations Map
            </button>
        </div>
    <?php endif; ?>
</div>

