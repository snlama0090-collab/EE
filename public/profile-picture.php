<?php // ponytail: standalone post-registration picture step, sibling of
      // complete-profile.php - same standalone-card pattern, self-contained JS.
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

// Session-gated, driver/owner only.
if (!Auth::isSessionValid() || !in_array(Auth::getCurrentUserType(), ['driver', 'owner'], true)) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$role = Auth::getCurrentUserType();
// Provisional Google accounts must finish complete-profile.php FIRST - otherwise
// this page's Skip would hand them a dashboard the completion gate still blocks.
if (isset($_SESSION['profile_complete']) && $_SESSION['profile_complete'] === false) {
    header('Location: ' . APP_URL . '/public/complete-profile.php');
    exit;
}

$dashboard = APP_URL . '/public/dashboard/' . $role . '.php';
$presetList = preset_keys();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars(Csrf::token(), ENT_QUOTES); ?>">
<title>Set your profile picture - EV Charge Nepal</title>
<style>
*{box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif}
body{margin:0;background:#f9fafb;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.08);max-width:460px;width:100%;padding:32px}
h1{font-size:22px;margin:0 0 6px}.sub{color:#6b7280;font-size:14px;margin-bottom:22px}
.preset-picker{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.preset-picker img{width:48px;height:48px;border-radius:50%;cursor:pointer;border:3px solid transparent;object-fit:cover}
.file-row{display:flex;align-items:center;gap:8px;margin-bottom:20px}
.file-row label{margin:0;font-weight:normal;color:#6b7280;font-size:13px}
.row{display:flex;gap:10px;margin-top:22px}
button{flex:1;background:#16a34a;color:#fff;border:0;border-radius:8px;padding:12px;font-size:15px;font-weight:600;cursor:pointer}
button:disabled{opacity:.6;cursor:default}
.skip{flex:1;display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#374151;border:0;border-radius:8px;padding:12px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;opacity:0;transition:opacity .2s;z-index:99;max-width:90%}
.toast.error{background:#dc2626}
.toast.show{opacity:.97}
</style>
</head>
<body>
<div class="card">
  <h1>Set your profile picture</h1>
  <p class="sub">Optional — pick a preset avatar<?php echo ($role === 'owner') ? ' or company logo' : ''; ?>, or upload your own image.</p>

  <form id="picture-form">
<?php if ($presetList): ?>
    <div class="preset-picker">
    <?php foreach ($presetList as $pk): ?>
      <img src="<?php echo APP_URL; ?>/public/assets/img/presets/<?php echo $pk; ?>.jpg" alt="<?php echo $pk; ?>"
           onclick="selectPreset('<?php echo $pk; ?>', this)">
    <?php endforeach; ?>
    </div>
    <input type="hidden" id="preset-input" name="preset" value="">
<?php endif; ?>

    <div class="file-row">
      <input type="file" id="pfp-input" name="pfp" accept="image/*">
      <label for="pfp-input">PNG, JPG or GIF, up to 5MB — resized server-side</label>
    </div>

    <div class="row">
      <a class="skip" href="<?php echo $dashboard; ?>">Skip for now</a>
      <button type="submit" id="submit-btn">Save picture</button>
    </div>
  </form>
</div>

<script src="<?php echo APP_URL; ?>/public/assets/js/csrf.js"></script>
<script>
    // Same picker contract as the signup forms: click = select, click again = deselect.
    function selectPreset(key, el) {
        var inp = document.getElementById('preset-input');
        if (!inp) return;
        if (inp.value === key) { inp.value = ''; el.style.borderColor = 'transparent'; return; }
        inp.value = key;
        var imgs = document.querySelectorAll('.preset-picker img');
        for (var i = 0; i < imgs.length; i++) imgs[i].style.borderColor = 'transparent';
        el.style.borderColor = 'var(--primary)';
    }

(function () {
  var toastEl = null;
  function showToast(msg, type) {
    if (!toastEl) { toastEl = document.createElement('div'); document.body.appendChild(toastEl); }
    toastEl.className = 'toast show ' + (type || 'error');
    toastEl.textContent = msg;
    clearTimeout(showToast._h);
    showToast._h = setTimeout(function () { toastEl.classList.remove('show'); }, 3500);
  }

  document.getElementById('picture-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    var fi = document.getElementById('pfp-input');
    var pe = document.getElementById('preset-input');
    var hasFile = fi && fi.files && fi.files[0];
    var hasPreset = pe && pe.value;
    if (!hasFile && !hasPreset) { showToast('Pick a preset or upload an image first.'); return; }

    var btn = document.getElementById('submit-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      // csrf.js auto-injects X-CSRF-Token; body-type agnostic (multipart OK).
      var fd = new FormData();
      if (hasFile) fd.append('pfp', fi.files[0]);
      if (hasPreset) fd.append('preset', pe.value);
      var res = await fetch('/EE/api/auth/profile-picture.php', { method: 'POST', body: fd });
      var data = await res.json();
      if (data.status === 'success') { window.location.href = data.redirect; return; }
      btn.disabled = false; btn.textContent = 'Save picture';
      showToast(data.message || 'Something went wrong.', 'error');
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Try again';
      showToast('Network error - please try again.', 'error');
    }
  });
})();
</script>
</body>
</html>