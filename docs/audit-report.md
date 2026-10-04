# EV Charging Platform — Read-Only Audit Report

**Date:** 2026-10-04 · **Mode:** static, read-only audit (no project code was modified) · **Scope:** six requested areas.
**Method:** line-referenced reads of auth pages/APIs, dashboard shells + sections, booking/station APIs, `app/helpers/SessionTicker.php`, `database/schema.sql`, `app/config/config.php`. Refs marked *(pre)* were verified in the earlier evidence pass; everything else was re-verified in this session's terminal output.

Finding format: **file:line → current behavior → root cause → what needs to change.**

---

## Area 1 — Authentication flows (signup, Google signup, login, reset)

### 1.1 Google signup collects no phone number — Google drivers can stay phone-less forever
- **Where:** `api/auth/google.php` new-user `INSERT INTO users (email, password, name, picture) …` has no `phone` column; `public/complete-profile.php:58-110` collects only name + (driver: car_model, battery) / (owner: company_name, bank_account); inline validation `complete-profile.php:149-171` and the server `complete_profile` handler (`api/auth/google.php` `complete_profile` action) validate exactly those fields — no phone anywhere in the flow.
- **Current behavior:** Google-registered users keep `phone = NULL`. The owner's next profile save forces a phone (owner_sections/profile.php:27 requires it non-empty), but the driver path never does (see 2.6), so Google drivers can remain phone-less indefinitely.
- **Root cause:** phone was simply never added to the Google flow, although manual signup collects and validates it thoroughly (`register.php:439/504` Nepali pattern, `auth.js:247/266/284` `phoneChecks`, server `validate_phone`).
- **Change:** add a required phone field to `complete-profile.php` (both role branches), validate client-side with the same pattern, and run `validate_phone()` in the `complete_profile` action.

### 1.2 Manual signup phone — no gap
Collected client-side (pattern attrs + `phoneChecks`) and server-side (`validate_phone`). Explicit non-finding.

### 1.3 Signup wizard vs the browser Back button
- **Where:** `register.php:657-671` `goToStep()` toggles DOM steps only; there is no `history.pushState`/`popstate` anywhere in the file; `register.php:685-690` `pageshow` handler restores only the submit-button state after a bfcache restore.
- **Current behavior:** pressing Back from step 2 leaves the page entirely; a bfcache restore lands on step 1 with only the button reset — step/progress state is not re-synced.
- **Root cause:** the two-step wizard has no history integration; step transitions are pure DOM.
- **Change (decision needed):** minimal = accept it (Back exits signup — standard form behavior); better = `pushState` per step + `popstate → goToStep()`, and re-sync the progress bar in the `pageshow` handler.

### 1.4 Login Enter-key — works; no fix needed
`login.php:218` form uses `onsubmit="handleLogin(event)"` with a real `type="submit"` button, so Enter in any field submits and `handleLogin` prevents default. Explicit non-finding.

### 1.5 Login checklist demands rules the system never enforced
- **Where:** `login.php:240-243` checklist + `paintPwRules()` (`login.php:346-362`) paint "Uppercase letter · Number" red until matched. But neither registration (`api/auth/register.php:46-49` — `PASSWORD_MIN_LENGTH` only) nor reset (`api/auth/reset-password.php:35-36` — length only) ever enforced uppercase/number.
- **Current behavior:** a user whose password never had an uppercase letter or number sees permanently red rules on login even though the password is valid — users will read this as "my password is being rejected".
- **Root cause:** checklist markup was copied from a stricter draft policy and never aligned with the actual server rules.
- **Change:** pick one — (a) trim the login checklist to length-only (matches all server checks today; ~5-line fix), or (b) enforce upper+num at registration and reset (a policy/migration change). Default recommendation: (a).

### 1.6 Reset-vs-signup validation drift
- **Working baseline:** `reset-password.php:74` checklist (length-only, green `.ok` CSS at :43-44, JS :89/:104-106 including the match check at :106) — consistent with the server (`api/auth/reset-password.php:35-36`).
- **Broken:** `register.php:529-531` — line 530 is corrupted markup: `<span id="<span id="pw-rule-len">…` (a span nested inside its own id attribute). `auth.js:30-31` looks up `#pw-rule-len`; the malformed tag breaks that lookup, so the register live checklist never updates. It also carries only ONE rule vs login's three (drift, see 1.5).
- **Show/hide toggles:** eye buttons exist on register (`register.php:523-525, 538-540`) but the reset form (`reset-password.php:70-81`) has none — inconsistent UX between two screens doing the same job.
- **Forgot page:** `forgot-password.php:50` is `novalidate` with `type="email"` → no client format check; the server validates (`forgot-password.php:43` `validate_email` + generic anti-enumeration response). Minor.
- **Change:** fix the malformed span; move to one shared checklist component (length-only per 1.5 decision); add eye toggles to the reset page.

### 1.7 The same rules are hand-copied in 3+ places (already drifting)
- Gmail-only email regex: `register.php:434/499` (pattern attrs) ≡ `auth.js:243` ≡ server `validate_gmail()` — three copies.
- Nepali phone: `register.php:439/504` ≡ `auth.js:247` ≡ `validate_phone()` — three copies.
- Password checklist markup: `login.php:240-243` / `register.php:529-531` / `reset-password.php:74` — three near-identical blocks, already divergent.
- Length checks: `api/auth/register.php:51` uses `strlen` while `api/auth/google.php:59-76` uses `mb_strlen` — multibyte names counted differently (bytes vs chars).
- Dashboard JS: `selectPreset()` (`driver.php:1024-1033` ≡ `owner.php:747-756`) and `saveProfile()` (`driver.php:1035-1061` ≡ `owner.php:789-812`) are near-duplicates (verified pre).
- **Root cause:** rules/components duplicated by hand instead of one source of truth; the drift in 1.5/1.6 is the direct consequence.
- **Change:** server helpers stay the source of truth; JS reads the rules once (config-printed data-attributes or a tiny shared rules blob); `strlen` → `mb_strlen` in the register API.

---

## Area 2 — Per-form validation matrix (JS client vs PHP server)

Legend: ✅ covered · ⚠️ partial · ❌ missing.

| # | Form (file:line) | Client JS validation | Server-side validation | Gaps |
|---|---|---|---|---|
| 1 | Manual signup (`register.php:422`) | ✅ `auth.js:262-297`: name, gmail, phone, car ≤100, battery 0.1-1000, company ≤150, bank 5-20 digits, pw ≥ min, confirm, terms | ⚠️ `api/auth/register.php:29-89`: email+gmail, pw len, name 2-100 (`strlen`), phone, bank regex | ❌ car_model ≤100, battery ≤1000, company ≤150 not server-checked; terms accepted blindly |
| 2 | Google complete-profile (`complete-profile.php:58`) | ✅ inline JS :149-171 (name 2-100+letters, car required, battery >0, company required, bank regex, terms) | ⚠️ `api/auth/google.php:59-77`: name mb_strlen 2-100, car ≤100, battery >0, company ≤150, bank regex | ❌ battery upper bound missing both sides; phone absent (1.1) |
| 3 | Login (`login.php:218`) | ⚠️ empty-only check + UX checklist (misleading, 1.5) | ✅ rate-limited, password verify, user_type, lockout | Client-only issue (1.5) |
| 4 | Forgot password (`forgot-password.php:50`) | ❌ none (novalidate kills native email check) | ✅ `validate_email` + anti-enumeration (`:43`) | Minor client gap |
| 5 | Reset password (`reset-password.php:70`) | ✅ length ≥8 (:105), match (:106) | ✅ token/expiry + min length (:35-36) | None (eye toggles only, 1.6) |
| 6 | Driver profile (`sections/profile.php:116`) | ❌ NONE — `saveProfile` (`driver.php:1035-1061`) posts raw FormData | ❌ `sections/profile.php:25-28`: name-empty only | ❌ **biggest gap**: phone not regex-checked, battery no range (`floatval` default 50), car_model no length cap |

| 7 | Owner profile (`owner_sections/profile.php:114`) | ❌ NONE (`owner.php:789-812`) | ⚠️ `owner_sections/profile.php:19-30`: company/name/phone non-empty | ❌ no phone regex, no bank-digits regex, no length caps |
| 8 | Station register (`owner_sections/stations.php:127`) | ⚠️ `submitStation` (`owner.php:527-561`): ≥1 charger only; posts name/desc/lat/lon/address/city/chargers raw | ⚠️ `api/stations.php:354-403`: name/lat/lon/address/city presence (:368-371) | ❌ client: no name/lat/lon checks; server: **no charger_type whitelist** (:391), **no wattage range** (`floatval` default 7.4, negatives accepted, :392) |
| 9 | Booking modal (`driver.php:440-564`) | ⚠️ charger select only | ✅ `api/bookings.php:83-115`: queue-cap re-check inside transaction, reserved rule (:97-105), maintenance/offline gate (:108-112) | OK |
| 10 | Review modal (`sections/bookings.php:191-203`) | ⚠️ client-side check unverified | ✅ `api/reviews.php:163-175`: rating 1-5, comment ≤1000 + non-empty; eligibility :188-200 | OK server-side |
| 11 | Support ticket (`sections/support.php:31-51`) | ✅ maxlength 150/5000 | ✅ `api/support.php:92-99`: subject ≤150, msg ≤5000 | Best-covered form in the app |

**Cross-cutting:** the Terms checkbox is client-only in both signup paths (rows 1-2) — a non-JS client can create an account without agreeing. If that matters legally, add a server check; otherwise document the shortcut.

---

## Area 3 — Profile existence and editable fields per dashboard role

- **Driver** ✅ — `sections/profile.php` (nav at `driver.php:138-139`): preset avatar, avatar upload, name, phone, email (read-only), car_model, car_full_capacity_kwh, charger_preference. Phone is optional here (:25-28) — ties into 1.1/2.6.
- **Owner** ✅ — `owner_sections/profile.php` (nav in `owner.php`): company_name, contact name, phone (required at :27), email (read-only), bank_name, bank_account_number, account_holder_name, description, preset logo, logo upload.
- **Admin** ❌ — `admin.php` nav (`admin.php:100-147`) has no Profile entry and `admin_sections/` contains no profile.php (dir listing verified). Admins cannot edit their own name or picture anywhere.
- Other roles: none exist — dashboards are driver/owner/admin only; find-stations is a driver section, not a role.

**Change / decision:** add an admin profile section, or confirm it's intentionally out of scope (see Questions).

---

## Area 4 — Image upload, preview, cache busting, old-file handling, cropping

**Upload surfaces (3 distinct UX paths):**
1. `public/profile-picture.php` (post-Google step, exists — dir verified): full experience — live preview circle via FileReader (:204-207), preset modal (:96-110), preset/file mutual exclusion (:192/:207), CSRF token (:26). File input at :86 `accept="image/*"`.
2. Driver avatar — `sections/profile.php:140` `accept="image/*"`: **no live preview, no client size check**; presets are inline select + `apply_preset`.
3. Owner logo — `owner_sections/profile.php:187` input: **no live preview, no client size check** (server path verified :42-79).

- **4.1 Preview inconsistency:** the Google-flow page previews; the two dashboard profile forms don't. **Root cause:** the dashboard forms never wired a `change` handler (grep confirms no FileReader anywhere in dashboard sections). **Change:** either reuse a tiny shared preview snippet or accept upload-blind UX; also add a client-side `MAX_UPLOAD_SIZE` check to avoid 5 MB POSTs failing server-side only.
- **4.2 URL construction:** `get_profile_picture_url($user_id, $type, $profile_pic)` (`app/config/config.php:341`) — 3 tiers: uploaded file → Google remote picture URL → default. Used at `driver.php:34-35`; owner/admin equivalents (verified pre).
- **4.3 Cache busting:** profile *sections* append `?t=time()` (`sections/profile.php:136`, `owner_sections/profile.php:183`), but the always-visible chrome does not: header avatar `driver.php:89-90` and sidebar `driver.php:113-114` render the bare URL → **stale avatar after every re-upload** until full reload/CDN expiry; Google remote tier can't be busted at all. **Change:** append the same `?t=` (or filemtime) in the shell templates, or rely on content-hash filenames.
- **4.4 Old-file handling:** fixed filename per user (`{user_id}.jpg` / `owner_{user_id}.jpg`, e.g. `owner_sections/profile.php:65`) → overwrite, no orphans by design. GD-failure fallback stores the raw original (`:71-72`) — acceptable; GIF accepted in the whitelist (`:47`) but the GD re-encode to JPEG strips animation (silent, cosmetic).
- **4.5 Cropping library:** none in the codebase (no cropper JS; GD resizes to 512px q82 with raw fallback, `owner_sections/profile.php:67-78`). No client-side crop exists on any surface. **Decision:** ship plain resize (current) or add a crop lib (new dependency — needs approval).

---

## Area 5 — Driver dashboard navigation mechanics

**Mechanism:** `loadSection(sectionName, force)` — `driver.php:171-211` (guard :173, `history.pushState` :179-181, spinner :187, fetch :195, login-form detection :200, error screen :203-210). Same pattern in `owner.php:270-332` and `admin.php:168-227`. Sections dir: `sections/{bookings,dashboard,favorites,find-stations,notifications,profile,receipts,support}.php`.

- **5.1 "My Bookings" / "Favorites" metric cards are not clickable** — `sections/dashboard.php:39-54`: the two stat cards have **no onclick / href / cursor at all**. Only wired shortcuts are "View All" → `loadSection('bookings')` (:84) and "Find Stations & Book" (:126). **Root cause:** cards were built as display-only while resembling shortcuts. **Change:** add `onclick="loadSection('bookings')"` / `loadSection('favorites')"` (+ `cursor:pointer`), and align naming — the nav calls the same target "Charging Sessions" (`driver.php:126-133`) while the card says "My Bookings".
- **5.2 No `popstate` handler anywhere** — grep across `driver.php`/`owner.php`/`admin.php` returns zero matches. After `pushState`, browser Back reverts **only the URL**; content and `currentSection` stay on the new section → URL-vs-page mismatch, and afterwards nav clicks on the stale current section silently no-op (guard :173). **Change:** `window.addEventListener('popstate', () => loadSection(sectionFromUrl(), true))` in each shell.
- **5.3 "View all" stuck-loader mechanisms (two real ones; no third found):**
  1. `driver.php:86` / `owner.php:175` / `admin.php:84` "View all notifications" → `loadSection('notifications')`; the target files exist and every loadSection has a `.catch` error screen — a 404 is handled. **But** the fetch has **no timeout/AbortController** (all three shells), so a hanging request leaves the spinner indefinitely.
  2. The guard (:173): if `currentSection` already equals the target (e.g. after 5.2's Back desync, or clicking "View all notifications" while already on Notifications), the call **silently returns** — the UI looks frozen. **Change:** add an AbortController timeout + make the guard still update nav state or force-refresh.
- **5.4 `initializeSection`** (`driver.php:213-220`) only wires find-stations and bookings; notifications/receipts/support need no init — no finding.

---

## Area 6 — Booking lifecycle & data model

**Statuses (verified):** `pending_payment`, `booked`, `charging`, `stopped`, `completed`, `cancelled` — set by `api/bookings.php` actions (`initiate_payment` :65, `confirm_payment` :186, `confirm_charging_payment` :258, `initiate_charging_payment` :355, `stop_session` :406→`'stopped'` :449-452, owner `complete_session` :519) and by `app/helpers/SessionTicker.php` (`'pending_payment','booked'` → `cancelled` :19-31; `charging` → `completed` :135-142). There are **no** `expired`/`no_show` statuses — expiry reuses `cancelled`.

- **6.1 No session extension exists.** Greps found no extend action/endpoint/UI anywhere (`api/bookings.php:62-575` action list has none), and `schema.sql` bookings table (:205-283) has no per-hour rate field to price an extension with. Auto-completion rides the piggyback ticker — `SessionTicker` runs on request via the lazy tick (`bookings.php:18`), no cron. **Change (if wanted):** needs a rate source (new column or derived from `estimated_total_cost`), an `extend_session` action with transaction + charger re-check, and UI. Currently out of scope until user confirms.
- **6.2 Expiry UX:** driver CAN cancel their own booking (`bookings.php:580-602`, status gate :584), but ticker-driven expiry-cancel (:19-31) fires silently — no notification insert near the UPDATE. **Verify during fix phase** whether drivers should be notified when a reservation is auto-cancelled.
- **6.3 `pending_payment` display-vs-cap mismatch (reported earlier; re-verified).** Booking CAP counts it (`api/bookings.php:87` `IN ('booked','pending_payment','charging')`, reserved rule :97-105) but the bookable DISPLAY ignores it (`api/stations.php:124-129` counts only `('booked','charging')`; owner list :163; ticker charger release :68-70). Result: a charger can show **Available** in station cards while the booking API rejects with "queue full / already reserved" — for up to `BOOKING_ARRIVAL_DEADLINE` (`app/config/config.php`) until the ticker cancels the unpaid row. **Change:** include `pending_payment` in the display calc (the cap side is correct).
- **6.4 Filter pills drop real rows** — `sections/bookings.php:67-72`: pills match on badge **class + exact status text**; badge mapping (:92-95) defaults everything else to `badge-info`. Therefore `pending_payment` and `stopped` rows match **no** pill except "All", and `stopped` renders with the generic info badge (cosmetically grouped with booked). Search (:77) is fine. **Change:** filter on a `data-status` attribute instead of badge text/classes; decide which group `stopped` belongs to (completed side is natural — it's billed, `payment_status='completed'` :452).
- **6.5 Dashboard consistency:** `dashboard.php:29` counts `pending_payment` as an active reservation — agrees with the cap (6.3), disagrees with station-card display (6.3). One fix resolves both.
- **6.6 Schema notes (`schema.sql:205-283`):** status/arrival_deadline/session_ends_at/estimated+actual cost/payment fields present; no extension-related columns; `updated_at ON UPDATE CURRENT_TIMESTAMP` is the terminal-state timestamp relied on by review eligibility (`api/reviews.php:188-193`) — safe today, but any new status-writing path must keep rows current.
- **6.7 Double-completion guards:** ticker guards on `status='charging'` (`SessionTicker.php:142`); `stop_session` guarded likewise — no race found between ticker and driver stop.

---

## Prioritized bug list (critical → minor)

**P0 — critical (data integrity / trust-breaking):**
1. Driver profile server validation gap — phone unvalidated, battery no range, car_model uncapped (`sections/profile.php:25-28`; client also absent, 2.6).
2. `pending_payment` display-vs-cap mismatch — UI says Available, server rejects (6.3; `api/stations.php:124-129` vs `api/bookings.php:87`).
3. Google signup: no phone collected + driver never required later (1.1; `api/auth/google.php:202/:227`, `complete-profile.php:58-110`).
4. Corrupted register password-checklist markup — live UX dead (`register.php:530`).

**P1 — major (wrong/confusing behavior):**
5. Login checklist shows unenforced rules (1.5; `login.php:240-243, 346-362`).
6. No `popstate` handler in all three shells — Back desyncs URL/page and bricks clicks (5.2).
7. "My Bookings"/"Favorites" metric cards not clickable + label mismatch (5.1; `dashboard.php:39-54`).
8. Fetch without timeout → indefinite spinner possible in all dashboards (5.3.1).
9. Filter pills hide `pending_payment`/`stopped` rows (6.4; `sections/bookings.php:67-72`).
10. Owner profile: no phone/bank format or length validation (2.7; `owner_sections/profile.php:19-30`).
11. Station create: no charger-type whitelist, no wattage range, weak client checks (2.8; `api/stations.php:390-394`, `owner.php:527-561`).

**P2 — minor (polish / hygiene):**
12. `strlen` vs `mb_strlen` inconsistency (`api/auth/register.php:51` vs `api/auth/google.php:59-76`).
13. Stale header/sidebar avatars after re-upload (4.3; `driver.php:89-90, 113-114`).
14. Reset page lacks eye toggles (1.6).
15. `stopped` badge-info cosmetic (2.x badge mapping, `sections/bookings.php:92-95`).
16. Triplicated validation rules risk future drift (1.7).
17. Terms accepted without server check (2 cross-cutting note).
18. Forgot form: no client email check (2 row 4).
19. Admin has no profile section (Area 3 — or by design; see Questions).

---

## Files likely needing changes (if/when fixes are approved)

**Auth:** `public/register.php` (checklist markup 530, toggles), `public/assets/js/auth.js` (checklist/rules source), `public/login.php` (checklist rules), `public/reset-password.php` (toggles), `public/complete-profile.php` (+phone field), `api/auth/google.php` (+phone validation), `api/auth/register.php` (mb_strlen, optional server length caps).

**Dashboard shells:** `public/dashboard/driver.php`, `owner.php`, `admin.php` (popstate, fetch timeout, avatar cache-bust, shared selectPreset/saveProfile consolidation).

**Sections:** `sections/profile.php` (server validation, preview, cache-bust), `owner_sections/profile.php` (same + phone/bank format), `sections/dashboard.php` (clickable cards), `sections/bookings.php` (pills + badge mapping), `owner_sections/stations.php` + `api/stations.php` (charger type/wattage validation).

**Config (read-only source of truth):** `app/config/config.php` (constants already in place: `BOOKING_BASE_FEE`, `ELECTRICITY_RATE_PER_KWH`, `MAX_UPLOAD_SIZE`, `PASSWORD_MIN_LENGTH`, `BOOKING_ARRIVAL_DEADLINE`).

**Possibly schema (only if extension feature approved):** `database/schema.sql` (no per-hour rate column exists — a new migration would be needed; destructive-gate rules apply).

---

## Questions / ambiguities to resolve before fixing

1. **Password policy:** relax the login checklist to length-only (recommended, matches server today), or enforce uppercase+number at registration and reset going forward?
2. **Google signup phone:** required at complete-profile (mirrors manual signup), or optional with a nudge later?
3. **Admin profile:** is the missing admin profile section intentional, or should it be added?
4. **Image cropping:** keep plain GD resize (no new dependency), or add a client-side crop library (needs approval for a new asset)?
5. **Session extension:** in scope now? It needs a pricing basis (no per-hour rate field exists — new column or derive from `estimated_total_cost`?) plus endpoint + UI.
6. **Auto-cancelled reservations:** should drivers get a notification when the ticker cancels their `pending_payment`/`booked` row at the arrival deadline (currently silent)?
7. **Back-button behavior:** restore sections via `popstate` (recommended), or is URL-only navigation acceptable with just the silent-guard fix?
8. **Terms checkbox:** enforce server-side in both signup paths, or document as client-only?

---

*End of report. Compiled from static, read-only inspection only — no project code was modified. `docs/audit-report.md` was the only file written.*










