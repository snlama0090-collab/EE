# 🔍 WattPulse EV Charging Platform — Combined Audit Report

**Sources merged:** Independent static-review pass (security/infra focus) + Cline's pass (financial/business-logic focus).
**Method:** De-duplicated overlapping findings, cross-checked disputed items against actual code, and re-ranked everything into one severity order.

---

## 🔴 Critical

### 1. Payment system is entirely simulated
**`api/bookings.php` (`confirm_payment`, lines ~156–228)**
There is no real payment gateway integration. "Payment confirmation" just generates a fake `transaction_id = 'TXN' . time() . ...`, sets `payment_method = 'wallet'`, and marks it `completed` — no money actually moves, no gateway call happens. `razorpay_order_id` / `razorpay_payment_id` / `razorpay_signature` exist in the schema but are never populated or verified.
**Fix:** Integrate Razorpay (or equivalent) SDK, verify `razorpay_signature` server-side before marking any booking as paid.
> **[2026-08-28 update]** Superseded: Razorpay rejected (India-first, no NPR settlement for Nepali merchants); columns renamed to `gateway_*`; **Khalti** chosen (sandbox without business onboarding). See PROJECT_REPORT §19.

### 2. Booking queue race condition (charger double-booking)
**`api/bookings.php` (`initiate_payment` ~90–109, legacy path ~248–267)**
The "is this charger's queue full?" check is a plain `SELECT COUNT(*)` inside a transaction, not `SELECT ... FOR UPDATE`. Two concurrent requests for the same charger can both pass the check before either commits, exceeding the intended 2-booking cap.
**Fix:** Add `FOR UPDATE` row locking on the queue-check query, or add a unique/partial constraint enforcing max-active-bookings-per-charger at the DB level.

### 3. No brute-force / rate limiting on login, despite config claiming otherwise
**`app/config/config.php` (`API_RATE_LIMIT_*` constants) + `api/auth/login.php`**
The constants exist and `admin_sections/settings.php` even displays them in the UI, but nothing in the codebase reads or enforces them. `app.log` shows this being actively exploited — dozens of `Failed login attempt for admin@example.com` within the same second, repeated across multiple days.
**Fix:** Track failed attempts per email+IP in a table/cache, lock out or exponentially delay after N failures within the configured window.

### 4. No CSRF protection on any state-changing endpoint
**All of `api/bookings.php`, `api/stations.php`, `api/auth/*.php`**
No token generation, no validation anywhere. `SESSION_COOKIE_SAMESITE = 'Lax'` gives partial browser-level cover only. Admin station approve/reject and booking cancel/payment endpoints are all exposed.
**Fix:** Generate a CSRF token at login, store in session, require it (header or body) on every non-GET request.

### 5. File upload validation trusts client-supplied data
**`dashboard/sections/profile.php` (driver avatar), `dashboard/owner_sections/profile.php` (company logo)**
Both validate uploads using `$_FILES[...]['type']` — a browser-supplied, attacker-controllable header — plus a file-extension string check. Neither verifies the file is actually an image.
**Fix:** Validate with `getimagesize($tmp_name)` (or `finfo_file`) on the actual file contents before calling `move_uploaded_file()`.

---

## 🟠 High

### 6. Owner can start charging sessions without payment
**`api/bookings.php` (`start_session`, legacy `booked`-status branch, ~322–361)**
A booking in `booked` status (no payment ever taken) can transition straight to `charging` via the owner's legacy start-session path. Combined with #1 above, this is a second way free charging can happen.
**Fix:** Require `payment_status = 'completed'` before allowing `start_session`; remove or gate the unpaid `booked`-status branch.

### 7. Buffer/arrival timing is inconsistent with its own config
**`api/bookings.php` (lines ~124, 190, 349)**
`BOOKING_ARRIVAL_DEADLINE_MINUTES = 20` in config, but `buffer_ends_at` is hardcoded to `DATE_ADD(NOW(), INTERVAL 5 MINUTE)` in three separate places, and the buffer starts counting from *payment confirmation*, not from actual driver arrival. Config and behavior have drifted apart.
**Fix:** Either derive the buffer from `BOOKING_ARRIVAL_DEADLINE_MINUTES` consistently, or start the buffer only on a physical-arrival signal.

### 8. kWh billing assumes every session charges to 100%
**`app/helpers/SessionTicker.php` (line ~52), `api/bookings.php` (`complete_session`, ~398)**
`$kwh = (100 - $start_pct) / 100 * $capacity` — there's no end-battery-percentage input anywhere, so a driver who stops charging early is billed as if they charged to full.
**Fix:** Capture actual end-battery % (manual entry or telemetry) and bill on the real delta, not an assumed 100%.

### 9. Google OAuth auto-approves new owner accounts/stations
**`api/auth/google.php` (~lines 100–121)**
New owners registering via Google are inserted with `approval_status = 'approved'` immediately — bypassing the admin moderation flow that the normal `register.php` path goes through.
**Fix:** New owners should default to `pending` regardless of registration method; require explicit admin approval either way.

### 10. AJAX session-expiry breaks silently mid-navigation
**`app/helpers/Auth.php` global session check + `loadSection()` in `driver.php`/`owner.php`/`admin.php`**
`Auth.php`'s auto-check runs on every include, including the section-fragment files fetched via `fetch()`. If a session expires and the user clicks a nav item, the fetch gets a redirect-to-login response, and `loadSection()` just injects that HTML straight into `#content-area` — broken login form wedged inside the dashboard, instead of a real redirect.
**Fix:** Detect the login-page/redirect response in the fetch handler (or add a dedicated header from `Auth.php`) and force `window.location` to the real login page.

### 11. Cascade-delete destroys financial history
**`database/schema.sql`: `stations` → `chargers` → `bookings` → `charging_sessions`/`payment_transactions`, all `ON DELETE CASCADE`**
An owner deleting a station via `DELETE /api/stations.php` silently wipes every historical booking, session, and payment record tied to it — no soft-delete, no archive, no audit trail of what was removed.
**Fix:** Soft-delete stations (`is_deleted` flag / status) instead of hard-deleting, or at minimum archive financial rows before cascade.

---

## 🟡 Medium

### 12. No audit trail for bookings or payments
`activity_logs` is only ever written to for admin actions (approve/reject/login). Nothing logs booking creation, payment confirmation, session start/stop, or cancellations — so financial disputes or fraud can't be reconstructed after the fact.
**Fix:** Add `log_action()`-style calls at every state-changing point in `api/bookings.php`.

### 13. Server-side password complexity rules are dead config
`PASSWORD_REQUIRE_UPPERCASE` / `PASSWORD_REQUIRE_NUMBERS` are defined but never checked in `api/auth/register.php` — only length (`≥8`) is enforced, client and server. `password123`-style weak passwords pass fine today.
**Fix:** Add regex validation server-side matching the config flags' stated intent.

### 14. Debug-mode errors leak raw exception text to the client
**`api/bookings.php` (~450–454), `api/stations.php` (~391–395)**
With `DEBUG = true`, `catch` blocks return `$e->getMessage()` directly in the JSON response — database/schema details exposed to anyone hitting the endpoint.
**Fix:** Return a generic "Server error" message to the client always; log the real message server-side only.

### 15. No input length validation
`NAME_MAX_LENGTH` (100) is defined but never enforced in `api/auth/register.php` or `api/stations.php` — a 10,000-character name would either get silently truncated by MySQL or throw a raw PDO error (compounding #14).
**Fix:** Add a `validate_name()`-style helper, apply consistently across registration and station endpoints.

### 16. Google OAuth data run through `sanitize()` before storage
**`api/auth/google.php`**
`email`, `name`, and `picture` from Google's *already-verified* token payload get `htmlspecialchars()`-encoded via `sanitize()` before being stored — this can corrupt names/emails with special characters (e.g., `O'Brien` → `O&#039;Brien` in the DB) and is unnecessary since this data goes into parameterized queries, not raw HTML output.
**Fix:** Store the verified payload data as-is (still parameterized-query-safe); only `htmlspecialchars()` at render time if ever echoed into HTML.

### 17. Inconsistent use of `sanitize()` on string inputs
Scattered across `api/bookings.php` (~300, 443) and `api/stations.php` (~325, 358) — low practical risk since PDO parameterization is the actual injection defense, but worth normalizing for consistency.

---

## 🟢 Low / Polish

### 18. Duplicate password-toggle logic
`login.php` hardcodes `togglePasswordVisibility()` to `#password`/`#eye-icon`; `register.php` has a parameterized version doing the same job. Extract one shared version into `assets/js/auth.js`.

### 19. Native `alert()` still used in one place
`driver.php`'s `searchStations()` calls plain `alert('Please enter a location')` instead of the themed `showAlert()` from `modal.js` used everywhere else — inconsistent UX polish, not a bug.

### 20. Currency symbol mismatch
`format_currency()` in `config.php` outputs `₹` (INR) while every price in the app is expressed in NPR. Should be `Rs.` / `NPR`.

### 21. No timeout on Nominatim reverse-geocode calls
`driver.php` (~lines 272–276) and similar calls elsewhere `fetch()` Nominatim with no `AbortController`/timeout — a slow or hung response can leave the UI stuck.
**Fix:** Add an 8–10s `AbortController` timeout with a graceful fallback.

### 22. Log file has no rotation
`LOG_MAX_SIZE` is defined in config but `log_message()` never checks or enforces it — `app.log` grows unbounded.

### 23. `PROJECT_REPORT.md` contains a stale claim
It states `.sidebar-collapsed` CSS was pruned from `dashboard.css` as dead code — it's still present in the file. Not a functional bug, but a reminder that the project's own documentation has drifted from reality in at least this one spot.

---

## ⚠️ Reconsidered / downgraded from the original passes

**"Google OAuth `user_type` not validated → privilege escalation"** — On closer inspection of `google.php`, admin accounts *cannot* be created this way: the code requires a pre-existing, active admin row for `user_type = 'admin'`, so there's no path to escalate into admin via OAuth. For `driver`/`owner`, letting the client choose which role to self-register as mirrors the normal `register.php` flow — that's intentional self-service, not a broken access control. Downgraded from a distinct vulnerability to a non-issue; folded conceptually into #9 above (the real problem is auto-*approval*, not role selection).

---

## 📌 Unified priority order

**Week 1 — Critical, do not deploy without these:**
1. Real payment gateway integration + signature verification (#1)
2. Booking queue row-locking / race condition fix (#2)
3. Login rate limiting / brute-force protection (#3)
4. CSRF tokens on all state-changing endpoints (#4)
5. File upload content validation via `getimagesize()` (#5)

**Week 2 — High:**
6. Require payment before `start_session` (#6)
7. Fix buffer/arrival timing drift (#7)
8. Real end-battery kWh billing (#8)
9. Owner stations default to `pending` regardless of signup method (#9)
10. Fix AJAX session-expiry redirect (#10)
11. Soft-delete stations instead of cascading (#11)

**Week 3 — Medium:**
12. Audit trail logging for bookings/payments (#12)
13. Server-side password complexity enforcement (#13)
14. Stop leaking raw exception messages (#14)
15. Input length validation (#15)
16. Stop `sanitize()`-ing OAuth payload data (#16)
17. Normalize `sanitize()` usage (#17)

**Week 4 — Low / polish:**
18–23. Shared JS module extraction, alert() consistency, currency symbol, Nominatim timeout, log rotation, doc cleanup.

---

## 🧭 Overall risk assessment

**Authentication:** ✅ Solid mechanics (bcrypt, session timeout, user-agent check) undermined by ❌ zero rate limiting.
**Authorization:** ✅ Role guards are consistently applied across all endpoints.
**Payments:** ❌ Not real — this alone blocks production readiness.
**Concurrency safety:** ❌ Booking queue is exploitable under load.
**CSRF / upload hardening:** ❌ Both absent.
**Financial integrity:** ⚠️ No audit trail, cascade-deletes destroy history, billing assumes ideal-case charging.

**Bottom line:** the app is further along than "prototype" but genuinely not production-ready — the payment simulation and the concurrency/CSRF/upload gaps need to close before this touches real money or real users. 🛠️
