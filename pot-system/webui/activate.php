<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$success = '';

// Generate device ID from hostname + first MAC
function getDeviceId() {
    $hostname = trim(shell_exec('hostname') ?? 'unknown');
    $mac = trim(shell_exec("ip link show | grep 'link/ether' | head -1 | awk '{print $2}'") ?? '00:00:00:00:00:00');
    return hash('sha256', $hostname . $mac);
}

$deviceId = getDeviceId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $rawKey = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $_POST['key'] ?? ''));

        if (strlen($rawKey) !== 15) {
            $error = 'Invalid key format. Must be 15 alphanumeric characters (XXXXX-XXXXX-XXXXX).';
        } else {
            $formattedKey = substr($rawKey,0,5).'-'.substr($rawKey,5,5).'-'.substr($rawKey,10,5);

            // Check key in DB
            $stmt = $db->prepare("SELECT id, type, status, device_id FROM license_keys WHERE key = ?");
            $stmt->execute([$formattedKey]);
            $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$keyRow) {
                $error = 'Activation key not found. Please check and try again.';
            } elseif ($keyRow['status'] === 'revoked') {
                $error = 'This key has been revoked and cannot be used.';
            } elseif ($keyRow['status'] === 'used' && $keyRow['device_id'] !== $deviceId) {
                $error = 'This key is already activated on another device. Each key is for 1 device only.';
            } else {
                // Activate
                $expiry = $keyRow['type'] === 'lifetime' ? '2099-12-31' :
                          ($keyRow['type'] === 'trial' ? date('Y-m-d', strtotime('+7 days')) :
                           date('Y-m-d', strtotime('+1 year')));

                $db->prepare("UPDATE license SET type=?, status='licensed', expires_at=? WHERE id=1")
                   ->execute([$keyRow['type'], $expiry]);

                $db->prepare("UPDATE license_keys SET status='used', device_id=? WHERE id=?")
                   ->execute([$deviceId, $keyRow['id']]);

                $success = 'License activated successfully! Redirecting...';
                header('Refresh: 2; url=/dashboard.php');
            }
        }
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Get trial days left
$lic = $db->query("SELECT expires_at FROM license WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$daysLeft = 0;
if ($lic) {
    $exp = new DateTime($lic['expires_at']);
    $now = new DateTime();
    $daysLeft = max(0, (int)$now->diff($exp)->days * ($exp > $now ? 1 : -1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POT System — Activate License</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; display:flex; align-items:center; justify-content:center; }
.container { width:100%; max-width:460px; padding:20px; }
.logo-wrap { text-align:center; margin-bottom:24px; }
.logo-icon { width:56px; height:56px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:20px; font-weight:900; color:#fff; margin-bottom:10px; }
.logo-title { font-size:20px; font-weight:700; color:#e6edf3; }
.card { background:#161b22; border:1px solid #f85149; border-radius:14px; padding:28px; }
.card-icon { font-size:40px; text-align:center; margin-bottom:12px; }
.card-title { font-size:18px; font-weight:700; color:#f85149; text-align:center; margin-bottom:6px; }
.card-sub { font-size:13px; color:#8b949e; text-align:center; margin-bottom:20px; line-height:1.6; }
.device-box { background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:10px 13px; margin-bottom:16px; font-size:11px; }
.device-box span { color:#8b949e; }
.device-box code { color:#58a6ff; font-family:monospace; font-size:11px; word-break:break-all; }
.form-group { margin-bottom:14px; }
label { display:block; font-size:12px; color:#8b949e; margin-bottom:5px; font-weight:500; }
.key-input { width:100%; background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:12px 14px; color:#e6edf3; font-size:18px; text-align:center; letter-spacing:3px; font-family:monospace; outline:none; transition:border-color 0.2s; }
.key-input:focus { border-color:#00b4d8; box-shadow:0 0 0 3px rgba(0,180,216,0.1); }
.btn-activate { width:100%; padding:12px; border-radius:8px; border:none; background:linear-gradient(135deg,#0077b6,#00b4d8); color:#fff; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.2s; }
.btn-activate:hover { opacity:0.9; }
.error-box { background:#2a0a0a; border:1px solid #da3633; border-radius:8px; padding:10px 13px; font-size:13px; color:#f85149; margin-bottom:14px; }
.success-box { background:#0d2a1a; border:1px solid #238636; border-radius:8px; padding:10px 13px; font-size:13px; color:#2ea043; margin-bottom:14px; }
.hint { font-size:11px; color:#6e7681; text-align:center; margin-top:12px; }
.hint a { color:#58a6ff; }
.back-link { display:block; text-align:center; margin-top:14px; font-size:12px; color:#8b949e; text-decoration:none; }
.back-link:hover { color:#e6edf3; }
</style>
</head>
<body>
<div class="container">
  <div class="logo-wrap">
    <div class="logo-icon">POT</div>
    <div class="logo-title">POT System</div>
  </div>
  <div class="card">
    <div class="card-icon">🔐</div>
    <div class="card-title">
      <?= $daysLeft > 0 ? "Trial: {$daysLeft} Days Remaining" : "Trial Period Expired" ?>
    </div>
    <div class="card-sub">
      <?= $daysLeft > 0
        ? "Enter your activation key to unlock the full version of POT System."
        : "Your 7-day free trial has ended. Enter a valid 15-digit activation key to continue." ?>
    </div>

    <?php if ($error): ?>
    <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="success-box">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="device-box">
      <span>Device ID (bound to this machine): </span><br>
      <code><?= htmlspecialchars(substr($deviceId, 0, 32)) ?>...</code>
    </div>

    <form method="POST" action="/activate.php">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <div class="form-group">
        <label>Activation Key</label>
        <input class="key-input" type="text" name="key" maxlength="17"
               placeholder="XXXXX-XXXXX-XXXXX"
               oninput="formatKey(this)" autocomplete="off">
      </div>
      <button type="submit" class="btn-activate">Activate License</button>
    </form>

    <div class="hint">
      Need a key? Go to <a href="/keygen.php">Key Generator</a> or contact your reseller.<br>
      <strong style="color:#e3b341">1 Key = 1 Device Only</strong>
    </div>
  </div>
  <a class="back-link" href="/dashboard.php">← Back to Dashboard</a>
</div>
<script>
function formatKey(input) {
    let val = input.value.replace(/[^A-Z0-9a-z]/g,'').toUpperCase();
    let out = '';
    for (let i = 0; i < val.length && i < 15; i++) {
        if (i > 0 && i % 5 === 0) out += '-';
        out += val[i];
    }
    input.value = out;
}
</script>
</body>
</html>
