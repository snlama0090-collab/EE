# EV Charging Station Finder — Project Architecture Report

> **Generated:** 2026-07-15  
> **Last updated:** 2026-08-23  
> **Project Root:** `d:/Xampp/htdocs/EE`  
> **Mode:** Read-only analysis

---

## 1. Executive Summary & Project Objective

### High-Level Purpose

This is a full-stack web application for **finding, booking, and managing EV charging stations**. It serves three distinct user types — **Drivers** (EV owners who book chargers), **Station Owners** (who register stations and manage charging sessions), and **Admins** (who moderate the platform). The application operates as a marketplace: drivers search for nearby stations on an interactive Leaflet map, pay a prepaid fee, and charge their vehicles; owners submit stations for admin approval, monitor charger statuses, and start/complete sessions; admins approve/reject stations, manage users, and view reports.

### Core Business Logic & Primary User Journeys

| Journey | Actor | Flow |
|---|---|---|
| **Registration** | Driver / Owner | Multi-step form → user type selection → account details → password → terms → POST to `api/auth/register.php` → redirect to login |
| **Authentication** | All roles | email + password → `api/auth/login.php` → session start via `Auth::startSession()` → dashboard redirect (role-based) |
| **Google OAuth** | All roles | Google One Tap → `api/auth/google.php` → verify token → find-or-create user → session start → dashboard redirect |
| **Find & Book** | Driver | Landing page → leaflet map → station cards with distance/battery details → modal with charger selection → `initiate_payment` → `confirm_payment` → booking held as `booked` (reservation fee paid); charging starts separately, driver-initiated, via `initiate_charging_payment` / `confirm_charging_payment` |
| **Charging Lifecycle** | Owner + Driver | `booked` → `pending_payment` (driver pays reservation fee) → `booked` (awaiting arrival) → `charging` (driver confirms charging payment) → `stopped`/`completed` (driver stops early or auto-completes via `SessionTicker`) → release charger |
| **Station Management** | Owner | Register station with location picker → add charger rows → submit for approval → admin approves → manage charger status (available/maintenance/offline) |
| **Admin Moderation** | Admin | Review pending stations → approve/reject with reason → manage users, reviews, and view reports |
| **Session Auto-Completion** | System | `SessionTicker` piggybacks on each booking API call → detects overdue sessions → calculates kWh/cost → marks as completed → releases charger |

---

## 2. Architecture & Tech Stack

### Language & Frameworks

| Layer | Technology | Notes |
|---|---|---|
| **Backend** | PHP 8.x (procedural + OOP) | No framework — vanilla PHP with simple autoload via `require_once` |
| **Frontend** | HTML5, CSS3, Vanilla JavaScript | No SPA framework — server-rendered PHP pages with AJAX partial loads |
| **Database** | MySQL 8.x via PDO | `ev_charging_db` with 14 tables |
| **Server** | Apache / XAMPP | `.htaccess` rewrite at root |

### Key Dependencies

| Dependency | Usage | Source |
|---|---|---|
| **Font Awesome 6.4** | Icons throughout (eye, car, plug, chart, etc.) | CDN |
| **Leaflet 1.9.4** | Interactive maps (station search, location picker, detail maps) | CDN + unpkg |
| **Chart.js 4.4.7** | Owner financial dashboards (revenue / kWh charts) | CDN |
| **Google Identity Services (GSI)** | OAuth 2.0 sign-in/up (One Tap) | CDN |
| **Nominatim (OpenStreetMap)** | Reverse geocoding for location detection | REST API |
| **Razorpay** | Payment processing (schema references `razorpay_order_id`, `razorpay_payment_id` — not yet fully wired in frontend) | Schema-level only |

### Database Schema Overview (16 tables)

```
users ──┬── favorites
         ├── bookings ──┬── charging_sessions
         │               └── payment_transactions
         └── ratings_reviews ──── owner_replies
               
owners ──┬── stations ──── chargers
         ├── verification_tokens
         └── activity_logs

admins ────── activity_logs
               
remember_tokens (standalone)
verification_tokens (standalone)
```

---

## 3. File & Directory Structure Map

```
d:/Xampp/htdocs/EE/
│
├── .clinerules              # Agent behavior rules (ponytail mode active)
├── .htaccess                # Apache rewrite rules
├── PROJECT_REPORT.md        # This file
│
├── app/
│   ├── config/
│   │   └── config.php       # App constants, Database singleton, helper functions
│   ├── helpers/
│   │   ├── Auth.php          # Session management, login/logout, access control
│   │   ├── Location.php      # Haversine distance calculation
│   │   ├── Mailer.php        # OTP email sending (PHPMailer via Gmail SMTP)
│   │   └── SessionTicker.php # Auto-complete overdue charging sessions
│   └── logs/                 # Application log output
│
├── api/
│   ├── auth/
│   │   ├── login.php         # POST: email + password authentication
│   │   ├── register.php      # POST: driver/owner account creation
│   │   ├── logout.php        # GET: session destroy + redirect
│   │   ├── google.php        # POST: Google OAuth token verification + auto-register
│   │   └── otp.php           # POST: 6-digit OTP generation + verification (Gmail SMTP)
│   ├── bookings.php          # GET/POST/PUT/DELETE: full booking lifecycle
│   ├── stations.php          # GET/POST/PUT/DELETE: stations, chargers, admin actions
│   ├── nearby-stations.php   # GET: public no-auth station discovery (bounding box + Haversine)
│   └── stats.php             # GET: platform-wide statistics
│
├── database/
│   └── schema.sql            # Full DDL for all 14 tables + sample data
│
├── public/
│   ├── index.php             # Landing page (role cards, map)
│   ├── login.php             # Login page (user type tabs, email/password, Google One Tap)
│   ├── register.php          # 2-step registration (type selection → full form)
│   ├── logout.php            # Logout + redirect proxy
│   │
│   ├── assets/
│   │   ├── css/
│   │   │   ├── auth.css      # Shared login/register page styles
│   │   │   └── dashboard.css # Dashboard layout, sidebar, cards, tables, modals, responsive
│   │   ├── js/
│   │   │   ├── modal.js      # Themed modal/alert/confirm system (IIFE)
│   │   │   └── landing.js    # Landing page interactivity (map, tabs, location)
│   │   └── img/              # Static images (default avatar, etc.)
│   │
│   └── dashboard/
│       ├── driver.php         # Driver dashboard shell (sidebar, map, booking modal)
│       ├── owner.php          # Owner dashboard shell (station management, charts)
│       ├── admin.php          # Admin dashboard shell (station moderation, users)
│       │
│       ├── sections/          # Driver dashboard content loaded via AJAX
│       │   ├── dashboard.php
│       │   ├── find-stations.php
│       │   ├── bookings.php
│       │   ├── favorites.php
│       │   ├── notifications.php
│       │   ├── profile.php
│       │   └── support.php
│       │
│       ├── owner_sections/    # Owner sub-pages loaded via AJAX
│       │   ├── overview.php
│       │   ├── analytics.php
│       │   ├── invoices.php
│       │   ├── stations.php
│       │   ├── bookings.php
│       │   ├── team.php
│       │   ├── notifications.php
│       │   ├── settings.php
│       │   ├── support.php
│       │   └── profile.php
│       │
│       └── admin_sections/    # Admin sub-pages loaded via AJAX
│           ├── overview.php
│           ├── analytics.php
│           ├── orders.php
│           ├── customers.php
│           ├── invoices.php
│           ├── stations.php
│           ├── users.php
│           ├── reviews.php
│           ├── reports.php
│           ├── notifications.php
│           ├── settings.php
│           └── support.php
│
└── app/
    └── config/           (listed above for clarity)
```

---

## 4. System Roles & User Types

### Implemented Roles

| Role | Database Table | Auth Guard Method | Redirect Target |
|---|---|---|---|
| **Driver** | `users` | `Auth::requireUserType('driver')` | `dashboard/driver.php` |
| **Owner** | `owners` | `Auth::requireUserType('owner')` | `dashboard/owner.php` |
| **Admin** | `admins` | `Auth::requireUserType('admin')` | `dashboard/admin.php` |

Additionally, a **Guest** (unauthenticated) role exists, which can only access `index.php`, `login.php`, and `register.php`. All other pages and API endpoints enforce authentication.

### Role Enforcement Points

1. **Auth.php** — `requireUserType($type)` (line 93) calls `requireLogin()` then checks `$_SESSION['user_type']`. If mismatch → HTTP 403 "Access Denied".

2. **Dashboard entry files** — Each dashboard file calls `Auth::requireUserType(...)` at the top:
   - `driver.php` line 6: `Auth::requireUserType('driver')`
   - `owner.php` line 6: `Auth::requireUserType('owner')`
   - `admin.php` line 5: `Auth::requireUserType('admin')`

3. **API endpoints** — `stations.php` and `bookings.php` call `Auth::requireLogin()` and then switch logic based on `Auth::getCurrentUserType()`. Each action validates the user type before execution (e.g., only drivers can `confirm_charging_payment`/`stop_session`, only owners can `complete_session`, only admins can `approve` stations).

4. **Admin sub-roles** — The `admins` table has a `role` column (`super_admin` / `moderator`) with granular permission flags (`can_approve_stations`, `can_manage_users`, `can_moderate_reviews`), though these are not yet enforced in the code — only basic `admin` user-type checks are in place.

### Session Security Mechanisms

- **Auth.php** `startSession()` (line 14): stores `user_id`, `user_type`, `login_time`, and `user_agent`
- **Auth.php** `isSessionValid()` (line 59): checks timeout (`SESSION_TIMEOUT` = 1 hour), User Agent match (hijacking detection)
- **Auto-logout**: `Auth.php` line 165 — on every page load, expired or invalid sessions are destroyed with redirect
- **"Remember Me"**: `generateRememberToken()` inserts a SHA-256 hashed token into `remember_tokens` with 30-day expiry; `verifyRememberToken()` validates and starts a new session
- **Password hashing**: `PASSWORD_BCRYPT` with cost 10 (`config.php` line 39)

---

## 5. Deep File-by-File Breakdown (Core Files)

### 5.1 `app/config/config.php`

**Purpose:** Application-wide constants, database connection singleton (`Database` class), and global helper functions.

**Key Elements:**
- `Database` class (line 100-146) — singleton PDO wrapper with `getInstance()`, `connect()`, `getConnection()`, `disconnect()`. Uses `PDO::ERRMODE_EXCEPTION` and `FETCH_ASSOC`.
- `getDB()` (line 153) — convenience global function returning a PDO connection.
- `hash_password()` / `verify_password()` (lines 174-182) — wraps PHP `password_hash`/`password_verify` with bcrypt.
- `sanitize()` (line 188) — `htmlspecialchars()` + trim, recursive for arrays.
- `validate_email()` / `validate_phone()` — email via `filter_var`, phone via regex `/^(?:\+977\s?)?9[78]\d{8}$/`.
- `json_response()` (line 227) — standardized JSON response output.
- `generate_token()` — `bin2hex(random_bytes(32))` for CSRF/remember tokens.
- `log_message()` — appends to file in `app/logs/`.

### 5.2 `app/helpers/Auth.php`

**Purpose:** Session management, login/logout, access control, "Remember Me" token handling.

**Key Functions:**
| Method | Line | Description |
|---|---|---|
| `startSession()` | 14 | Sets `$_SESSION` vars (`user_id`, `user_type`, `login_time`, `user_agent`); optionally sets 30-day remember cookie |
| `isLoggedIn()` | 31 | Checks `$_SESSION['user_id']` and `$_SESSION['user_type']` exist |
| `getCurrentUserId()` | 38 | Returns `$_SESSION['user_id']` or null |
| `getCurrentUserType()` | 45 | Returns `$_SESSION['user_type']` or null |
| `isUserType($type)` | 52 | Compares session type to argument |
| `isSessionValid()` | 59 | Checks timeout + User Agent consistency |
| `requireLogin()` | 83 | Redirects to login page if session invalid |
| `requireUserType($type)` | 93 | Calls `requireLogin()` then type-check; 403 on failure |
| `logout()` | 105 | `session_destroy()`, clears remember cookie, logs event |
| `generateRememberToken()` | 121 | Creates token, stores SHA-256 hash in `remember_tokens` |
| `verifyRememberToken()` | 136 | Looks up hash, starts session, deletes used token |

**Global auto-execution** (lines 159-174): Initializes session, validates on every load, and auto-logs in via remember cookie if available.

### 5.3 `api/auth/login.php`

**Purpose:** Authenticate user via email + password against the appropriate table (users/owners/admins) based on user type.

**Key Logic:**
- Line 33-41: Routes query to `users`, `owners`, or `admins` table depending on `user_type`
- Lines 34-42: `LoginThrottle::check()` brute-force gate runs BEFORE the credential query — returns HTTP 429 + `Retry-After: 900` when either layer trips, with a body identical whether or not the account exists (no enumeration leak)
- Line 47: `verify_password($password, $user['password'])` via bcrypt
- Line 54: `Auth::startSession($user['id'], $user_type, $remember)`
- On success: `LoginThrottle::reset($db, $email, $ip)` clears only this email+IP pair's failure rows
- Line 48: Failed attempts logged via `log_message('WARNING', ...)` plus `LoginThrottle::recordFailure()` into `login_attempts`

### 5.4 `api/auth/register.php`

**Purpose:** Create new driver or owner account with validated inputs.

**Key Logic:**
- Input validation: email (filter_var), password length (≥8, `PASSWORD_MIN_LENGTH`), phone (regex)
- Line 39-48: Driver registration inserts into `users` with `car_model`, `car_full_capacity_kwh`
- Line 50-59: Owner registration inserts into `owners` with `company_name`, `bank_account_number`
- Line 70: Duplicate email detection via `PDOException` message matching `'Duplicate'`

**Notable:** Does NOT auto-login after registration — redirects user to `login.php` with success message.

### 5.5 `api/auth/google.php`

**Purpose:** Google OAuth sign-in/sign-up. Verifies ID token against Google's `tokeninfo` endpoint, then finds or creates user.

**Key Logic:**
- Line 23: Calls `https://oauth2.googleapis.com/tokeninfo?id_token=...` via cURL
- Line 45: Verifies `payload['aud']` matches `GOOGLE_CLIENT_ID`
- Lines 63-121: Per-user-type logic:
  - **Driver**: If existing → session start; if new → auto-register with random password, generic car model "Generic EV", 50 kWh capacity
  - **Owner**: If existing → session start; if new → auto-register with random password, `{$name} Enterprise` as company name, auto-approved
  - **Admin**: Cannot auto-register — must pre-exist; otherwise returns error
- Line 124: `Auth::startSession($user_id, $user_type, false)`
- Line 130: Logs authentication to `activity_logs`

### 5.6 `api/bookings.php`

**Purpose:** Full booking CRUD with prepaid payment flow and driver-initiated session management.

**Key Endpoints:**
| Method + Action | Line | Description |
|---|---|---|
| `GET` | 19-48 | Fetch bookings (user-specific or owner-specific) |
| `POST / initiate_payment` | 61-153 | Driver submits charger ID → flat reservation fee → inserts `pending_payment` booking with `arrival_deadline` |
| `POST / confirm_payment` | 156-228 | Driver confirms reservation payment → status to `booked`, inserts first `payment_transactions` record (reservation fee) |
| `POST / confirm_charging_payment` | 205-296 | Driver arrives, confirms charging payment → transitions `booked` → `charging`, calculates fee from battery % + capacity + wattage, inserts second `payment_transactions` record (charging fee), creates `charging_sessions`, sets `session_ends_at` timer, logs `session_started` |
| `POST / initiate_charging_payment` | 298-347 | Driver requests cost quote for charging → returns `charging_cost`, `charge_time_minutes`, `kwh_needed` without mutating state |
| `POST / stop_session` | 349-396 | Driver stops active charging early → transitions `charging` → `stopped`, sets `payment_status = 'completed'` and `payment_amount = estimated_total_cost`, releases charger, logs `session_stopped` (no refund) |
| `PUT / complete_session` | 421+ | Owner completes session → calculates kWh, cost, updates station stats, releases charger (sessions are started exclusively by drivers; owners only complete) |
| `DELETE` | 442-448 | Cancel booking (status → `cancelled`) |

**Queue Management:** Lines 80-109 — maximum 2 active bookings per charger; if 1 existing booking is `booked`/`pending_payment`/`charging`, new booking is rejected.

**Lazy Tick:** Line 17 calls `tickChargingSessions($db)` on every request.

### 5.7 `api/stations.php`

**Purpose:** Station and charger CRUD with location-based search and admin moderation.

**Key Endpoints:**
| Method + Query | Line | Description |
|---|---|---|
| `GET ?id=` | 13-87 | Station detail with chargers, active booking counts, bookable status, reviews |
| `GET ?lat&lng&radius` | 89-133 | Nearby stations: SQL bounding box pre-filter + Haversine post-filter via `Location::getNearbyLocations()` |
| `GET` (authenticated) | 136-181 | Owner's stations or all stations (admin) |
| `POST ?action=approve` | 198-206 | Admin approves station, logs to `activity_logs` |
| `POST ?action=reject` | 209-221 | Admin rejects station with reason |
| `POST ?action=update_charger_status` | 232-255 | Owner sets charger to available/maintenance/offline |
| `POST` (owner) | 257-306 | Create station + chargers in a transaction |
| `PUT` | 313-351 | Owner updates station details (resets to `pending` approval) |
| `DELETE` | 353-385 | Owner or admin deletes station |

### 5.8 `app/helpers/SessionTicker.php`

**Purpose:** Auto-completes overdue charging sessions. Called piggyback on every `api/bookings.php` request.

**Logic:**
- Query bookings where `status = 'charging'` AND `session_ends_at <= NOW()` (limit 10)
- For each overdue session:
  1. Calculate kWh consumed based on `car_full_capacity_kwh` and `battery_start_percent`
  2. Compute `electricity_cost` = kWh × `ELECTRICITY_RATE_PER_KWH` + `base_fee`
  3. Update `charging_sessions` with end time, end battery (100%), kWh, payment
  4. Update booking to `completed` with race-safe `WHERE status = 'charging'` guard
  5. Release charger (status → `available`)
  6. Update station stats (`total_bookings`, `total_revenue`, `total_kwh_consumed`)

### 5.9 `public/login.php`

**Purpose:** Login page with user type tabs (driver/owner/admin), email/password form, Google One Tap button.

**Key Functions:**
- `switchUserType(type)` — updates hidden input + active tab styling
- `handleLogin(event)` — async fetch to `api/auth/login.php`, handles loading state, redirects on success
- `togglePasswordVisibility()` — hardcoded for `#password` / `#eye-icon`
- `handleGoogleSignIn(response)` — passes credential to `api/auth/google.php`, handles errors, re-renders Google button on failure

### 5.10 `public/register.php`

**Purpose:** Two-step registration (type selection → form), supports both driver (with car details, battery, preferred charger) and owner (company, bank details, description).

**Key Functions:**
- `selectUserType(element, type)` — toggles driver/owner form sections, disables fields for inactive type
- `goToStep(step)` — navigates between step 1 (type selection) and step 2 (form), updates progress bar
- `handleRegister(event)` — validates password match, terms, minimum length; POST to `api/auth/register.php`
- `togglePasswordVisibility(inputId, iconId)` — parameterized version (not in login.php) supporting both password and confirm-password fields

### 5.11 `public/dashboard/driver.php`

**Purpose:** Driver dashboard shell — sidebar navigation, Leaflet map, search, station booking modal, polling loop for active timers.

**Key Functions:**
- `loadSection(sectionName)` — AJAX loads section content, updates URL with `history.pushState`, handles errors with retry button
- `initMap()` — initializes Leaflet map, enables scroll-wheel zoom on click
- `detectLocation()` — uses Navigator Geolocation API, reverse geocodes via Nominatim
- `searchStations()` / `filterStations()` / `sortStations()` — station card filtering by distance, charger type
- `bookStation(stationId)` — creates modal with charger selector, calls `initiate_payment` (flat reservation fee)
- `startCharging(bookingId)` — opens battery % input modal, calls `initiate_charging_payment` for quote, then `confirm_charging_payment` to start session
- `confirmPayment(bookingId)` — POST to `confirm_payment`, starts polling
- `startPollingIfNeeded()` / `pollTick()` — polls every 12s for active bookings, updates live countdown timers
- `initCountdowns()` — wires `startCountdown()` to elements with `.countdown[data-countdown-to]`
- `startCountdown(targetIso, element, onExpire)` — live ticking countdown helper (M:SS format)
- `stopCharging(bookingId)` / `doStopCharging(bookingId)` — POST to `stop_session`, no refund
- `cancelBooking(id)` / `doCancelBooking(id)` — DELETE to `api/bookings.php`

### 5.12 `public/dashboard/sections/bookings.php`

**Purpose:** Driver bookings list with live countdown timers and session actions.

**Key UI Elements:**
- For `booked` status: shows **"Arrive in M:SS"** countdown from `arrival_deadline`, with Cancel and Start Charging buttons
- For `charging` status: shows **"Ends in M:SS"** countdown from `session_ends_at`, with Stop Charging button
- Countdowns use `.countdown[data-countdown-to]` attributes wired by `initCountdowns()` in `driver.php`
- Completed bookings show actual charge time and kWh consumed

### 5.13 `public/dashboard/owner.php`

**Purpose:** Owner dashboard — station registration with Leaflet location picker (draggable marker), charger management, booking completion oversight, financial charts.

**Key Functions:**
- `initLocationPickerMap()` — draggable marker on Leaflet map, reverse geocodes on drag/click
- `submitStation(event)` — collects form data + charger rows, POST to `api/stations.php`
- `manageStationChargers(stationId, stationName)` — AJAX load charger list with status dropdowns
- `updateChargerStatus(chargerId, newStatus, stationId, stationName)` — POST to `update_charger_status`
- `completeSession(bookingId)` / `doCompleteSession(bookingId)` — confirmation dialog → PUT `complete_session` (session start is driver-initiated; owner only completes)
- `switchFinancialView(period)` — Chart.js bar/line chart switching between days/months/years
- `deleteStation(id)` — confirmation + DELETE to `api/stations.php`

### 5.14 `public/dashboard/admin.php`

**Purpose:** Admin dashboard — station review/moderation with detail modal (Leaflet map, charger table), approve/reject flow.

**Key Functions:**
- `loadSection(sectionName)` — AJAX loads admin section partials
- `approveStation(stationId)` / `rejectStation(stationId)` — POST to `api/stations.php?action=approve|reject`
- `viewStationDetails(stationId)` — opens modal with Leaflet map, charger table, approve/reject buttons
- `doModalApprove(id)` / `doModalReject(id)` — confirm dialog → API call → close modal + reload section

---

## 6. Critical Workflows (Step-by-Step)

### 6.1 Driver Charging Lifecycle

```
1. DRIVER RESERVES CHARGER
   File: public/dashboard/driver.php → bookStation(stationId)
   - Modal with charger selector → POST /api/bookings.php { action: 'initiate_payment', charger_id }
   - Server inserts booking with status='pending_payment', base_fee = BOOKING_BASE_FEE, arrival_deadline = now + BOOKING_ARRIVAL_DEADLINE_MINUTES

2. DRIVER CONFIRMS RESERVATION PAYMENT
   File: public/dashboard/driver.php → confirmPayment(bookingId)
   - POST /api/bookings.php { action: 'confirm_payment', booking_id }
   - Server sets status='booked', payment_status='completed', inserts first payment_transactions row (reservation fee)
   - Driver can now cancel or start charging before arrival_deadline expires

3. DRIVER ARRIVES AND STARTS CHARGING
   File: public/dashboard/driver.php → startCharging(bookingId)
   - Step A: Modal asks for current battery % → POST { action: 'initiate_charging_payment' } to get quote
   - Step B: showConfirm with cost breakdown → POST { action: 'confirm_charging_payment', battery_percent }
   - Server transitions status='charging':
       * Calculates kWh_needed = (100 - battery_percent) / 100 * car_full_capacity_kwh
       * Calculates charge_time_minutes = ceil(kWh_needed / wattage_kw * 60)
       * Calculates charging_cost = kWh_needed * ELECTRICITY_RATE_PER_KWH
       * Sets session_ends_at = NOW() + 5 min buffer + charge_time_minutes
       * Updates car_current_battery_percent, calculated_charge_time_minutes
       * Sets charger status='charging'
       * Inserts charging_sessions record
       * Inserts second payment_transactions row (charging fee) with '-CHG' suffix
       * Logs activity_logs 'session_started' with plain-text details

4. LIVE COUNTDOWN TIMERS
   File: public/dashboard/driver.php → pollTick() + initCountdowns()
   - pollTick() runs every 12s while active bookings exist
   - For each booking card with [data-booking-id]:
       * Shows green "⚡ Charging — M:SS remaining" from session start
         (the orange "Owner connecting..." buffer phase is dead UI — `buffer_ends_at` is never set by any API path; see §7.3)
       * After session_ends_at: stops polling, reloads section (SessionTicker may auto-complete)
   - initCountdowns() wires startCountdown() to .countdown elements in bookings.php template

5. DRIVER STOPS CHARGING EARLY
   File: public/dashboard/driver.php → stopCharging(bookingId)
   - Confirmation dialog warns "NO REFUND"
   - POST { action: 'stop_session' }
   - Server sets status='stopped', payment_amount=estimated_total_cost, payment_status='completed'
   - Ends charging_sessions, releases charger to 'available'
   - Logs activity_logs 'session_stopped' with plain-text details

6. SESSION AUTO-COMPLETION (FALLBACK)
   File: app/helpers/SessionTicker.php → tickChargingSessions()
   - Triggered lazily on every api/bookings.php request
   - Finds bookings where status='charging' AND session_ends_at <= NOW()
   - Calculates kWh from car_full_capacity_kwh and battery_start_percent (assumes 100% end)
   - Updates charging_sessions and bookings to 'completed', releases charger, updates station stats
```

### 6.2 Authentication Flow

```
1. USER VISITS LOGIN PAGE
   File: public/login.php
   - PHP: checks Auth::isLoggedIn() → redirects to dashboard if already authenticated
   - Render: login form with 3 user-type tabs, email input, password input with eye toggle, Google One Tap

2. USER SUBMITS CREDENTIALS (email + password)
   File: public/login.php → function handleLogin(event) (line 256)
   - Validates fields not empty, password ≥ 6 chars
   - Shows loading spinner, disables button
   - POST to /EE/api/auth/login.php with JSON body: { email, password, user_type, remember }

3. API HANDLES AUTHENTICATION
   File: api/auth/login.php
   - Reads JSON input, sanitizes fields
   - Brute-force gate FIRST: LoginThrottle::check() — if either layer trips → HTTP 429 + Retry-After: 900, identical response regardless of account existence or credential validity
   - Routes query to correct table based on user_type (users/owners/admins) — line 33-42
   - Executes: SELECT id, password, name FROM {table} WHERE email = ? AND status = 'active'
   - If no user found OR verify_password() fails → logs warning, records failure row, returns { status: 'error', message: 'Invalid credentials' }
   - If success → calls Auth::startSession($user['id'], $user_type, $remember) — line 54, then resets this email+IP pair's throttle counter

4. SESSION IS ESTABLISHED
   File: app/helpers/Auth.php → startSession() (line 14)
   - Sets $_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['login_time'], $_SESSION['user_agent']
   - If remember flag → generates token, stores SHA-256 hash in remember_tokens table, sets 30-day cookie

5. RESPONSE SENT TO CLIENT
   File: api/auth/login.php (line 56-64)
   - Returns { status: 'success', data: { user_id, name, type } }

6. CLIENT REDIRECTS
   File: public/login.php → handleLogin() (line 314-323)
   - On success → window.location.href = 'dashboard/{driver|owner|admin}.php'

7. DASHBOARD VERIFIES SESSION
   File: e.g., public/dashboard/driver.php (line 6) → Auth::requireUserType('driver')
   - Calls requireLogin() → isSessionValid() → checks login_time timeout and user_agent match
   - If invalid → redirects to login.php?session=expired

SUBSEQUENT REQUESTS:
   File: app/helpers/Auth.php (lines 159-174)
   - Auto-executed on every page load: starts session, validates, auto-login from remember token
   - All API endpoints require Auth::requireLogin() / Auth::requireUserType()
```

### 6.3 Registration Flow

```
1. USER VISITS REGISTRATION PAGE
   File: public/register.php
   - PHP: checks Auth::isLoggedIn() → redirects if already logged in
   - Two-step UI: step 1 = user type selection (driver / owner), step 2 = full form

2. STEP 1: USER SELECTS TYPE
   File: public/register.php → function selectUserType(element, type) (line 492)
   - Updates hidden #user-type input
   - Toggles visibility of #driver-form / #owner-form
   - Disables inactive form fields via setFormFieldsState()

3. USER CLICKS "CONTINUE"
   File: public/register.php → function goToStep(2) (line 533)
   - Shows step 2, updates progress bar to 100%

4. STEP 2: USER FILLS FORM
   - Driver: name, email, phone, car model (datalist), battery capacity (dropdown + custom), preferred charger
   - Owner: name, company name, email, phone, bank account, description
   - BOTH: password, confirm password, terms checkbox

5. USER SUBMITS FORM
   File: public/register.php → async function handleRegister(event) (line 552)
   - Client-side validation:
     - If battery_capacity === 'other' → swaps custom value (line 560-567)
     - Passwords must match (line 577-579)
     - Password ≥ 8 chars (line 582-584)
     - Terms must be accepted (line 587-589)
   - Shows loading state: "Creating account..."
   - POST to /EE/api/auth/register.php with JSON body

6. API CREATES ACCOUNT
   File: api/auth/register.php
   - Validates email (filter_var), password length (≥8), phone (Nepali regex)
   - If driver → INSERT INTO users (email, password, name, phone, car_model, car_full_capacity_kwh)
   - If owner → INSERT INTO owners (email, password, name, company_name, phone, bank_account_number)
   - Uses hash_password() (bcrypt, cost 10)
   - On duplicate email → returns "Email already registered"
   - Returns { status: 'success', message: 'Registration successful' }

7. CLIENT SHOWS SUCCESS, REDIRECTS
   File: public/register.php → handleRegister() (line 620-631)
   - On success → shows green success message "Account created successfully!"
   - After 2s delay → redirects to login.php?type={driver|owner}

8. Alternative: GOOGLE ONE-TAP REGISTRATION
   File: public/register.php → async function handleGoogleRegister(response) (line 679)
   - Passes credential + user_type to api/auth/google.php
   - Endpoint verifies token, auto-registers if new (with random password), starts session immediately
   - On success → redirects directly to dashboard (no manual login needed)
```

---

## 7. Schema & Data Model Notes

### 7.1 activity_logs.details Column

The `activity_logs.details` column is defined as `TEXT` in `database/schema.sql` (line 368). It stores plain-text notification messages across all roles:
- Driver notifications: `"Charging session started. Total cost: NPR X.XX (NPR 50.00 reservation + NPR Y.YY charging)."`
- Driver notifications: `"Charging stopped early. Payment already made is NOT refunded."`
- This supports notification rendering in `driver.php`, `owner_sections/notifications.php`, and `admin_sections/notifications.php` without JSON parsing.

Three notification actions are actively written for driver-facing alerts: `session_started` (`api/bookings.php:283`), `session_stopped` (`api/bookings.php:385`), and `booking_expired` (`app/helpers/SessionTicker.php:43`). Each inserts into `activity_logs` scoped to the affected `user_id`, rendered by `sections/notifications.php`.

### 7.2 bookings.status ENUM

The `bookings.status` column includes six states (schema.sql line 184):
```sql
ENUM('booked', 'pending_payment', 'charging', 'completed', 'cancelled', 'stopped') DEFAULT 'booked'
```

- `stopped` is used exclusively for driver-initiated early session termination (`stop_session` action).
- `cancelled` is used for driver-initiated reservation cancellation before payment confirmation.
- `completed` is used for sessions that run to full estimated duration (via `complete_session` or `SessionTicker`).

### 7.3 buffer_ends_at Deprecation

The `buffer_ends_at` column (schema.sql line 180) is deprecated in the current implementation:
- It remains in the schema for backward compatibility but is never set by the current API.
- Timers and countdowns rely exclusively on `arrival_deadline` (reservation expiry) and `session_ends_at` (charging session expiry).
- The polling logic in `driver.php` checks `buffer_ends_at` but will skip display if NULL, falling back to `session_ends_at` behavior.

## 8. Financial Logic & Reporting

### 8.1 Payment Flow & Transactions

A single booking generates up to two `payment_transactions` records:
1. **Reservation fee** — inserted during `confirm_payment` (amount = `BOOKING_BASE_FEE`, transaction_id = `TXN{time}{booking_id}`)
2. **Charging fee** — inserted during `confirm_charging_payment` (amount = `kwh_needed * ELECTRICITY_RATE_PER_KWH`, transaction_id = `TXN{time}{booking_id}-CHG`)

Both rows have `status='completed'` and `currency='NPR'`.

### 8.2 Revenue Filtering Rule

All driver receipts, owner invoices, and admin financial reports must filter strictly on `payment_status = 'completed'` (not booking `status`). This ensures:
- `stopped` sessions (driver early termination) are counted in revenue because `booking.payment_status` is set to `'completed'`
- `cancelled` sessions are excluded because they never reach payment confirmation
- Only fully paid sessions contribute to financial totals

### 8.3 Cost Calculation

| Component | Source | Formula |
|---|---|---|
| Reservation fee | `BOOKING_BASE_FEE` config constant | Flat fee (NPR 50) |
| Charging cost | `ELECTRICITY_RATE_PER_KWH` × kWh needed | `(100 - battery_percent) / 100 * car_full_capacity_kwh * rate` |
| Total session cost | `base_fee + charging_cost` | Stored in `estimated_total_cost` after `confirm_charging_payment` |

**Known limitation:** kWh billing assumes every session charges to 100% (no end-battery-% capture). See audit item #8.

## 9. Architectural Observations & Recommendations

### 1. 🔴 Duplicate Password Toggle Logic

**Location:** `public/login.php` (line 348) and `public/register.php` (line 688)

Both files define a `togglePasswordVisibility()` function with nearly identical logic. `login.php` hardcodes element IDs (`#password`, `#eye-icon`) while `register.php` uses a parameterized version (`(inputId, iconId)`). The login version is brittle and cannot be reused for additional fields.

**Recommendation:** Extract a single `togglePasswordVisibility(inputId, iconId)` into `assets/js/auth.js` (a new shared JS file) and include it on both pages. Update `login.php` to call it with the explicit IDs. This eliminates duplication and makes maintenance easier.

**Effort:** ~10 minutes. Low risk since it's isolated inline JavaScript.

---

### 2. ✅ CSRF Protection on API Endpoints — RESOLVED 2026-08-24

**Location:** `api/bookings.php`, `api/stations.php`, `api/notifications.php` (+ helper `app/helpers/Csrf.php`)

~~None of the POST/PUT/DELETE API endpoints validate a CSRF token.~~ **Implemented:** session-bound token minted in `Auth::startSession()`, validated via `hash_equals()` against the `X-CSRF-Token` header on every authenticated state-changing request; delivered through `<meta name="csrf-token">` + a global fetch wrapper (`public/assets/js/csrf.js`). Full design, exemptions table, and test coverage: **§13 below**.

> Correction to this entry's original text: it claimed the session was isolated via `SESSION_COOKIE_SAMESITE = 'Lax'`, but those constants only configured the remember-me cookie — the session cookie ran on php.ini defaults until 2026-08-24, when `session_set_cookie_params()` was wired up in `Auth.php`.

---

### 3. ✅ Password Complexity / Validation Gaps — RESOLVED 2026-08-24

**Location:** `api/auth/register.php`, `public/assets/js/auth.js`, `public/login.php`

~~Config defined `PASSWORD_REQUIRE_UPPERCASE`/`PASSWORD_REQUIRE_NUMBERS` but nothing enforced them; registration only checked minimum length.~~ **Implemented:** the server now honors all three password flags (`PASSWORD_REQUIRE_SPECIAL_CHARS` remains intentionally `false`) plus `NAME_MIN_LENGTH`/`NAME_MAX_LENGTH` (2–100) — both previously dead config. Role fields gained real validation too: driver battery capacity must be > 0 and car model non-empty; owner company name non-empty and bank account digits-only (5–20). Client side mirrors everything: live ticking checklist under both password fields driven by a `window.PW_CONFIG` object injected from the same constants (can't drift), plus submit-time toasts; login's stale "6 characters" hint aligned to `PASSWORD_MIN_LENGTH`.

Regression coverage: integration suite checks 44–48 — each rejection message asserted against the live endpoint (validation runs before the OTP gate, so no SMTP needed), with check 48 proving a fully valid payload still clears every rule and reaches the gate.

**Client layer unified (2026-08-24):** both auth forms now run under a shared declarative validation engine (`public/assets/js/auth.js`) with `novalidate` + inline `.field-error` messages and red borders on every field — including login's email format (previously native-browser-only) — with rules mirrored from the same server constants via an injected `window.PW_CONFIG`; behavior verified by a headless-Chrome DOM matrix across every field/rule (invalid AND valid cases), incl. focus-first-offender ordering and a real authenticated-login round-trip.

---

### 4. 🟢 Inline JavaScript in Every Page — No Centralized Module

**Location:** `public/driver.php`, `public/owner.php`, `public/admin.php`, `public/login.php`, `public/register.php`, `public/index.html`

Each dashboard page contains hundreds of lines of inline JavaScript (driver.php: ~500 lines, owner.php: ~450 lines, admin.php: ~200 lines). This prevents caching, bloats HTML responses, and makes it impossible to use modern JS tooling (linting, TypeScript, bundling). The only shared JS file is `assets/js/modal.js` (96 lines).

**Recommendation:** Incrementally refactor common logic into separate JS modules:
- `assets/js/auth.js` — login/register/Google handlers, password toggle
- `assets/js/dashboard-base.js` — `loadSection()`, polling, logout, `showAlert`/`showConfirm` imports
- `assets/js/map.js` — Leaflet initialization, markers, geocoding
- `assets/js/booking-modal.js` — booking modal, payment flow

Leave role-specific code (owner charger management, driver polling, admin moderation) inline until the shared module foundation is stable.

**Effort:** ~4-6 hours for initial extraction. No functional change — purely organizational.

---

### 5. 🟢 Hardcoded Currency Symbol and Pricing Values

**Location:** `app/helpers/Auth.php` line 53 — INR symbol `₹` in `format_currency()`.  
`public/index.html` lines 227, 233, 239 — pricing section shows `₹20`, `₹8-12`, `₹50`.

The config file defines `ELECTRICITY_RATE_PER_KWH = 10` and `BOOKING_BASE_FEE = 50` (in NPR). But the `format_currency()` function outputs `₹` (Indian Rupee) rather than `₨` or `Rs.` (Nepali Rupee). The landing page HTML also uses `₹`. This is inconsistent with the location context (Kathmandu, Nepali phone validation).

**Recommendation:** Add a `define('CURRENCY_SYMBOL', '₨')` or `'NPR'` to config.php, update `format_currency()` to use it, and dynamically render pricing in the landing page from config values rather than hardcoded HTML.

**Effort:** ~30 minutes.

---

### Summary of Recommendations

| Priority | Issue | Effort | Impact |
|---|---|---|---|
| 🔴 High | Duplicate password toggle logic | 10 min | Maintenance burden, brittle code |
| 🟡 Medium | No CSRF protection | 2-3 hrs | Security gap for state-changing operations |
| 🟡 Medium | Password rules not enforced server-side | 15 min | Config intent not honored |
| 🟢 Low | No centralized JS modules | 4-6 hrs | Code organization, caching, tooling |
| 🟢 Low | Wrong currency symbol | 30 min | Brand accuracy |

---

## 10. Development Progress & Milestones

### Dashboard Standardization (2026-07-20)

The platform was audited and refactored to align with a standardized **9-page dashboard architecture** across three roles.

#### 9 Core Pages

| # | Page | Admin | Station Owner | Driver |
|---|---|---|---|---|
| 1 | **Overview / Dashboard** | `overview.php` | `overview.php` | `dashboard.php` |
| 2 | **Analytics** | `analytics.php` | `analytics.php` | — |
| 3 | **Orders / Sessions** | `orders.php` | `bookings.php` | `bookings.php` |
| 4 | **Customers / Drivers** | `customers.php` | — | — |
| 5 | **Invoices & Billing** | `invoices.php` | `invoices.php` | `receipts.php` |
| 6 | **Users & Team** | `users.php` | `team.php` | — |
| 7 | **Notifications** | `notifications.php` | `notifications.php` | — |
| 8 | **Settings** | `settings.php` | `settings.php` | `profile.php` |
| 9 | **Help & Support** | `support.php` | `support.php` | `support.php` |

#### Files Created (10 new section files)

**Admin (6 new):**
- `admin_sections/analytics.php` — Platform-wide metrics (bookings, revenue, kWh, active sessions)
- `admin_sections/orders.php` — All platform bookings with status filters
- `admin_sections/customers.php` — EV driver database (separated from users.php)
- `admin_sections/invoices.php` — Payment transactions and billing records
- `admin_sections/notifications.php` — Activity log feed
- `admin_sections/support.php` — Help resources and system info

**Station Owner (5 new):**
- `owner_sections/analytics.php` — Owner-specific performance metrics
- `owner_sections/invoices.php` — Revenue logs with paid/pending summaries
- `owner_sections/team.php` — Staff management placeholder
- `owner_sections/notifications.php` — Owner-scoped activity feed
- `owner_sections/settings.php` — Company info and preferences
- `owner_sections/support.php` — Owner-specific help resources

**Driver (2 new):**
- `sections/receipts.php` — Completed session payment receipts
- `sections/support.php` — Driver help and FAQ

#### RBAC Sidebar Navigation Updated

| Dashboard | Nav Items | Pages |
|---|---|---|
| `admin.php` | 12 | Overview, Analytics, Orders, Customers, Invoices, Users, Stations, Reviews, Reports, Notifications, Settings, Support |
| `owner.php` | 10 | Overview, Analytics, Invoices, My Stations, Bookings, Team, Notifications, Settings, Support, Company Profile |
| `driver.php` | 7 | My Hub, Find Stations, Charging Sessions, My Receipts, Favorites, Profile, Support |

#### Data Scoping Patterns

- **Admin:** `SELECT *` — global platform-wide data
- **Station Owner:** `WHERE owner_id = :current_user_id` — station-scoped queries
- **Driver:** `WHERE user_id = :current_user_id` — self-scoped queries

#### Deliberate Omissions

CRM, SaaS subscription management, and advanced charting dashboards were deliberately omitted to maintain a lean architecture focused on the core EV charging marketplace functionality.

---

### Entry Page Zenith UI Alignment (2026-07-20)

#### Landing Page (`public/index.php`)
- **Converted from `.html` to `.php`** with PHP session redirect at top — authenticated users bypass landing and go straight to their role dashboard
- **Zenith CSS Integration:** Replaced `landing.css` with `assets/css/dashboard.css`. All styling uses CSS variables (`--background`, `--card`, `--primary`, `--foreground`, `--border`, `--radius`, `--muted-foreground`, `--header-height`)
- **Option 2 Topbar:** Fixed header with `WattPulse` brand title + `EV Charging Network` subtitle; Login (`.btn-sm .btn-secondary`) and Register (`.btn-sm .btn-primary`) buttons
- **3 Role Cards:** `.role-card` grid with Driver Hub, Station Owner Portal, and Admin Access — each with contextual CTA buttons
- **Responsive:** 3-column role cards → 1-column on mobile; feature grid 3→2→1; footer 4→2→1

#### Login Page (`public/login.php`)
- **Zenith Card Wrapper:** `.auth-card` uses `var(--card)`, `var(--border)`, `var(--radius)` — matches dashboard component styling
- **Dark Gradient Background:** `linear-gradient(135deg, var(--primary) 0%, #1a1a2e 100%)` consistent with dashboard theme
- **Dynamic Role Badge:** Updates to "Admin" / "Station Owner" / "Driver" on tab switch
- **Input-Group Password Toggle:** `.input-group` with `var(--muted-foreground)` icon — consistent with dashboard form styling

#### Registration Page (`public/register.php`)
- **Same `.auth-card` wrapper** for visual parity with login page
- **Role Selection:** Segmented control grid using dashboard CSS variables
- **Dual Password Toggles:** Both Password and Confirm Password fields use `.input-group` pattern
- **Progress Bar:** `var(--primary)` fill, matches dark theme

#### CSS Audit
- Note: the `.sidebar-collapsed` rules remain present in `dashboard.css` and are **live** — `dashboard.js` toggles the class on the dashboard container. An earlier draft of this report wrongly claimed they were pruned; corrected per audit item #23.
- All utility classes, layout variables, and responsive breakpoints preserved
- No visual regressions — only dead code removed

---

## 11. Cross-Role UI Leakage & System Architecture Audit

> **Audit Date:** 2026-07-21  
> **Source:** `COMBINED_AUDIT_REPORT_1.md` (merged static-review + Cline analysis passes)

### 9.1 UI Scoping Strategy

To prevent CSS and JS changes in one role's dashboard from breaking another, a **role-scoped body class** architecture was implemented:

| Role | Body Class | File |
|---|---|---|
| Admin | `<body class="role-admin">` | `public/dashboard/admin.php` |
| Station Owner | `<body class="role-owner">` | `public/dashboard/owner.php` |
| Driver | `<body class="role-driver">` | `public/dashboard/driver.php` |

This enables targeted CSS overrides without global pollution:

```css
.role-admin .sidebar { ... }
.role-owner .nav-btn.active { background: linear-gradient(135deg, #34C759 0%, #20c997 100%); }
.role-driver .station-card { ... }
```

**Dark mode isolation:** Each role stores its theme preference under a role-scoped localStorage key (`dashboard-theme-{role}`). The landing page explicitly resets `data-theme` on load to prevent dark mode bleed from any dashboard.

### 9.2 JavaScript Safety & Modularization

**Event listener guards:** All `addEventListener` calls in `landing.js` are wrapped in null-checks to prevent `TypeError` crashes when elements don't exist in the current page context:

```js
if (getLocationBtn) {
    getLocationBtn.addEventListener('click', () => { ... });
}
```

**localStorage key scoping:** Sidebar collapse and theme state are stored per-role:

| State | Key Pattern | Scope |
|---|---|---|
| Sidebar collapsed | `sidebar-collapsed-{role}` | Per-role, per-browser |
| Dark mode | `dashboard-theme-{role}` | Per-role, per-browser |

**Global function isolation:** `showToast()` remains a global utility but is guarded against missing DOM elements. `window.bookStation` and `window.fetchNearbyStations` from `landing.js` are namespaced to prevent accidental cross-script collisions.

### 9.3 Audit Findings & Vulnerability Matrix

The following table summarizes all findings from the combined audit, ranked by severity:

| # | Issue | Affected Files / Roles | Severity | Status |
|---|---|---|---|---|
| 1 | Payment system is entirely simulated — no real gateway integration | `api/bookings.php` | 🔴 Critical | Unresolved |
| 2 | Booking queue race condition (charger double-booking via missing `FOR UPDATE`) | `api/bookings.php` | 🔴 Critical | Unresolved |
| 3 | No brute-force / rate limiting on login despite config constants existing | `api/auth/login.php`, `app/config/config.php` | 🔴 Critical | Unresolved |
| 4 | No CSRF protection on any state-changing endpoint | All `api/*.php` endpoints | 🔴 Critical | Unresolved |
| 5 | File upload validation trusts client-supplied `$_FILES['type']` | `dashboard/sections/profile.php`, `dashboard/owner_sections/profile.php` | 🔴 Critical | ✅ Resolved 2026-08-22 (`getimagesize()` validation + move failure checks implemented) |
| 6 | Owner can start charging sessions without payment (legacy `booked`-status path) | `api/bookings.php` | 🟠 High | ✅ Resolved 2026-08-22 (`start_session` removed entirely; charging exclusively driver-initiated and payment-gated) |
| 7 | Buffer/arrival timing inconsistent with config (`BOOKING_ARRIVAL_DEADLINE_MINUTES` vs hardcoded 5 min) | `api/bookings.php` | 🟠 High | Unresolved |
| 8 | kWh billing assumes every session charges to 100% — no end-battery input | `app/helpers/SessionTicker.php`, `api/bookings.php` | 🟠 High | Unresolved |
| 9 | Google OAuth auto-approves new owner accounts (bypasses admin moderation) | `api/auth/google.php` | 🟠 High | Unresolved |
| 10 | AJAX session-expiry breaks silently — login page HTML injected into dashboard | `app/helpers/Auth.php`, `loadSection()` in all dashboards | 🟠 High | Unresolved |
| 11 | Cascade-delete destroys financial history (no soft-delete on stations) | `database/schema.sql`, `api/stations.php` | 🟠 High | Unresolved |
| 12 | No audit trail for bookings or payments | `api/bookings.php` | 🟡 Medium | Unresolved |
| 13 | Server-side password complexity rules are dead config (never enforced) | `api/auth/register.php`, `app/config/config.php` | 🟡 Medium | Unresolved |
| 14 | Debug-mode errors leak raw exception text to client | `api/bookings.php`, `api/stations.php` | 🟡 Medium | Unresolved |
| 15 | No input length validation (`NAME_MAX_LENGTH` defined but never enforced) | `api/auth/register.php`, `api/stations.php` | 🟡 Medium | Unresolved |
| 16 | Google OAuth data run through `sanitize()` before storage (corrupts special chars) | `api/auth/google.php` | 🟡 Medium | Unresolved |
| 17 | Inconsistent use of `sanitize()` on string inputs | `api/bookings.php`, `api/stations.php` | 🟡 Medium | Unresolved |
| 18 | Duplicate password-toggle logic across login/register pages | `public/login.php`, `public/register.php` | 🟢 Low | Unresolved |
| 19 | Native `alert()` still used in `searchStations()` instead of themed `showAlert()` | `public/dashboard/driver.php` | 🟢 Low | Unresolved |
| 20 | Currency symbol mismatch — `format_currency()` outputs `₹` (INR) instead of NPR | `app/config/config.php` | 🟢 Low | Unresolved |
| 21 | No timeout on Nominatim reverse-geocode calls (can hang UI) | `public/dashboard/driver.php`, `public/assets/js/landing.js` | 🟢 Low | Unresolved |
| 22 | Log file has no rotation (`LOG_MAX_SIZE` defined but never enforced) | `app/config/config.php`, `app/helpers/Auth.php` | 🟢 Low | Unresolved |
| 23 | `PROJECT_REPORT.md` stale claim — `.sidebar-collapsed` CSS was reported as pruned but is still present in `dashboard.css` | `PROJECT_REPORT.md` (this file) | 🟢 Low | ✅ Fixed (documentation corrected) |

### 9.4 Persistent UI State Management

**Current model — Hybrid localStorage + Session:**

| State | Storage | Persistence | Scope |
|---|---|---|---|
| Sidebar collapsed | `localStorage` key `sidebar-collapsed-{role}` | Browser-only, per-role | Per-browser, survives refresh |
| Dark/light theme | `localStorage` key `dashboard-theme-{role}` + `<html data-theme>` attribute | Browser-only, per-role | Per-browser, survives refresh |
| Active dashboard section | URL query string (`?page=overview`) | Session-only | Per-tab, lost on navigation |
| User session | `$_SESSION` (server-side) | Session-only | Per-browser, expires after timeout |

**Planned — Database-backed UI preferences (future):**

A `user_ui_preferences` table is proposed to sync sidebar and theme state across devices:

```sql
CREATE TABLE user_ui_preferences (
    user_id INT NOT NULL,
    user_type ENUM('admin', 'owner', 'driver') NOT NULL,
    preferences JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, user_type)
);
```

The JSON payload would store:
```json
{
    "sidebar_collapsed": true,
    "theme": "dark",
    "last_section": "overview"
}
```

**Sync strategy:**
1. On page load → read from `localStorage` (instant, no network)
2. On preference change → write to `localStorage` + POST to `/api/preferences.php` (async, fire-and-forget)
3. On login from new device → fetch from DB, merge into `localStorage`, apply

This avoids blocking UI paint on network requests while still providing cross-device sync.

### 9.5 Future Refactoring & Roadmap

#### Week 1 — Critical (blocking production deployment)

| Task | Files | Effort |
|---|---|---|
| Real payment gateway integration + signature verification | `api/bookings.php` | 2-3 days |
| Booking queue row-locking (`SELECT ... FOR UPDATE`) | `api/bookings.php` | 2-4 hours |
| Login rate limiting / brute-force protection | `api/auth/login.php`, new table or cache | 4-6 hours |
| CSRF tokens on all state-changing endpoints | All `api/*.php` + new middleware | 2-3 hours |
| ~~File upload content validation via `getimagesize()`~~ ✅ Done 2026-08-22 | `dashboard/sections/profile.php`, `dashboard/owner_sections/profile.php` | 1 hour |

#### Week 2 — High

| Task | Files | Effort |
|---|---|---|
| ~~Require payment before session start~~ ✅ Done 2026-08-22 (`start_session` removed; driver-initiated flow) | `api/bookings.php` | 1-2 hours |
| Fix buffer/arrival timing drift | `api/bookings.php` | 1 hour |
| Real end-battery kWh billing | `api/bookings.php`, `app/helpers/SessionTicker.php` | 2-3 hours |
| Owner stations default to `pending` regardless of signup method | `api/auth/google.php` | 30 min |
| Fix AJAX session-expiry redirect | `app/helpers/Auth.php`, `loadSection()` in all dashboards | 1-2 hours |
| Soft-delete stations instead of cascading | `database/schema.sql`, `api/stations.php` | 2-3 hours |

#### Week 3 — Medium

| Task | Files | Effort |
|---|---|---|
| Audit trail logging for bookings/payments | `api/bookings.php` | 2-3 hours |
| Server-side password complexity enforcement | `api/auth/register.php` | 30 min |
| Stop leaking raw exception messages | `api/bookings.php`, `api/stations.php` | 30 min |
| Input length validation | `api/auth/register.php`, `api/stations.php` | 30 min |
| Stop `sanitize()`-ing OAuth payload data | `api/auth/google.php` | 15 min |
| Normalize `sanitize()` usage | `api/bookings.php`, `api/stations.php` | 30 min |

#### Week 4 — Low / Polish

| Task | Files | Effort |
|---|---|---|
| Extract shared password-toggle JS module | `public/login.php`, `public/register.php`, new `assets/js/auth.js` | 30 min |
| Replace `alert()` with `showAlert()` in driver search | `public/dashboard/driver.php` | 15 min |
| Fix currency symbol to NPR | `app/config/config.php` | 15 min |
| Add AbortController timeout to Nominatim calls | `public/dashboard/driver.php`, `public/assets/js/landing.js` | 30 min |
| Implement log rotation | `app/helpers/Auth.php` | 30 min |

#### Template Layout Partials (Architecture)

To eliminate the triple-duplication of dashboard HTML, create role-scoped layout partials:

```
templates/
  ├── layout_admin.php
  ├── layout_owner.php
  ├── layout_driver.php
  └── layout_landing.php
```

Each contains the full `<head>`, `<body>`, sidebar, header, and footer with only the elements needed for that role. This ensures a CSS/JS fix applied to one role's layout automatically propagates to all roles without manual triple-application.

#### API-Level Data Scoping

All database queries in `api/stations.php` and `api/bookings.php` must enforce strict user-ID filtering:

| Endpoint | Current Scope | Required Scope |
|---|---|---|
| `GET /api/stations.php` (owner) | `WHERE owner_id = ?` | ✅ Already scoped |
| `GET /api/bookings.php` (driver) | `WHERE user_id = ?` | ✅ Already scoped |
| `GET /api/stations.php` (admin) | No filter (global) | ✅ Correct for admin |
| `DELETE /api/stations.php` | Owner or admin | ⚠️ Verify owner_id matches session before delete |

### 9.6 Overall Risk Assessment

| Category | Rating | Notes |
|---|---|---|
| **Authentication** | ✅ Solid | bcrypt, session timeout, user-agent check |
| **Authorization** | ✅ Consistent | Role guards applied across all endpoints |
| **Payments** | ❌ Not real | Blocks production readiness |
| **Concurrency safety** | ❌ Exploitable | Booking queue race condition under load |
| **CSRF hardening** | ✅ Closed 2026-08-24 | Session-bound tokens on all state-changing endpoints (§13); login/logout exemptions documented there |
| **File upload validation** | ❌ Still absent | Client-supplied data trusted — separate backlog item |
| **Financial integrity** | ⚠️ Weak | No audit trail, cascade-deletes destroy history, billing assumes ideal-case charging |

**Bottom line:** The application is further along than a prototype but is not production-ready. The payment simulation, concurrency gap, and file upload validation need to close before this touches real money or real users. (CSRF protection closed 2026-08-24 — see §13.)

---

## 10. Known Fragility — Relative API Paths (2026-08-23)

> **TODO:** ~15 inline `fetch('../api/…')` calls across `public/dashboard/{driver,owner,admin}.php` only resolve correctly under rewritten short URLs; under canonical `/EE/public/dashboard/*.php` paths they break exactly like the notification-bell bug fixed today (`dashboard.js` bell fetches now use root-absolute `/EE/api/…`, matching `auth.js`/`landing.js`). If the URL structure ever changes, migrate all client-side fetches to the root-absolute pattern.
>
> **Related fix (2026-08-23, server-side):** `APP_URL` in `app/config/config.php` still pointed at the nonexistent legacy directory `ev-charging-station`, sending expired-session redirects (via `app/helpers/Auth.php`) to a 404 — same URL-structure fragility, redirect layer instead of fetch layer. Now `http://localhost/EE`.
>
> **Known cosmetic issue (2026-08-23, Firefox only):** Google Sign-In shows minor intra-button visual churn specifically in Firefox — caused by Firefox's lack of full FedCM support forcing GIS into its legacy account-detection flow inside Google's own iframe. Confirmed non-functional (inert injected marker class on `<body>`, zero matching CSS rules anywhere) and confined to the button's reserved space (no page layout shift). Investigated and accepted as-is — outside our code's control, Google-owned rendering.
>
> **Known fragility (2026-08-24, pre-existing): intermittent `.htaccess` internal-redirect loops (`AH00124`).** Apache's error log records `Request exceeded the limit of 10 internal redirects due to probable configuration error` episodes — at least once on 2026-08-23 (referer: `driver.php`) and again 2026-08-24 during integration-test runs, i.e. the fragility **predates the CSRF work**. During such episodes affected requests fail unpredictably (observed as empty/null response bodies under rapid sequential requests). Suspected cause: recursion between the rewrite rules and the relative-path redirects described in the first TODO above. Backlog: audit `.htaccess` recursion paths (review `[L]`/`END` flags and rewrite conditions), or eliminate the dependency entirely by migrating remaining client-side fetches to root-absolute URLs.
>
> **Security backlog (2026-08-24): `api/auth/logout.php` open redirect.** The endpoint feeds `$_GET['redirect']` straight into `header('Location: …')` with no validation, allowing attacker-crafted post-logout redirects (phishing vector). Low severity, trivial fix: allowlist relative paths only (e.g. reject values starting with a scheme or `//`). Related context: the logout-CSRF exemption rationale lives in §13.

---

## 11. Remember Me — Token Rotation & Logout Hardening (2026-08-23)

The Remember Me checkbox was previously non-functional end-to-end: the token was stored hashed correctly, but the boot sequence ran the session-expiry bail **before** the auto-login hook (destroying the remember cookie on the exact path the feature targets), consumed tokens were never rotated, `logout()` left DB rows alive, and `public/logout.php` bypassed `Auth::logout()` entirely (hand-rolled session wipe — meaning Logout didn't actually log out).

**Now implemented** (`app/helpers/Auth.php`):
- **Ordered boot gate** — `Auth::boot()` runs on every request: valid sessions continue; stale sessions are wiped (session only) and offered a remember-me rescue; unrescuable stale sessions redirect to `?session=expired` exactly as before; plain guests fall through to the unchanged `requireLogin()`/`requireUserType()` gates.
- **Single-use tokens with rotation** — every successful auto-login consumes the old row and mints a fresh 30-day token + cookie. A stolen cookie dies the moment the legitimate client uses theirs.
- **Fail-closed liveness check** — auto-login validates the account still exists with `status='active'` in `users`/`owners`/`admins`; ghost accounts get their token row deleted and cookie cleared.
- **Logout wipes all devices** — `Auth::logout()` deletes every `remember_tokens` row for the identity; `public/logout.php` now routes through it.
- **Lazy expiry sweep** — expired rows purged opportunistically on remembered login.
- **UA guard hardened** — `HTTP_USER_AGENT` missing (non-browser clients) no longer emits a PHP warning into JSON response bodies; both sides of the comparison use null-coalescing.
- **Cookie flags** — HttpOnly + SameSite=Lax; `secure` intentionally `false` while on `http://localhost` (browsers drop Secure cookies over plain HTTP) — **must flip `SESSION_COOKIE_SECURE` when HTTPS is deployed**.

Regression coverage: integration suite checks 27–30b (unauthenticated/expired redirect targets, hash-at-rest proof, auto-login + rotation, replay rejection, tampered-token fail-closed, logout wipe).

---

## 12. Login Rate Limiting — Two-Layer Brute-Force Protection (2026-08-24)

Failed logins are now throttled via `app/helpers/LoginThrottle.php` backed by the new `login_attempts` table (`database/schema.sql` §16):

- **Layer 1 (email+IP pair):** ≥ `LOGIN_MAX_ATTEMPTS` (5) failures within `LOGIN_LOCKOUT_WINDOW` (900s) locks that pair.
- **Layer 2 (spray net):** ≥ `LOGIN_IP_MAX_ATTEMPTS` (20) failures across ALL emails from one IP locks the entire IP — catches multi-account password spraying.
- **Semantics:** thresholds are `COUNT(*) >= N` evaluated BEFORE the current failed attempt is recorded, so N wrong tries execute and request N+1 is the first 429 (approved interpretation of the design's off-by-one wording).
- Locked requests get HTTP 429 + `Retry-After: 900` and an account-existence-neutral body, whichever layer tripped and whether or not the submitted credentials were valid.
- Identity source is raw `$_SERVER['REMOTE_ADDR']` only — X-Forwarded-For is client-spoofable. Scope is `api/auth/login.php` exclusively; `google.php` untouched (OAuth abuse handled upstream).
- Successful login resets ONLY that email+IP pair's rows (never IP-wide — a reset must never launder an attacker's spray rows against other emails); every `check()` also lazily purges rows older than the window (no cron needed).
- Successes remain tracked only in `logs/app.log`; the table counts failures exclusively.
- Regression coverage: integration suite checks 31–37 + self-cleaning teardown (legit login at zero failures, pair lockout on 6th request, valid creds still blocked while locked, PDO-simulated window expiry, full-budget-after-reset proof, 20-row spray causing instant 429 of a brand-new email, pure-Layer-2 block of correct credentials). Cross-IP isolation is not runtime-testable from a single-host suite — covered by code review of the `ip_address = ?` WHERE clauses only.

---

## 13. CSRF Protection — Session-Bound Tokens (2026-08-24)

Every authenticated state-changing endpoint now requires a per-session token:

- **Minting:** `Auth::startSession()` sets `$_SESSION['csrf_token'] = generate_token(32)` — one choke point covers password login, remember-me rescue, and OAuth. Rotates per identity establishment, not per request (multi-tab/back-button safe).
- **Validation:** `app/helpers/Csrf.php::validate()` — timing-safe `hash_equals()` against the `X-CSRF-Token` header; failures return **HTTP 403** with `"Invalid security token. Please refresh the page and try again."` (deliberately distinct from 429/generic errors).
- **Coverage:** `api/bookings.php` (POST+DELETE), `api/stations.php` (POST/PUT/DELETE — incl. admin approve/reject), `api/notifications.php` (POST).
- **Delivery:** `<meta name="csrf-token">` server-rendered in the three dashboard shells; `public/assets/js/csrf.js` wraps global `fetch()` so every same-origin non-GET call carries the header automatically — zero edits to the ~73 existing fetch call sites, with an explicit cross-origin guard so the token never leaves the origin.
- **Cookie hardening shipped alongside:** `session_set_cookie_params()` now applies `SESSION_COOKIE_SECURE/HTTPONLY/SAMESITE` to the **PHP session cookie itself** (previously those constants configured only the remember-me cookie — the session cookie ran on php.ini defaults).

### Accepted / deferred exemptions (reasoned, not oversights)

| Surface | Status | Reasoning |
|---|---|---|
| `api/auth/login.php`, `register.php`, `otp.php` | **Accepted exemption** | No session exists pre-auth to bind a token to. Login-CSRF (forced-login attacks) is a distinct, lower-severity class — deferred until a need is demonstrated. |
| `api/auth/google.php` | **Accepted exemption** | Google OAuth carries its own upstream credential; consistent with rate-limiting scope precedent. |
| `api/auth/logout.php` | **Deferred** | Currently a plain GET-able link; enforcing tokens breaks the UI affordance. Logout-CSRF is nuisance-grade. NOTE: this file also takes `$_GET['redirect']` into `header('Location:')` — open redirect flagged for backlog. |
| GET endpoints (`stats.php`, `nearby-stations.php`, all reads) | Out of scope by definition | CSRF targets state changes. |

Regression coverage: integration suite checks 38–43 (meta-token delivery through real shell HTML, valid-token acceptance, missing/tampered rejection with distinct message, foreign-session token binding, login-without-token regression, GET scope guard).
