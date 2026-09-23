// cdp_paths_check.mjs — headless-Chrome verification for the path-normalization + CSP batch.
// (a) Leaflet + marker PNGs from unpkg on the landing map, (b) driver favorites/profile
// sections under /EE/, (c) owner loadSection() dynamic sections, (d) profile-picture presets.
// Asserts: ZERO CSP-violation console errors, ZERO failed/blocked localhost requests.
// Usage: node tests/cdp_paths_check.mjs   (Node built-ins only; mirrors cdp_countdown.mjs)
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const GENERIC_MSG = 'If that email is registered, a password reset link has been sent.';
const MYSQL = 'D:\\Xampp\\mysql\\bin\\mysql.exe';
function sqlExec(sql) {
  const r = spawnSync(MYSQL, ['-u', 'root', 'ev_charging_db'], { input: sql, encoding: 'utf8' });
  if (r.status !== 0) throw new Error('mysql failed: ' + (r.stderr || r.stdout));
}
// Case-80 seeding pattern: raw token only client-side, SHA-256 stored in DB.
function seedResetToken(raw) {
  const h = createHash('sha256').update(raw).digest('hex');
  sqlExec(`INSERT INTO verification_tokens (user_id, token, token_type, expires_at) VALUES (1, '${h}', 'password_reset', DATE_ADD(NOW(), INTERVAL 30 MINUTE));`);
  return h;
}
function deleteResetTokenRow(h) {
  sqlExec(`DELETE FROM verification_tokens WHERE token = '${h}';`);
}
function getDriverPasswordHash() {
  // NOTE: the database name MUST be passed as a positional arg — omitting it
  // makes mysql print usage, stdout goes empty, and the caller restores ''.
  const r = spawnSync(MYSQL, ['-u', 'root', 'ev_charging_db', '-B', '-N', '-e', 'SELECT password FROM users WHERE id = 1'], { encoding: 'utf8' });
  if (r.status !== 0 || !(r.stdout || '').trim()) throw new Error('driver hash read failed: ' + (r.stderr || '').slice(0, 200));
  return r.stdout.trim();
}

const PORT = 9337;
const profile = mkdtempSync(join(tmpdir(), 'cdp-paths-'));
const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', '--disable-gpu', 'about:blank'
], { stdio: 'ignore' });

const wait = ms => new Promise(r => setTimeout(r, ms));
let targets = [];
for (let i = 0; i < 40; i++) {
  try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); if (targets.length) break; } catch { }
  await wait(500);
}
if (!targets.length) { console.error('NO_CDP_TARGET'); chrome.kill(); process.exit(1); }
const ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
let idc = 0; const pending = new Map();
const cspErrors = [], consoleErrors = [], badLocal = [], unpkg = [], failedLocal = [];
ws.onmessage = e => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
    const t = m.params.args.map(a => a.value ?? a.description ?? '').join(' ');
    (t.includes('Content Security Policy') ? cspErrors : consoleErrors).push(t.slice(0, 160));
  }
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
    const t = m.params.entry.text;
    (t.includes('Content Security Policy') ? cspErrors : consoleErrors).push(t.slice(0, 160));
  }
  if (m.method === 'Network.responseReceived') {
    const { url, status } = m.params.response;
    if (status >= 400 && url.includes('localhost')) badLocal.push(`${status} ${url.slice(0, 120)}`);
    if (url.includes('unpkg.com')) unpkg.push(`${status} ${url.slice(0, 120)}`);
  }
  if (m.method === 'Network.loadingFailed' && !m.params.canceled) failedLocal.push((m.params.errorText || 'failed').slice(0, 80));
};
const send = (method, params = {}) => new Promise(res => { const id = ++idc; pending.set(id, res); ws.send(JSON.stringify({ id, method, params })); });
const evl = async expr => (await send('Runtime.evaluate', { expression: expr, returnByValue: true }))?.result?.result?.value;
const evlAsync = async expr => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }))?.result?.result?.value;
const nav = async (url, ms = 3500) => { await send('Page.navigate', { url }); await wait(ms); };

const results = {};
try {
  await send('Network.enable'); await send('Page.enable'); await send('Log.enable'); await send('Runtime.enable');

  // (0) register.php as guest — GSI button renders on BOTH role tabs, the real
  // flow entry (handleGoogleRegister) binds the LIVE selectedUserType, and the
  // wrapper height never shrinks (flicker ratchet).
  await nav('http://localhost/EE/public/register.php', 4000);
  const regHeightAt = () => evl("document.getElementById('google-btn-wrapper').getBoundingClientRect().height");
  results.register = {
    path: await evl('location.pathname'),
    gsiDivPresent: await evl("!!document.querySelector('.g_id_signin')"),
    gsiIframeRendered: await evl("!!document.querySelector('.g_id_signin iframe')"),
    clientIdFromEnv: await evl("(document.getElementById('g_id_onload').getAttribute('data-client_id') || '').endsWith('.apps.googleusercontent.com')"),
    dataText: await evl("document.querySelector('.g_id_signin').getAttribute('data-text')"),
    heightT0: await regHeightAt(),
    loginLeak: await evl("!!document.getElementById('login-form')"),
  };
  await wait(1500);
  results.register.heightT1 = await regHeightAt();
  // Spy fetch, then drive the REAL flow entry (the GSI callback) under each tab
  await evl(`window.__cap = []; const __orig = window.fetch;
             window.fetch = function (u, o) { try { window.__cap.push(JSON.parse(o.body)); } catch (e) { window.__cap.push(null); }
               return __orig.apply(this, arguments); }; true;`);
  await evl("selectUserType(document.querySelector('.type-option[data-type=\"owner\"]'), 'owner'); handleGoogleRegister({ credential: 'csp-probe-token' }); true;");
  await wait(800);
  await evl("selectUserType(document.querySelector('.type-option[data-type=\"driver\"]'), 'driver'); handleGoogleRegister({ credential: 'csp-probe-token' }); true;");
  await wait(1200);
  results.register.tabOwnerBound = await evl("window.__cap.some(b => b && b.user_type === 'owner')");
  results.register.tabDriverBound = await evl("window.__cap.some(b => b && b.user_type === 'driver')");
  results.register.heightT2 = await regHeightAt();
  results.register.heightMonotonic = (results.register.heightT0 ?? 0) >= 44
    && (results.register.heightT1 ?? 0) >= (results.register.heightT0 ?? 0) - 1
    && (results.register.heightT2 ?? 0) >= (results.register.heightT1 ?? 0) - 1;

  // (0b) forgot/reset pages as guest — UI verification for the password-reset flow.
  await nav('http://localhost/EE/public/forgot-password.php', 3500);
  results.forgot_page = {
    path: await evl('location.pathname'),
    formVisible: await evl("!!document.getElementById('forgot-form') && document.getElementById('forgot-form').offsetParent !== null"),
    csrfMeta: await evl("!!document.querySelector('meta[name=\"csrf-token\"]')"),
  };
  await evl("document.getElementById('email').value = 'driver1@example.com'; document.getElementById('user-type').value = 'driver'; true;");
  await evl("document.getElementById('forgot-form').dispatchEvent(new Event('submit', { cancelable: true })); true;");
  // wait 6s: the endpoint sends a REAL Gmail SMTP mail synchronously before responding
  await wait(6000);
  results.forgot_generic_valid = await evl("document.getElementById('success-message').textContent");
  await evl("document.getElementById('email').value = 'nosuchuser-cdp@gmail.com'; document.getElementById('user-type').value = 'driver'; true;");
  await evl("document.getElementById('forgot-form').dispatchEvent(new Event('submit', { cancelable: true })); true;");
  await wait(1500);
  results.forgot_generic_unknown = await evl("document.getElementById('success-message').textContent");

  // reset-password with missing token → error card, NO password field
  await nav('http://localhost/EE/public/reset-password.php', 3000);
  results.reset_invalid = {
    path: await evl('location.pathname'),
    errorCard: await evl("document.body.textContent.includes('Link Invalid or Expired')"),
    noPasswordField: await evl("!document.getElementById('password')"),
  };

  // valid token (seeded via case-80 pattern) → password form + live checklist
  const rawTok = 'cdp-reset-' + createHash('sha256').update(String(Date.now()) + 'cdp').digest('hex').slice(0, 24);
  const seedHash = seedResetToken(rawTok);
  await nav('http://localhost/EE/public/reset-password.php?token=' + encodeURIComponent(rawTok), 3500);
  results.reset_valid = {
    path: await evl('location.pathname'),
    formPresent: await evl("!!document.getElementById('reset-form')"),
    passwordField: await evl("!!document.getElementById('password')"),
  };
  await evl("const p = document.getElementById('password'); p.value = '12345678'; p.dispatchEvent(new Event('input', { bubbles: true })); true;");
  results.reset_checklistGreen = await evl("document.getElementById('pw-rule-len').classList.contains('ok')");
  // mismatch blocked CLIENT-side: spy fetch, submit mismatch → zero reset calls
  await evl("window.__rp = 0; const __of = window.fetch; window.fetch = function (u, o) { if (String(u).includes('reset-password')) window.__rp++; return __of.apply(this, arguments); }; true;");
  await evl("document.getElementById('confirm-password').value = 'different999'; true;");
  await evl("document.getElementById('reset-form').dispatchEvent(new Event('submit', { cancelable: true })); true;");
  await wait(600);
  results.reset_mismatchMsg = await evl("document.getElementById('error-message').textContent");
  results.reset_mismatchNoFetch = await evl('window.__rp === 0');
  // capture the pre-test password hash — the reset REALLY changes driver1's
  // password, and the downstream driver-login stage needs the original back.
  const prevHash = getDriverPasswordHash();
  // matching submit → success + redirect toward login
  await evl("document.getElementById('confirm-password').value = '12345678'; true;");
  await evl("document.getElementById('reset-form').dispatchEvent(new Event('submit', { cancelable: true })); true;");
  await wait(800); // capture the success toast BEFORE the 1800ms redirect fires
  results.reset_successMsg = await evl("document.body.textContent.includes('Password updated')");
  await wait(2200);
  results.reset_redirect = await evl('location.pathname');
  // restore: original password hash + purge test token/rows (suite 80-cooldown safety)
  sqlExec("UPDATE users SET password = '" + prevHash + "' WHERE id = 1;");
  deleteResetTokenRow(seedHash);
  sqlExec("DELETE FROM verification_tokens WHERE user_id = 1 AND token_type = 'password_reset' AND is_used = FALSE;");

  // (a) landing map as guest — Leaflet + marker PNGs (unpkg) must load under CSP
  await nav('http://localhost/EE/public/index.php', 5000);
  results.map_leafletLoaded = await evl("typeof L !== 'undefined'");
  results.map_markerImgs = await evl("[...document.querySelectorAll('img.leaflet-marker-icon')].length");
  results.map_mapHasTiles = await evl("document.querySelectorAll('.leaflet-tile').length");

  // (b) driver login → favorites + profile sections
  await nav('http://localhost/EE/public/login.php', 3500);
  await evl(`document.getElementById('email').value = 'driver1@example.com';
             document.getElementById('password').value = 'Test@123';
             document.getElementById('user-type').value = 'driver';
             document.getElementById('login-btn').click(); true;`);
  await wait(4500);
  results.driver_login_path = await evl('location.pathname');
  await nav('http://localhost/EE/public/dashboard/driver.php?page=favorites', 3500);
  results.driver_favorites = {
    path: await evl('location.pathname'),
    hasFavoritesHeading: await evl("document.body.textContent.includes('My Favorite Stations') || document.body.textContent.includes('No Favorites Added Yet')"),
    loginLeak: await evl("!!document.getElementById('login-form')"),
  };
  await nav('http://localhost/EE/public/dashboard/driver.php?page=profile', 3500);
  results.driver_profile = {
    path: await evl('location.pathname'),
    loginLeak: await evl("!!document.getElementById('login-form')"),
    imgsTotal: await evl('document.images.length'),
    imgsBroken: await evl('[...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.src && !i.src.startsWith("data:")).length'),
  };

  // (d) profile-picture.php — preset thumbs + preview URLs (as logged-in driver)
  await nav('http://localhost/EE/public/profile-picture.php', 3500);
  results.profile_picture = {
    path: await evl('location.pathname'),
    presetImgs: await evl("document.querySelectorAll('.modal-presets img').length"),
    presetSrcsSample: await evl("[...document.querySelectorAll('.modal-presets img')].slice(0, 3).map(i => i.getAttribute('src')).join(' | ')"),
    presetImgsBroken: await evl("[...document.querySelectorAll('.modal-presets img')].filter(i => i.complete && i.naturalWidth === 0).length"),
  };

  // marker CSP proof: load the exact unpkg marker PNG the way Leaflet would
  results.markerIconDirectProbe = await evlAsync(
    "new Promise(res => { const i = new Image(); i.onload = () => res('LOADED ' + i.naturalWidth + 'x' + i.naturalHeight); i.onerror = () => res('BLOCKED'); i.src = 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png'; setTimeout(() => res('TIMEOUT'), 6000); })"
  );

  // find-stations map uses Leaflet DEFAULT markers → unpkg img-src proof in the real UI
  await nav('http://localhost/EE/public/dashboard/driver.php?page=find-stations', 4500);
  results.driver_find_stations = {
    path: await evl('location.pathname'),
    markerImgs: await evl("[...document.querySelectorAll('img.leaflet-marker-icon')].length"),
    markerIconsLoaded: await evl("[...document.querySelectorAll('img.leaflet-marker-icon')].length > 0 && [...document.querySelectorAll('img.leaflet-marker-icon')].every(i => i.complete && i.naturalWidth > 0)"),
    loginLeak: await evl("!!document.getElementById('login-form')"),
  };
  await wait(1500); // let auto-geolocation resolve its deny/timeout path (headless default)
  results.driver_find_stations.listVisibleOnLoad = await evl("(() => { const el = document.getElementById('stations-section'); return !!el && el.offsetParent !== null; })()");
  results.driver_find_stations.cardsPopulated = await evl("document.querySelectorAll('.station-card').length");
  results.driver_find_stations.distancesShowDashPreLocation = await evl("[...document.querySelectorAll('.station-distance')].every(s => s.textContent.trim() === '—')");
  results.driver_find_stations.statusLine = await evl("document.getElementById('station-status')?.textContent || ''");
  // simulate resolved geolocation → distances populate + distance sort, list stays visible
  await send('Browser.grantPermissions', { permissions: ['geolocation'], origin: 'http://localhost' });
  await send('Emulation.setGeolocationOverride', { latitude: 27.7172, longitude: 85.3240, accuracy: 50 });
  await evl("typeof autoDetectLocation === 'function' && autoDetectLocation(); true;");
  await wait(1800);
  results.driver_find_stations.distancesPopulated = await evl("[...document.querySelectorAll('.station-distance')].filter(s => s.textContent.trim() !== '—' && !isNaN(parseFloat(s.textContent))).length");
  results.driver_find_stations.sortedByDistance = await evl("(() => { const d = [...document.querySelectorAll('.station-card')].map(c => parseFloat(c.dataset.distance)); for (let i = 1; i < d.length; i++) if (d[i] < d[i-1] - 0.05) return false; return true; })()");
  results.driver_find_stations.listStillVisible = await evl("(() => { const el = document.getElementById('stations-section'); return !!el && el.offsetParent !== null; })()");

  // (c) owner login (fresh cookies) → loadSection() dynamic sections
  await send('Network.clearBrowserCookies');
  await nav('http://localhost/EE/public/login.php', 3500);
  await evl(`document.getElementById('email').value = 'owner1@example.com';
             document.getElementById('password').value = 'Test@123';
             document.getElementById('user-type').value = 'owner';
             document.getElementById('login-btn').click(); true;`);
  await wait(4500);
  results.owner_login_path = await evl('location.pathname');
  await nav('http://localhost/EE/public/dashboard/owner.php?page=overview', 3500);
  await evl("typeof loadSection === 'function' && loadSection('profile'); true;");
  await wait(3000);
  results.owner_loadSection_profile = {
    sectionVisible: await evl("!!document.getElementById('owner-profile-form') || document.body.textContent.length > 3000"),
    loginLeak: await evl("!!document.getElementById('login-form')"),
  };
  await evl("typeof loadSection === 'function' && loadSection('stations'); true;");
  await wait(3000);
  results.owner_loadSection_stations = {
    sectionVisible: await evl("document.body.textContent.includes('Stations') || document.body.textContent.length > 3000"),
    loginLeak: await evl("!!document.getElementById('login-form')"),
  };

  results.SUMMARY = {
    registerGsiOk: results.register.gsiDivPresent === true && results.register.gsiIframeRendered === true,
    registerTabBindingOk: results.register.tabOwnerBound === true && results.register.tabDriverBound === true,
    registerNoFlicker: results.register.heightMonotonic === true,
    forgotPageOk: results.forgot_page.formVisible === true && results.forgot_page.csrfMeta === true,
    forgotGenericUIMatches: results.forgot_generic_valid === GENERIC_MSG && results.forgot_generic_unknown === GENERIC_MSG,
    resetInvalidNoForm: results.reset_invalid.errorCard === true && results.reset_invalid.noPasswordField === true,
    resetFormAndChecklistOk: results.reset_valid.formPresent === true && results.reset_checklistGreen === true,
    resetMismatchBlocked: results.reset_mismatchNoFetch === true,
    resetSuccessRedirect: results.reset_successMsg === true && (results.reset_redirect ?? '').includes('/login.php'),
    mapOk: results.map_leafletLoaded === true,
    markersVisible: (results.map_markerImgs ?? 0) > 0,
    driverFavoritesOk: results.driver_favorites.hasFavoritesHeading === true && results.driver_favorites.loginLeak === false,
    driverProfileOk: results.driver_profile.loginLeak === false && (results.driver_profile.imgsBroken ?? 0) === 0,
    ownerSectionsOk: results.owner_loadSection_profile.loginLeak === false && results.owner_loadSection_stations.loginLeak === false,
    profilePictureOk: (results.profile_picture.presetImgs ?? 0) > 0 && (results.profile_picture.presetImgsBroken ?? 0) === 0,
    findStationsMarkers: (results.driver_find_stations.markerImgs ?? 0) > 0,
    findStationsMarkerIconsLoaded: results.driver_find_stations.markerIconsLoaded === true,
    findStationsListVisibleOnLoad: results.driver_find_stations.listVisibleOnLoad === true && (results.driver_find_stations.cardsPopulated ?? 0) > 0,
    findStationsDashPreLocation: results.driver_find_stations.distancesShowDashPreLocation === true,
    findStationsDistancesPopulated: (results.driver_find_stations.distancesPopulated ?? 0) > 0,
    findStationsSortedByDistance: results.driver_find_stations.sortedByDistance === true && results.driver_find_stations.listStillVisible === true,
    markerIconDirectProbe: results.markerIconDirectProbe,
    cspViolationCount: cspErrors.length,
    cspErrors: cspErrors.slice(0, 5),
    otherConsoleErrors: consoleErrors.slice(0, 5),
    badLocalResponses: badLocal.slice(0, 10),
    failedRequests: failedLocal.slice(0, 10),
    unpkgRequests: unpkg.slice(0, 8),
  };
  console.log('RESULT ' + JSON.stringify(results.SUMMARY, null, 1));
  console.log('DETAIL ' + JSON.stringify(results));
} finally {
  try { ws.close(); } catch { }
  chrome.kill();
  try { rmSync(profile, { recursive: true, force: true }); } catch { }
}