<?php
/**
 * Mailer Helper — sends OTP emails via Gmail SMTP using PHPMailer.
 * 
 * Requires: PHPMailer installed via Composer, GMAIL_USER / GMAIL_APP_PASSWORD in config.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send a 6-digit OTP to the given email address.
 *
 * @param string $recipientEmail
 * @param string $otp  6-digit plaintext OTP
 * @return bool         true on success, false on failure
 */
function sendOtpEmail($recipientEmail, $otp) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USER;
        $mail->Password   = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom(GMAIL_USER, 'WattPulse EV Charging');
        $mail->addAddress($recipientEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your WattPulse Verification Code';

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;padding:40px 16px;">
<tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
<tr><td style="padding:40px 32px 24px;text-align:center;">
<div style="font-size:40px;margin-bottom:12px;">⚡</div>
<h1 style="font-size:22px;font-weight:700;color:#1a1a2e;margin:0 0 4px;">WattPulse</h1>
<p style="font-size:14px;color:#6b7280;margin:0 0 24px;">Your verification code</p>
<div style="background:#f8f9fc;border-radius:12px;padding:24px;margin-bottom:24px;">
<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Enter this code to complete your registration</div>
<div style="font-size:36px;font-weight:700;letter-spacing:8px;color:#1a1a2e;font-family:monospace;">{$otp}</div>
</div>
<p style="font-size:13px;color:#9ca3af;margin:0;">This code expires in <strong>10 minutes</strong>. If you didn't request this, please ignore this email.</p>
</td></tr>
<tr><td style="background:#f8f9fc;padding:16px 32px;text-align:center;">
<p style="font-size:12px;color:#9ca3af;margin:0;">&copy; 2026 WattPulse &mdash; EV Charging Station Finder</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

        $mail->AltBody = "Your WattPulse verification code is: {$otp}\n\nThis code expires in 10 minutes.\nIf you didn't request this, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}