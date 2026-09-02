<?php // ponytail: standalone post-registration picture step — guided single-focus
      // flow. Blank-state placeholder -> preset modal OR browse -> preview ->
      // save/skip. Backend endpoint unchanged.
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

if (!Auth::isSessionValid() || !in_array(Auth::getCurrentUserType(), ['driver', 'owner'], true)) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$role = Auth::getCurrentUserType();
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
/* Self-contained design tokens — match the app's language without loading dashboard.css */
:root{
  --primary:#16a34a;--primary-foreground:#fff;--card:#fff;--foreground:#111827;
  --muted-foreground:#6b7280;--border:#e5e7eb;--radius:8px;--input:#f3f4f6;
}
*{box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif}
body{margin:0;background:#f9fafb;color:var(--foreground);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:var(--card);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.08);max-width:460px;width:100%;padding:32px;text-align:center}
h1{font-size:22px;margin:0 0 6px}.sub{color:var(--muted-foreground);font-size:14px;margin-bottom:24px}
.pfp-preview{margin-bottom:20px}
.pfp-circle{width:120px;height:120px;border-radius:50%;margin:0 auto;background:var(--input);border:3px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden}
.pfp-circle svg{width:48px;height:48px;color:var(--muted-foreground)}
.pfp-circle img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.pfp-actions{display:flex;gap:10px;margin-bottom:20px}
.pfp-actions button{flex:1;padding:11px;font-size:14px;font-weight:600;border-radius:var(--radius);cursor:pointer;border:0}
#btn-presets{background:var(--primary);color:var(--primary-foreground)}
#btn-browse{background:var(--input);color:var(--foreground);border:1px solid var(--border)}
#pfp-input{display:none}
.modal-overlay{position:fixed;inset:0;background:rgba(9,9,11,.5);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;transition:opacity .15s ease;pointer-events:none}
.modal-overlay.show{opacity:1;pointer-events:auto}
.modal-box{background:var(--card);border-radius:var(--radius);padding:24px;max-width:380px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.15);text-align:center;position:relative;transform:scale(.95);opacity:0;transition:all .15s ease}
.modal-overlay.show .modal-box{transform:scale(1);opacity:1}
.modal-close{position:absolute;top:10px;right:14px;background:none;border:0;font-size:22px;cursor:pointer;color:var(--muted-foreground);line-height:1;padding:4px}
.modal-box h2{font-size:17px;margin:0 0 16px}
.modal-presets{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-bottom:18px}
.modal-presets img{width:48px;height:48px;border-radius:50%;cursor:pointer;border:3px solid transparent;object-fit:cover}
.modal-presets img.selected{border-color:var(--primary)}
#modal-select{width:100%;padding:11px;font-size:15px;font-weight:600;border-radius:var(--radius);border:0;background:var(--primary);color:var(--primary-foreground);cursor:pointer}
#modal-select:disabled{opacity:.5;cursor:default}
.row{display:flex;gap:10px;margin-top:8px}
.row button{flex:1;padding:11px;font-size:15px;font-weight:600;border-radius:var(--radius);cursor:pointer;border:0;background:var(--primary);color:var(--primary-foreground)}
.row button:disabled{opacity:.6;cursor:default}
.skip{flex:1;display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#374151;border:0;border-radius:8px;padding:11px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;opacity:0;transition:opacity .2s;z-index:99999;max-width:90%}
.toast.error{background:#dc2626}
.toast.show{opacity:.97}
</style>
</head>
<body>
<div class="card">
  <h1>Set your profile picture</h1>
  <p class="sub">Optional &mdash; pick a preset avatar<?php echo ($role === 'owner') ? ' or company logo' : ''; ?>, or upload your own image.</p>

  <!-- BLANK STATE: large circular placeholder -->
  <div class="pfp-preview">
    <div class="pfp-circle" id="pfp-circle">
      <svg id="pfp-placeholder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <circle cx="12" cy="8" r="4"/>
        <path d="M4 20c0-4 4-6 8-6s8 2 8 6"/>
      </svg>
    </div>
  </div>

  <!-- Two entry actions (always available for re-selection) -->
  <div class="pfp-actions">
    <button type="button" id="btn-presets">Choose from presets</button>
    <button type="button" id="btn-browse">Browse</button>
    <input type="file" id="pfp-input" name="pfp" accept="image/*">
  </div>

  <!-- Commit row -->
  <div class="row">
    <a class="skip" href="<?php echo $dashboard; ?>">Skip for now</a>
    <button type="button" id="submit-btn">Save picture</button>
  </div>
</div>

<!-- PRESET MODAL (hidden until opened) -->
<div class="modal-overlay" id="preset-modal" role="dialog" aria-modal="true" aria-label="Choose an avatar">
  <div class="modal-box">
    <button type="button" class="modal-close" id="modal-close" aria-label="Close">&times;</button>
    <h2>Choose an avatar</h2>
    <div class="modal-presets">
    <?php foreach ($presetList as $pk): ?>
      <img src="<?php echo APP_URL; ?>/public/assets/img/presets/<?php echo $pk; ?>.jpg"
           alt="<?php echo $pk; ?>" data-preset="<?php echo $pk; ?>" tabindex="0" role="button">
    <?php endforeach; ?>
    </div>
    <button type="button" id="modal-select" disabled>Select</button>
  </div>
</div>

<script src="<?php echo APP_URL; ?>/public/assets/js/csrf.js"></script>
<script>
(function () {
  var dashboard = '<?php echo $dashboard; ?>';
  var modal = document.getElementById('preset-modal');
  var modalPresets = document.querySelector('.modal-presets');
  var modalSelect = document.getElementById('modal-select');
  var modalCloseBtn = document.getElementById('modal-close');
  var pfpCircle = document.getElementById('pfp-circle');
  var placeholder = document.getElementById('pfp-placeholder');
  var fileInput = document.getElementById('pfp-input');
  var submitBtn = document.getElementById('submit-btn');
  var previewImg = null;       // currently shown <img> in the circle (if any)
  var chosenPreset = null;      // selected preset key (null if a file was chosen instead)

  // --- Toast ---
  var toastEl = null;
  function showToast(msg, type) {
    if (!toastEl) { toastEl = document.createElement('div'); document.body.appendChild(toastEl); }
    toastEl.className = 'toast show ' + (type || 'error');
    toastEl.textContent = msg;
    clearTimeout(showToast._h);
    showToast._h = setTimeout(function () { toastEl.classList.remove('show'); }, 3500);
  }

  // --- Preview rendering ---
  function clearPreview() {
    if (previewImg) { pfpCircle.removeChild(previewImg); previewImg = null; }
    if (placeholder.parentNode !== pfpCircle) pfpCircle.appendChild(placeholder);
  }
  function showPreview(src) {
    if (placeholder.parentNode === pfpCircle) pfpCircle.removeChild(placeholder);
    if (!previewImg) { previewImg = document.createElement('img'); pfpCircle.appendChild(previewImg); }
    previewImg.src = src;
  }

  // --- Modal ---
  function openModal() {
    chosenPreset = null;
    modalSelect.disabled = true;
    var imgs = modalPresets.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) imgs[i].classList.remove('selected');
    modal.classList.add('show');
    document.addEventListener('keydown', onEsc);
  }
  function closeModal() {
    modal.classList.remove('show');
    document.removeEventListener('keydown', onEsc);
  }
  function onEsc(e) { if (e.key === 'Escape') closeModal(); }

  document.getElementById('btn-presets').addEventListener('click', openModal);
  modalCloseBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  // Highlight on click; Enter/Space on a focused preset also highlights
  modalPresets.addEventListener('click', function (e) {
    var img = e.target.closest('img');
    if (!img) return;
    selectPresetImg(img);
  });
  modalPresets.addEventListener('keydown', function (e) {
    var img = e.target.closest('img');
    if (!img || (e.key !== 'Enter' && e.key !== ' ')) return;
    e.preventDefault();
    selectPresetImg(img);
  });
  function selectPresetImg(img) {
    var imgs = modalPresets.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) imgs[i].classList.remove('selected');
    img.classList.add('selected');
    chosenPreset = img.getAttribute('data-preset');
    modalSelect.disabled = false;
  }

  // Select applies the highlighted preset to the preview and closes.
  // Closing via X does NOT clear a prior choice — whatever was already shown
  // in the circle stays untouched.
  modalSelect.addEventListener('click', function () {
    if (!chosenPreset) return;
    showPreview('<?php echo APP_URL; ?>/public/assets/img/presets/' + chosenPreset + '.jpg');
    fileInput.value = null; // preset choice overrides any file
    closeModal();
  });

  // --- Browse ---
  document.getElementById('btn-browse').addEventListener('click', function () {
    fileInput.click();
  });
  fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { showToast('Image must be 5MB or smaller.', 'error'); fileInput.value = ''; return; }
    var reader = new FileReader();
    reader.onload = function (ev) {
      showPreview(ev.target.result);
      chosenPreset = null; // file choice overrides any preset
    };
    reader.readAsDataURL(file);
  });

  // --- Save ---
  submitBtn.addEventListener('click', function () {
    var hasFile = fileInput.files && fileInput.files[0];
    var hasPreset = chosenPreset;
    if (!hasFile && !hasPreset) {
      // Blank == skip: behave exactly like "Skip for now"
      window.location.href = dashboard;
      return;
    }
    submitBtn.disabled = true; submitBtn.textContent = 'Saving…';
    try {
      var fd = new FormData();
      if (hasFile) fd.append('pfp', fileInput.files[0]);
      if (hasPreset) fd.append('preset', hasPreset);
      fetch('/EE/api/auth/profile-picture.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.status === 'success') { window.location.href = data.redirect; return; }
          submitBtn.disabled = false; submitBtn.textContent = 'Save picture';
          showToast(data.message || 'Something went wrong.', 'error');
        })
        .catch(function () {
          submitBtn.disabled = false; submitBtn.textContent = 'Try again';
          showToast('Network error - please try again.', 'error');
        });
    } catch (err) {
      submitBtn.disabled = false; submitBtn.textContent = 'Try again';
      showToast('Network error - please try again.', 'error');
    }
  });
})();
</script>
</body>
</html>