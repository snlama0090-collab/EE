# WattPulse ⚡ — EV Charging Station Finder

A full-stack web application for finding, booking, and managing EV charging stations — a three-sided marketplace connecting drivers, station owners, and platform admins.

## Overview

Drivers search an interactive map for nearby chargers, reserve one with a flat fee, and pay a battery-%-based charging fee when they arrive. Station owners register stations and manage charger availability; admins moderate stations, users, and reviews. Built as a classic server-rendered PHP app with AJAX-loaded dashboard sections — no SPA framework.

> 📄 Full architecture report, workflows, schema notes, and audit findings: see [PROJECT_REPORT.md](PROJECT_REPORT.md)

## Tech Stack

- **Backend:** PHP 8.x (vanilla, PDO) — no framework
- **Database:** MySQL 8.x (`ev_charging_db`, 14 tables)
- **Frontend:** HTML5, CSS3, vanilla JavaScript (AJAX partial loads)
- **Maps:** Leaflet.js 1.9.4 + OpenStreetMap / Nominatim reverse geocoding
- **Charts:** Chart.js 4.4.7 (owner/admin analytics)
- **Auth:** Email/password (bcrypt) + Google OAuth (One Tap) + OTP email verification (PHPMailer via Gmail SMTP)
- **Server:** Apache / XAMPP

## Key Features

- 🗺️ **Map-based discovery** — searchable, sortable station list with live charger availability (public API, no auth required)
- 🔌 **Two-step booking flow** — flat reservation fee holds the charger; charging cost is quoted from current battery % and billed separately on arrival
- ⏱️ **Live countdowns** — 12-second polling shows "Arrive in M:SS" and "Charging ends in M:SS" timers
- 🛑 **Stop charging early** — driver-initiated termination, clearly marked "no refund"
- 👥 **Three role dashboards** — driver / owner / admin, each role-scoped with its own sidebar and data isolation
- ✅ **Admin moderation** — station approval/rejection workflow with activity logging
- 📧 **OTP registration** — hashed, expiring, attempt-limited email codes
- 🔔 **Notifications** — session started/stopped/expired events surfaced per-role
- ♻️ **Self-healing sessions** — `SessionTicker` auto-cancels expired bookings, recovers orphaned chargers, and auto-completes overdue sessions race-safely

## Setup

Requires [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP 8.x) and [Composer](https://getcomposer.org/).

1. **Clone into XAMPP's web root:**
   ```bash
   git clone https://github.com/snlama0090-collab/EE.git C:/xampp/htdocs/EE
   ```

2. **Install dependencies** (PHPMailer for OTP email, phpdotenv for config):
   ```bash
   cd C:/xampp/htdocs/EE
   composer install
   ```

3. **Create the database** — import the schema via phpMyAdmin (`http://localhost/phpmyadmin`) or CLI:
   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS ev_charging_db CHARACTER SET utf8mb4;"
   mysql -u root ev_charging_db < database/schema.sql
   ```
   The app expects a local MySQL connection as `root` with an empty password on port 3306 (hardcoded defaults in `app/config/config.php`).

4. **Create a `.env` file** in the project root with your Gmail credentials (used for OTP emails):
   ```ini
   GMAIL_USER=youraddress@gmail.com
   GMAIL_APP_PASSWORD=your-16-char-app-password
   ```
   > Use a Google [App Password](https://myaccount.google.com/apppasswords), not your normal password. These are the only two environment variables required — no OAuth secrets are needed at runtime.

5. **Start Apache and MySQL** from the XAMPP Control Panel.

6. **Open the app:** <http://localhost/EE/public/>

## Known Limitations

Honest "next steps" — the app is beyond prototype stage but not production-ready:

- 💳 **Payments are simulated** — `PAYMENT_DRIVER=simulated` default; Khalti sandbox integration underway (gateway columns `gateway_*` in schema; legacy Razorpay names retired — no NPR settlement). #1 production blocker until live
- 🏁 **Booking queue race condition** — double-booking check isn't row-locked (`SELECT ... FOR UPDATE`)
- 🔒 ~~No CSRF protection or login rate limiting~~ — **both shipped 2026-08-24**: two-layer login throttle (LOGIN_MAX_ATTEMPTS/IP spray net) + session-bound CSRF tokens (`app/helpers/Csrf.php`) enforced on all state-changing endpoints. Remaining security backlog: file-upload content validation.
- 🔋 **kWh billing assumes 100% charge** — no end-battery-% capture
- ⚠️ **Google OAuth auto-approves new owner accounts**, bypassing admin moderation
- 🗑️ **Cascade-delete on stations** destroys financial history (no soft-delete)

See [PROJECT_REPORT.md §11](PROJECT_REPORT.md) for the full audit matrix and roadmap.

## Documentation

- [PROJECT_REPORT.md](PROJECT_REPORT.md) — architecture, file-by-file breakdown, critical workflows, schema notes, financial logic, audit matrix & roadmap
- [database/schema.sql](database/schema.sql) — full DDL for all 14 tables + sample data

## License

No license has been added yet.
