<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo 'Access denied.'; exit; }

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$error = ''; $success = ''; $generatedKey = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Export CSV
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $keys = $db->query("SELECT key, device_id, customer, type, created_at, status FROM license_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pot-license-keys-' . date('Ymd') . '.csv"');
    echo "Key,Device ID,Customer,Type,Generated Date,Status\n";
    foreach ($keys as $k) {
        echo '"' . implode('","', array_values($k)) . '"' . "\n";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $error = 'Invalid request.';
    } else {
        $act = $_POST['act'] ?? '';

        if ($act === 'generate') {
            $deviceId = preg_replace('/[^A-Z0-9\-]/i', '', $_POST['device_id'] ?? '');
            $licType  = in_array($_POST['lic_type'] ?? '', ['trial','standard','lifetime']) ? $_POST['lic_type'] : 'standard';
            $customer = trim($_POST['customer'] ?? 'Unknown');
            $notes    = trim($_POST['notes'] ?? '');

            if (empty($deviceId)) { $error = 'Device ID is required.'; }
            else {
                // Generate random 15-char alphanumeric key
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $raw = '';
                for ($i = 0; $i < 15; $i++) {
                    $raw .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $key = substr($raw,0,5).'-'.substr($raw,5,5).'-'.substr($raw,10,5);

                $db->prepare("INSERT INTO license_keys (key, device_id, customer, type, status) VALUES (?,?,?,?,'active')")
                   ->execute([$key, $deviceId, $customer, $licType]);

                $generatedKey = $key;
                $success = "Key generated for {$customer}";
            }
        } elseif ($act === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE license_keys SET status='revoked' WHERE id=?")->execute([$id]);
            $success = 'Key revoked.';
        }
    }
}

$keys = $db->query("SELECT * FROM license_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Generate device ID hint from server
$hostname = trim(shell_exec('hostname') ?? 'unknown');
$mac = trim(shell_exec("ip link show 2>/dev/null | grep 'link/ether' | head -1 | awk '{print $2}'") ?? '');
$serverDeviceId = strtoupper(substr(hash('sha256', $hostname . $mac), 0, 32));

$pageTitle = 'License Key Generator';
?>
<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<div class="main">
<?php include 'partials/topbar.php'; ?>
<div class="content" style="max-width:960px">

<!-- Warning -->
<div class="alert alert-warn" style="display:flex;align-items:center;gap:10px">
  <span style="font-size:20px">⚠️</span>
  <div><strong>1 Key = 1 Device Only</strong> — Each activation key is bound to a single Device ID and cannot be reused on another machine.</div>
</div>

<?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Generator Form -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header">
    <div class="panel-title">🔑 Generate New License Key</div>
  </div>
  <div style="padding:20px">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="act" value="generate">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Device ID</label>
          <div style="display:flex;gap:8px">
            <input type="text" name="device_id" id="device-id" value="<?= htmlspecialchars($serverDeviceId) ?>" style="font-family:monospace;font-size:12px" required>
            <button type="button" class="btn btn-ghost" onclick="regenDeviceId()" style="white-space:nowrap">↻ New</button>
          </div>
        </div>
        <div class="form-group">
          <label>License Type</label>
          <select name="lic_type">
            <option value="trial">Trial (7 days)</option>
            <option value="standard" selected>Standard (1 year)</option>
            <option value="lifetime">Lifetime</option>
          </select>
        </div>
        <div class="form-group">
          <label>Customer Name</label>
          <input type="text" name="customer" placeholder="e.g. Juan dela Cruz">
        </div>
        <div class="form-group">
          <label>Notes (optional)</label>
          <input type="text" name="notes" placeholder="e.g. Reseller: ABC Networks">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:4px;padding:10px 24px">🔑 Generate Key</button>
    </form>

    <?php if ($generatedKey): ?>
    <div style="background:#0d1117;border:2px solid #00b4d8;border-radius:10px;padding:20px;text-align:center;margin-top:20px">
      <div style="font-size:11px;color:#8b949e;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">Generated License Key</div>
      <div id="gen-key" style="font-family:monospace;font-size:28px;font-weight:700;color:#00b4d8;letter-spacing:4px;text-shadow:0 0 20px rgba(0,180,216,0.3);margin-bottom:14px"><?= htmlspecialchars($generatedKey) ?></div>
      <button class="btn btn-green" onclick="copyKey()">📋 Copy Key</button>
      <div id="copy-fb" style="font-size:12px;color:#2ea043;margin-top:8px;display:none">✅ Copied to clipboard!</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Keys History -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">📋 Generated Keys History <span class="panel-badge"><?= count($keys) ?> keys</span></div>
    <a href="/keygen.php?action=export" class="btn btn-ghost btn-sm">⬇ Export CSV</a>
  </div>
  <div class="panel-body">
    <table>
      <thead><tr><th>Key</th><th>Device ID</th><th>Customer</th><th>Type</th><th>Generated</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (empty($keys)): ?>
      <tr><td colspan="7" style="text-align:center;color:#8b949e;padding:20px">No keys generated yet.</td></tr>
      <?php else: ?>
      <?php foreach ($keys as $k): ?>
      <?php
        $typeColors = ['trial'=>'#58a6ff','standard'=>'#2ea043','lifetime'=>'#a371f7'];
        $typeBg = ['trial'=>'#1a2a3a','standard'=>'#1a2a1a','lifetime'=>'#2a1a3a'];
        $tc = $typeColors[$k['type']] ?? '#8b949e';
        $tb = $typeBg[$k['type']] ?? '#21262d';
      ?>
      <tr>
        <td style="font-family:monospace;font-size:13px;color:#00b4d8;letter-spacing:1px"><?= htmlspecialchars($k['key']) ?></td>
        <td class="mono"><?= htmlspecialchars(substr($k['device_id'] ?? '', 0, 20)) ?>...</td>
        <td style="color:#e6edf3"><?= htmlspecialchars($k['customer'] ?? '—') ?></td>
        <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;background:<?= $tb ?>;color:<?= $tc ?>"><?= ucfirst($k['type']) ?></span></td>
        <td class="mono"><?= substr($k['created_at'] ?? '', 0, 10) ?></td>
        <td>
          <?php if ($k['status'] === 'active'): ?>
          <span style="color:#2ea043;font-size:12px;font-weight:600">Active</span>
          <?php else: ?>
          <span style="color:#f85149;font-size:12px;font-weight:600;text-decoration:line-through">Revoked</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($k['status'] === 'active'): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Revoke this key? This cannot be undone.')">
            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
            <input type="hidden" name="act" value="revoke">
            <input type="hidden" name="id" value="<?= $k['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
          </form>
          <?php else: ?>
          <span style="color:#6e7681;font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<script>
function regenDeviceId() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let id = '';
    for (let i = 0; i < 32; i++) id += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('device-id').value = id;
}
function copyKey() {
    const key = document.getElementById('gen-key')?.textContent;
    if (!key) return;
    navigator.clipboard.writeText(key).then(() => {
        const fb = document.getElementById('copy-fb');
        fb.style.display = 'block';
        setTimeout(() => fb.style.display = 'none', 2000);
    });
}
</script>
</body></html>
