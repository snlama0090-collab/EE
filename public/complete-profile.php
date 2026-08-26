<?php // ponytail: standalone completion step; validation kept self-contained because
      // auth.js's engine assumes the auth pages' element layout - mirroring its exact
      // messages here is safer than refactoring shared JS inside feature scope.
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

// Matrix rows 5/6: only provisional driver/owner sessions belong here.
if (!Auth::isSessionValid() || !in_array(Auth::getCurrentUserType(), ['driver', 'owner'], true)) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$role = Auth::getCurrentUserType();
if (!isset($_SESSION['profile_complete']) || $_SESSION['profile_complete'] !== false) {
    header('Location: ' . APP_URL . '/dashboard/' . $role . '.php');
    exit;
}

$db = getDB();
$table = ($role === 'driver') ? 'users' : 'owners';
$stmt = $db->prepare("SELECT email, name FROM $table WHERE id = ?");
$stmt->execute([Auth::getCurrentUserId()]);
$acct = $stmt->fetch();
if (!$acct) { header('Location: ' . APP_URL . '/logout.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars(Csrf::token(), ENT_QUOTES); ?>">
<title>Complete your profile - EV Charge Nepal</title>
<style>
*{box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif}
body{margin:0;background:#f9fafb;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.08);max-width:460px;width:100%;padding:32px}
h1{font-size:22px;margin:0 0 6px}.sub{color:#6b7280;font-size:14px;margin-bottom:22px}
.chip{display:inline-block;background:#dcfce7;color:#166534;border-radius:999px;padding:4px 12px;font-size:13px;margin-bottom:18px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 5px}
input[type=text],input[type=number],select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
input:focus,select:focus{outline:2px solid #22c55e33;border-color:#22c55e}
.field-error{color:#e5484d;font-size:12px;margin-top:4px;display:none}
.is-invalid{border-color:#e5484d !important}
.terms{display:flex;gap:8px;align-items:flex-start;margin-top:20px;font-size:13px}
button{width:100%;margin-top:22px;background:#16a34a;color:#fff;border:0;border-radius:8px;padding:12px;font-size:15px;font-weight:600;cursor:pointer}
button:disabled{opacity:.6;cursor:default}
</style>
</head>
<body>
<div class="card">
  <h1>Almost there!</h1>
  <p class="sub">Your Google sign-up is verified — just finish your <?php echo $role; ?> profile.</p>
  <div class="chip">✓ <?php echo htmlspecialchars($acct['email'], ENT_QUOTES); ?></div>

  <form id="complete-form" novalidate>
    <label for="name">Full name</label>
    <input type="text" id="name" value="<?php echo htmlspecialchars($acct['name'], ENT_QUOTES); ?>">
    <div class="field-error" id="err-name"></div>

<?php if ($role === 'driver'): ?>
    <label for="car_model">Car model</label>
    <input type="text" id="car_model" placeholder="e.g., Tata Nexon EV">
    <div class="field-error" id="err-car_model"></div>

    <label for="battery_capacity">Battery capacity (kWh)</label>
    <select id="battery_capacity">
      <option value="">Select capacity…</option>
      <option value="30">30 kWh</option><option value="40">40 kWh</option>
      <option value="50">50 kWh</option><option value="60">60 kWh</option>
      <option value="75">75 kWh</option><option value="other">Other…</option>
    </select>
    <input type="number" id="battery_other" step="0.1" min="0.1" placeholder="Enter exact kWh" style="display:none;margin-top:8px">
    <div class="field-error" id="err-battery_capacity"></div>
<?php else: ?>
    <label for="company_name">Company name</label>
    <input type="text" id="company_name" placeholder="Your charging business name">
    <div class="field-error" id="err-company_name"></div>

    <label for="bank_account">Bank account number (digits only)</label>
    <input type="text" id="bank_account" inputmode="numeric" placeholder="For future payouts - nothing charged today">
    <div class="field-error" id="err-bank_account"></div>
<?php endif; ?>

    <div class="terms">
      <input type="checkbox" id="terms">
      <span>I agree to the <a href="../docs/terms.php" target="_blank">Terms &amp; Conditions</a>
      and <a href="../docs/privacy.php" target="_blank">Privacy Policy</a></span>
    </div>
    <div class="field-error" id="err-terms"></div>

    <button type="submit" id="submit-btn"><?php echo ucfirst($role); ?> profile — finish setup</button>
  </form>
</div>

<script src="<?php echo APP_URL; ?>/public/assets/js/csrf.js"></script>
<script>
(function () {
  // Client layer is UX-only; api/auth/google.php re-checks everything server-side.
  var hasLetters = function (v) { return (v.match(/[A-Za-z\u00C0-\u024F]/g) || []).length >= 2; };
  var BANK_RE = /^[0-9]{5,20}$/;

  var sel = document.getElementById('battery_capacity');
  if (sel) sel.addEventListener('change', function () {
    document.getElementById('battery_other').style.display = (sel.value === 'other') ? 'block' : 'none';
  });

  function setErr(id, msg) {
    document.getElementById(id).classList.toggle('is-invalid', !!msg);
    var box = document.getElementById('err-' + id);
    box.textContent = msg || '';
    box.style.display = msg ? 'block' : 'none';
  }

  document.getElementById('complete-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    var val = function (id) { return document.getElementById(id).value.trim(); };
    var ok = true;
    function chk(cond, id, msg) { setErr(id, cond ? '' : msg); if (!cond) ok = false; }

    var name = val('name');
    chk(name.length >= 2 && name.length <= 100 && hasLetters(name),
        'name', 'Please enter your real name (2-100 characters)');
<?php if ($role === 'driver'): ?>
    chk(val('car_model') !== '', 'car_model', 'Car model is required');
    var bat = (sel.value === 'other') ? parseFloat(val('battery_other')) : parseFloat(sel.value);
    chk(!isNaN(bat) && bat > 0, 'battery_capacity', 'Battery capacity must be a positive number');
    var payload = { action: 'complete_profile', name: name,
                    car_model: val('car_model'), battery_capacity: bat };
<?php else: ?>
    chk(val('company_name') !== '', 'company_name', 'Company name is required');
    chk(BANK_RE.test(val('bank_account')), 'bank_account', 'Bank account must be 5-20 digits');
    var payload = { action: 'complete_profile', name: name,
                    company_name: val('company_name'), bank_account: val('bank_account') };
<?php endif; ?>
    chk(document.getElementById('terms').checked, 'terms', 'Please accept the Terms & Conditions');
    if (!ok) return;

    var btn = document.getElementById('submit-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      // csrf.js wrapper auto-injects X-CSRF-Token on same-origin POSTs.
      var res = await fetch('<?php echo APP_URL; ?>/api/auth/google.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      var data = await res.json();
      if (data.status === 'success') { window.location.href = data.redirect; return; }
      btn.disabled = false; btn.textContent = 'Try again';
      alert(data.message || 'Something went wrong.');
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Try again';
      alert('Network error - please try again.');
    }
  });
})();
</script>
</body>
</html>
