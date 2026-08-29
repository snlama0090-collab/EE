<?php
/**
 * Khalti return/verify endpoint — the completion half of the hosted-checkout flow.
 *
 * Khalti sends the user back here (GET ?pidx=…) and also POSTs the same reference
 * server-side. This page NEVER trusts those payloads as proof of payment: it
 * re-asks Khalti via lookup() (secret-key authenticated) and only the gateway's
 * literal "Completed" status — with a matching amount — advances anything. Both
 * DB updates are status-guarded, so repeated returns/callbacks for the same pidx
 * are idempotent no-ops.
 *
 * No CSRF token here BY DESIGN: there is no authenticated form submission — the
 * only input is pidx (a gateway-issued reference), every mutation is
 * gateway-verified and guarded, and Khalti's server callback carries no session.
 * (Mirrors the approved §19 design: lookup API is the sole success authority.)
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';      // boots session; guests fall through
require_once __DIR__ . '/../../app/helpers/KhaltiPayment.php';

$db = getDB();   // explicit handle — house pattern (config.php provides no global $db)

$pidx = sanitize($_GET['pidx'] ?? $_POST['pidx'] ?? '');
$dashboard = APP_URL . '/public/dashboard/driver.php?page=bookings';
$final = null;   // set only on paths that render a status page instead of redirecting

if ($pidx === '') {
    header('Location: ' . APP_URL . '/public/');
    exit;
}

// Which payment does this pidx belong to?
$stmt = $db->prepare("SELECT * FROM payment_transactions WHERE gateway_order_ref = ?");
$stmt->execute([$pidx]);
$txn = $stmt->fetch();

if (!$txn) {
    $final = 'Unknown payment reference.';
} elseif ($txn['status'] === 'completed') {
    // Idempotent short-circuit: already verified on an earlier return/callback.
    header('Location: ' . $dashboard);
    exit;
} else {
    $lk = KhaltiPayment::lookup($pidx);

    if ($lk['ok'] && $lk['status'] === 'Completed') {
        // Amount guard: the paisa amount Khalti says moved must match what this
        // transaction row expects. Mismatch => complete nothing, leave pending, log loud.
        $expectedPaisa = (int) round(((float) $txn['amount']) * 100);
        if ($lk['total_amount'] !== null && (int) $lk['total_amount'] !== $expectedPaisa) {
            error_log("[khalti-return] AMOUNT MISMATCH pidx=$pidx: gateway={$lk['total_amount']} expected=$expectedPaisa");
            $final = 'Payment amount verification failed. Please contact support.';
        } else {
            $db->beginTransaction();

            // Guarded on status='pending': if a parallel return/callback already
            // completed this payment, both updates below are equally inert.
            $stmt = $db->prepare("UPDATE payment_transactions
                SET status = 'completed', transaction_id = ?, gateway_payment_id = ?
                WHERE id = ? AND status = 'pending'");
            $stmt->execute([$lk['transaction_id'], $lk['transaction_id'], $txn['id']]);

            // Mirror simulated confirm_payment semantics exactly (guarded the same way).
            $stmt = $db->prepare("UPDATE bookings
                SET status = 'booked', payment_status = 'completed'
                WHERE id = ? AND status = 'pending_payment'");
            $stmt->execute([$txn['booking_id']]);
            $booked = ($stmt->rowCount() === 1);   // first completion only - keeps the owner bell exactly-once
            $db->commit();

            if ($booked) {
                $stmt = $db->prepare("SELECT b.id, c.charger_number, c.charger_type, c.wattage_kw,
                    s.name AS station_name, s.owner_id, u.name AS driver_name
                    FROM bookings b JOIN chargers c ON b.charger_id = c.id
                    JOIN stations s ON c.station_id = s.id JOIN users u ON b.user_id = u.id
                    WHERE b.id = ?");
                $stmt->execute([$txn['booking_id']]);
                if ($b = $stmt->fetch()) {
                    // ponytail: same single-row pattern as station approve/reject - audit + owner bell in one insert
                    $stmt = $db->prepare("INSERT INTO activity_logs (owner_id, action, resource_type, resource_id, details)
                        VALUES (?, 'booking_created', 'booking', ?, ?)");
                    $stmt->execute([$b['owner_id'], $b['id'],
                        "New booking for your station \"" . $b['station_name'] . "\" - " . $b['driver_name']
                        . " reserved charger #" . $b['charger_number'] . " ("
                        . $b['charger_type'] . ", " . $b['wattage_kw'] . " kW)."]);
                }
            }

            header('Location: ' . $dashboard);
            exit;
        }
    }

    if ($final === null) {
        if ($lk['ok'] && in_array($lk['status'], ['User canceled', 'Expired'], true)) {
            // Terminal non-success: transaction fails, booking stays pending_payment —
            // the existing expiry flow (SessionTicker) cancels it and frees the charger.
            $stmt = $db->prepare("UPDATE payment_transactions
                SET status = 'failed', failure_reason = ?
                WHERE id = ? AND status = 'pending'");
            $stmt->execute(['Khalti status: ' . $lk['status'], $txn['id']]);
            $final = 'Payment not completed (' . $lk['status'] . '). Your reservation will expire shortly and the charger will be freed.';
        } elseif ($lk['ok']) {
            // "Pending" or any other intermediate status — leave everything pending.
            $final = 'Payment is still processing at Khalti. If you already paid, retrying this page will confirm it.';
        } else {
            // Gateway unreachable — verify nothing, change nothing, let the user retry.
            $final = 'Could not verify payment right now. Please try again from your bookings page.';
            error_log('[khalti-return] lookup failed for pidx=' . $pidx . ': ' . $lk['error']);
        }
    }
}

// Failure/pending paths render a plain status page (guest-safe — Khalti's server
// callback may be the requester, with no session to redirect into a dashboard).
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Status — EV Charging</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:sans-serif;max-width:480px;margin:80px auto;padding:0 16px;text-align:center;">
    <h2>Payment Status</h2>
    <p><?php echo htmlspecialchars($final, ENT_QUOTES, 'UTF-8'); ?></p>
    <p><a href="<?php echo APP_URL; ?>/public/">Back to home</a></p>
</body>
</html>