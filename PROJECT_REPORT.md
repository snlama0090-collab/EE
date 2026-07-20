# EV Charging Station Finder — Project Architecture Report

> **Generated:** 2026-07-15  
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
| **Find & Book** | Driver | Landing page → leaflet map → station cards with distance/battery details → modal with charger selection + battery % → `initiate_payment` → `confirm_payment` → session begins |
| **Charging Lifecycle** | Owner + Driver | `booked` → `pending_payment` (driver pays) → `charging` (owner starts session) → `completed` (auto-tick via `SessionTicker` or owner completes) → release charger |
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

### Database Schema Overview (14 tables)

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
│   │   └── SessionTicker.php # Auto-complete overdue charging sessions
│   └── logs/                 # Application log output
│
├── api/
│   ├── auth/
│   │   ├── login.php         # POST: email + password authentication
│   │   ├── register.php      # POST: driver/owner account creation
│   │   ├── logout.php        # GET: session destroy + redirect
│   │   └── google.php        # POST: Google OAuth token verification + auto-register
│   ├── bookings.php          # GET/POST/PUT/DELETE: full booking lifecycle
│   └── stations.php          # GET/POST/PUT/DELETE: stations, chargers, admin actions
│
├── database/
│   └── schema.sql            # Full DDL for all 14 tables + sample data
│
├── public/
│   ├── index.html            # Landing page (hero, features, pricing, CTA)
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
│       │   ├── bookings.php
│       │   ├── favorites.php
│       │   └── profile.php
│       │
│       ├── owner_sections/    # Owner sub-pages loaded via AJAX
│       │   ├── overview.php
│       │   ├── financials.php
│       │   ├── stations.php
│       │   ├── bookings.php
│       │   └── profile.php
│       │
│       └── admin_sections/    # Admin sub-pages loaded via AJAX
│           ├── overview.php
│           ├── stations.php
│           ├── users.php
│           ├── reviews.php
│           ├── reports.php
│           └── settings.php
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

Additionally, a **Guest** (unauthenticated) role exists, which can only access `index.html`, `login.php`, and `register.php`. All other pages and API endpoints enforce authentication.

### Role Enforcement Points

1. **Auth.php** — `requireUserType($type)` (line 93) calls `requireLogin()` then checks `$_SESSION['user_type']`. If mismatch → HTTP 403 "Access Denied".

2. **Dashboard entry files** — Each dashboard file calls `Auth::requireUserType(...)` at the top:
   - `driver.php` line 6: `Auth::requireUserType('driver')`
   - `owner.php` line 6: `Auth::requireUserType('owner')`
   - `admin.php` line 5: `Auth::requireUserType('admin')`

3. **API endpoints** — `stations.php` and `bookings.php` call `Auth::requireLogin()` and then switch logic based on `Auth::getCurrentUserType()`. Each action validates the user type before execution (e.g., only owners can `start_session`, only admins can `approve` stations).

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
- Line 47: `verify_password($password, $user['password'])` via bcrypt
- Line 54: `Auth::startSession($user['id'], $user_type, $remember)`
- Line 48: Failed attempts logged via `log_message('WARNING', ...)`

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

**Purpose:** Full booking CRUD with prepaid payment flow and owner session management.

**Key Endpoints:**
| Method + Action | Line | Description |
|---|---|---|
| `GET` | 19-48 | Fetch bookings (user-specific or owner-specific) |
| `POST / initiate_payment` | 61-153 | Driver submits charger ID + battery % → calculates cost/time → inserts `pending_payment` booking |
| `POST / confirm_payment` | 156-228 | Driver confirms payment → status to `charging`, creates `charging_sessions`, inserts `payment_transactions`, sets `buffer_ends_at` and `session_ends_at` timers |
| `PUT / start_session` | 322-372 | Owner starts session for legacy (no-payment) bookings; sets buffer + session timers |
| `PUT / complete_session` | 374-427 | Owner completes session → calculates kWh, cost, updates station stats, releases charger |
| `DELETE` | 442-448 | Cancel booking (status → `cancelled`) |

**Queue Management:** Lines 90-109 — maximum 2 bookings per charger; if 1 existing booking is `booked`/`pending_payment`, new booking is rejected.

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
- `bookStation(stationId)` — creates modal with charger selector + battery % input, calls `initiate_payment`
- `confirmPayment(bookingId)` — POST to `confirm_payment`, starts polling
- `startPollingIfNeeded()` / `pollTick()` — polls every 12s for active bookings, updates countdown timers (buffer phase warning → charging phase green → expiry reload)
- `cancelBooking(id)` / `doCancelBooking(id)` — DELETE to `api/bookings.php`

### 5.12 `public/dashboard/owner.php`

**Purpose:** Owner dashboard — station registration with Leaflet location picker (draggable marker), charger management, booking session start/stop, financial charts.

**Key Functions:**
- `initLocationPickerMap()` — draggable marker on Leaflet map, reverse geocodes on drag/click
- `submitStation(event)` — collects form data + charger rows, POST to `api/stations.php`
- `manageStationChargers(stationId, stationName)` — AJAX load charger list with status dropdowns
- `updateChargerStatus(chargerId, newStatus, stationId, stationName)` — POST to `update_charger_status`
- `updateSession(bookingId, action)` — modal for battery % input before starting session, then `doUpdateSession()`
- `switchFinancialView(period)` — Chart.js bar/line chart switching between days/months/years
- `deleteStation(id)` — confirmation + DELETE to `api/stations.php`

### 5.13 `public/dashboard/admin.php`

**Purpose:** Admin dashboard — station review/moderation with detail modal (Leaflet map, charger table), approve/reject flow.

**Key Functions:**
- `loadSection(sectionName)` — AJAX loads admin section partials
- `approveStation(stationId)` / `rejectStation(stationId)` — POST to `api/stations.php?action=approve|reject`
- `viewStationDetails(stationId)` — opens modal with Leaflet map, charger table, approve/reject buttons
- `doModalApprove(id)` / `doModalReject(id)` — confirm dialog → API call → close modal + reload section

---

## 6. Critical Workflows (Step-by-Step)

### 6.1 Authentication Flow

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
   - Routes query to correct table based on user_type (users/owners/admins) — line 33-42
   - Executes: SELECT id, password, name FROM {table} WHERE email = ? AND status = 'active'
   - If no user found OR verify_password() fails → logs warning, returns { status: 'error', message: 'Invalid credentials' }
   - If success → calls Auth::startSession($user['id'], $user_type, $remember) — line 54

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

### 6.2 Registration Flow

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

## 7. Architectural Observations & Recommendations

### 1. 🔴 Duplicate Password Toggle Logic

**Location:** `public/login.php` (line 348) and `public/register.php` (line 688)

Both files define a `togglePasswordVisibility()` function with nearly identical logic. `login.php` hardcodes element IDs (`#password`, `#eye-icon`) while `register.php` uses a parameterized version (`(inputId, iconId)`). The login version is brittle and cannot be reused for additional fields.

**Recommendation:** Extract a single `togglePasswordVisibility(inputId, iconId)` into `assets/js/auth.js` (a new shared JS file) and include it on both pages. Update `login.php` to call it with the explicit IDs. This eliminates duplication and makes maintenance easier.

**Effort:** ~10 minutes. Low risk since it's isolated inline JavaScript.

---

### 2. 🟡 No CSRF Protection on API Endpoints

**Location:** `api/auth/login.php`, `api/auth/register.php`, `api/bookings.php`, `api/stations.php`

None of the POST/PUT/DELETE API endpoints validate a CSRF token. The session is isolated to same-origin via `SESSION_COOKIE_SAMESITE = 'Lax'` (config.php line 36), which provides basic browser-level CSRF protection, but does not protect against subdomain attacks or XSS-based exploitation.

**Recommendation:** Generate a CSRF token on login (stored in `$_SESSION`), include it in all state-changing requests (in a `X-CSRF-Token` header or `_csrf` body field), and validate server-side before processing. This is especially important for admin actions like station approval/rejection.

**Effort:** ~2-3 hours to implement token generation, middleware function, and wire into all API endpoints.

---

### 3. 🟡 Password Complexity Requirements Not Enforced Server-Side

**Location:** `api/auth/register.php` (lines 25-28), `api/auth/login.php`

The config defines `PASSWORD_REQUIRE_UPPERCASE` and `PASSWORD_REQUIRE_NUMBERS` (config.php lines 73-74), but the registration API only checks minimum length (`PASSWORD_MIN_LENGTH`). The uppercase/number requirements are not validated on the server. Client-side validation in `register.php` also only checks length.

**Recommendation:** Add server-side checks in `api/auth/register.php` to validate uppercase and numeric requirements when their respective config flags are true, matching the config intent. Also enforce on password change endpoints if added later.

**Effort:** ~15 minutes.

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

The config file defines `ELECTRICITY_RATE_PER_KWH = 10` and `BOOKING_BASE_FEE = 20` (in NPR). But the `format_currency()` function outputs `₹` (Indian Rupee) rather than `₨` or `Rs.` (Nepali Rupee). The landing page HTML also uses `₹`. This is inconsistent with the location context (Kathmandu, Nepali phone validation).

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

## 8. Development Progress & Milestones

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
