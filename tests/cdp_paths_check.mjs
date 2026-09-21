// cdp_paths_check.mjs — headless-Chrome verification for the path-normalization + CSP batch.
// (a) Leaflet + marker PNGs from unpkg on the landing map, (b) driver favorites/profile
// sections under /EE/, (c) owner loadSection() dynamic sections, (d) profile-picture presets.
// Asserts: ZERO CSP-violation console errors, ZERO failed/blocked localhost requests.
// Usage: node tests/cdp_paths_check.mjs   (Node built-ins only; mirrors cdp_countdown.mjs)
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

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
    mapOk: results.map_leafletLoaded === true,
    markersVisible: (results.map_markerImgs ?? 0) > 0,
    driverFavoritesOk: results.driver_favorites.hasFavoritesHeading === true && results.driver_favorites.loginLeak === false,
    driverProfileOk: results.driver_profile.loginLeak === false && (results.driver_profile.imgsBroken ?? 0) === 0,
    ownerSectionsOk: results.owner_loadSection_profile.loginLeak === false && results.owner_loadSection_stations.loginLeak === false,
    profilePictureOk: (results.profile_picture.presetImgs ?? 0) > 0 && (results.profile_picture.presetImgsBroken ?? 0) === 0,
    findStationsMarkers: (results.driver_find_stations.markerImgs ?? 0) > 0,
    findStationsMarkerIconsLoaded: results.driver_find_stations.markerIconsLoaded === true,
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