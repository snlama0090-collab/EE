<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();
?>
<h2 style="margin-bottom: 24px;"><i class="fas fa-cog"></i> Platform Settings</h2>

<div class="card" style="margin-bottom: 24px;">
    <h3><i class="fas fa-tools"></i> Configuration Controls</h3>
    <p style="color: var(--gray); margin-bottom: 20px;">Platform-level settings will be available in a future update. Below is a preview of planned controls.</p>

    <div class="table-container">
        <table>
            <thead>
                <tr><th>Setting</th><th>Current Value</th><th>Status</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Rate Limiting</td>
                    <td><?php echo API_RATE_LIMIT_REQUESTS; ?> requests / <?php echo API_RATE_LIMIT_WINDOW / 3600; ?>h</td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
                <tr>
                    <td>Booking Base Fee</td>
                    <td>NPR <?php echo BOOKING_BASE_FEE; ?></td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
                <tr>
                    <td>Electricity Rate per kWh</td>
                    <td>NPR <?php echo ELECTRICITY_RATE_PER_KWH; ?></td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
                <tr>
                    <td>Arrival Deadline</td>
                    <td><?php echo BOOKING_ARRIVAL_DEADLINE_MINUTES; ?> minutes</td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
                <tr>
                    <td>Email Notifications</td>
                    <td>—</td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
                <tr>
                    <td>Payment Gateway</td>
                    <td>Not configured</td>
                    <td><span class="badge badge-info">Planned</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <p style="text-align: center; color: var(--gray); margin-top: 20px; font-size: 13px;">
        <i class="fas fa-clock"></i> These controls are coming soon. Please check back in a future release.
    </p>
</div>