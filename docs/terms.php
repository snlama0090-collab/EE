<?php // ponytail: standalone static page - no router/includes exist to hook into
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms &amp; Conditions - EV Charge Nepal</title>
<style>
body{font-family:'Segoe UI',system-ui,sans-serif;color:#111827;background:#f9fafb;margin:0;line-height:1.65}
main{max-width:820px;margin:0 auto;padding:48px 24px 64px}
h1{color:#111827;font-size:28px;margin-bottom:4px} .upd{color:#6b7280;font-size:14px;margin-bottom:32px}
h2{color:#111827;font-size:18px;margin-top:32px;border-left:4px solid #22c55e;padding-left:10px}
p,li{font-size:15px} .sim{background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:12px 14px;font-size:15px}
a{color:#16a34a} footer{max-width:820px;margin:0 auto;padding:0 24px 40px;color:#6b7280;font-size:13px}
</style>
</head>
<body>
<main>
<h1>Terms &amp; Conditions</h1>
<p class="upd">Last updated: <?php echo date('F j, Y'); ?></p>

<p>These Terms &amp; Conditions ("Terms") govern your use of the EV Charge Nepal platform — the web application connecting electric vehicle <strong>drivers</strong> with charging-station <strong>owners</strong> across Nepal, administered by platform <strong>administrators</strong>. By creating an account or using the platform in any role you agree to these Terms.</p>

<h2>1. Roles</h2>
<ul>
<li><strong>Drivers</strong> register a vehicle (make/model, battery capacity) to discover stations, view charger types, and start/stop charging sessions.</li>
<li><strong>Owners</strong> list charging stations (location, charger types, pricing display) for admin review and manage their station details.</li>
<li><strong>Administrators</strong> approve or reject station listings and operate the support-ticket system.</li>
</ul>

<h2>2. Accounts &amp; Eligibility</h2>
<p>Registration currently requires a Gmail address (used for email verification codes) and a Nepali mobile number. You are responsible for the accuracy of your details and for keeping your password secure. One account per person per role.</p>

<h2>3. Station Listings (Owners)</h2>
<p>Owner-submitted stations appear publicly only after administrator approval. Owners must provide truthful location, charger-type, and capacity information and keep it current. The platform may reject or remove listings that are inaccurate, misleading, or inactive.</p>

<h2>4. Charging Sessions (Drivers)</h2>
<p>The platform lets you locate approved stations and record charging-session start/stop times. It does not itself control chargers, meter energy, or dispatch electricity — session records are informational logs of your charging activity.</p>

<div class="sim"><strong>Payments are currently simulated.</strong> All NPR amounts shown (session costs, estimates) are platform credits calculated for demonstration only. No payment gateway is integrated, no money is collected, held, transferred, or paid out, and no invoice raised through the platform is payable. When live payment processing is introduced, these Terms will be updated and users will be notified before it takes effect.</div>

<h2>5. Energy Settlement &amp; Payouts</h2>
<p>No vehicle-to-grid energy sale, net-metering settlement, or owner payout functionality exists today. Owners cannot monetise sessions through the platform at this stage.</p>

<h2>6. Reviews &amp; Conduct</h2>
<p>Where review features are enabled, reviews must reflect genuine experience. Prohibited: impersonation, harassment, manipulating ratings, scraping, interfering with station records, or using the platform for unlawful activity under Nepali law.</p>

<h2>7. Support</h2>
<p>Driver and owner support is handled through the in-app ticket system (Dashboard → Support). Administrative responses and resolutions are recorded against your ticket history.</p>

<h2>8. Service Availability</h2>
<p>The platform is provided on an "as is" and "as available" basis. Station data is contributed by owners and may be incomplete or outdated; always confirm availability directly with a station before travelling. We do not warrant uninterrupted operation.</p>

<h2>9. Limitation of Liability</h2>
<p>To the maximum extent permitted by law, the platform operator is not liable for indirect or consequential losses arising from use of the service — including travel to unavailable stations, charging equipment faults, vehicle damage, or reliance on simulated payment figures.</p>

<h2>10. Data &amp; Account Deletion</h2>
<p>Account data (profile, vehicles, session and ticket history) is stored to operate the service. You may request account deletion via a support ticket; verified requests are actioned within 30 days except records we must retain for legal or fraud-prevention purposes.</p>

<h2>11. Changes to These Terms</h2>
<p>We may update these Terms as features evolve — particularly when live payments arrive. Continued use after an update constitutes acceptance.</p>

<h2>12. Governing Law</h2>
<p>These Terms are governed by the laws of Nepal. Disputes fall under the jurisdiction of the courts of Nepal.</p>
</main>
<footer>EV Charge Nepal &middot; <a href="../public/register.php">Create an account</a></footer>
</body>
</html>
