<?php
/**
 * POT System - Subscriber Self-Service Portal
 * Clients can check their bill, usage, and pay online
 */
session_start();

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$client = null;
$invoices = [];

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sub_login'])) {
    $username = trim($_POST['username'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    if ($username && $phone) {
        $stmt = $db->prepare("SELECT * FROM billing_clients WHERE username=? AND phone=?");
        $stmt->execute([$username, $phone]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $_SESSION['sub_client_id'] = $client['id'];
        } else {
            $error = 'Username or phone number not found.';
        }
    }
}

// Auto-login from session
if (!$client && isset($_SESSION['sub_client_id'])) {
    $stmt = $db->prepare("SELECT * FROM billing_clients WHERE id=?");
    $stmt->execute([$_SESSION['sub_client_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['sub_client_id']);
    header('Location: /subscriber.php');
    exit;
}

// Get invoices if logged in
if ($client) {
    $invoices = $db->prepare("SELECT * FROM billing_invoices WHERE client_id=? ORDER BY due_date DESC LIMIT 12");
    $invoices->execute([$client['id']]);
    $invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POT System — Subscriber Portal</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; }
.topbar { background:#161b22; border-bottom:1px solid #30363d; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; }
.logo { display:flex; align-items:center; gap:10px; }
.logo-icon { width:36px; height:36px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:900; color:#fff; }
.logo-text { font-size:15px; font-weight:700; color:#e6edf3; }
.logo-sub { font-size:10px; color:#8b949e; }
.container { max-width:600px; margin:0 auto; padding:24px 16px; }
.card { background:#161b22; border:1px solid #30363d; border-radius:12px; padding:24px; margin-bottom:16px; }
.card-title { font-size:15px; font-weight:600; color:#e6edf3; margin-bottom:16px; }
.form-group { margin-bottom:14px; }
label { display:block; font-size:12px; color:#8b949e; margin-bottom:5px; font-weight:500; }
input { width:100%; background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:10px 13px; color:#e6edf3; font-size:14px; outline:none; }
input:focus { border-color:#00b4d8; }
.btn { padding:10px 20px; border-radius:8px; border:none; font-size:13px; font-weight:600; cursor:pointer; }
.btn-primary { background:linear-gradient(135deg,#0077b6,#00b4d8); color:#fff; width:100%; padding:12px; }
.btn-pay { background:linear-gradient(135deg,#238636,#2ea043); color:#fff; padding:6px 14px; font-size:12px; border-radius:6px; text-decoration:none; display:inline-block; }
.btn-logout { background:#21262d; border:1px solid #30363d; color:#8b949e; padding:6px 14px; font-size:12px; border-radius:6px; text-decoration:none; }
.status-card { border-radius:10px; padding:16px 20px; margin-bottom:16px; display:flex; align-items:center; gap:14px; }
.status-active { background:#0d2a1a; border:1px solid #238636; }
.status-suspended { background:#2a0a0a; border:1px solid #da3633; }
.status-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.dot-green { background:#2ea043; box-shadow:0 0 8px #2ea043; }
.dot-red { background:#f85149; box-shadow:0 0 8px #f85149; }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.info-item { background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:12px; }
.info-item .label { font-size:11px; color:#8b949e; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; }
.info-item .value { font-size:16px; font-weight:600; color:#e6edf3; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { padding:8px 12px; text-align:left; font-size:11px; color:#8b949e; font-weight:600; text-transform:uppercase; border-bottom:1px solid #30363d; }
td { padding:10px 12px; border-bottom:1px solid #21262d; }
tr:last-child td { border-bottom:none; }
.badge { font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600; }
.badge-paid { background:#0d2a1a; color:#2ea043; }
.badge-overdue { background:#2a0a0a; color:#f85149; }
.badge-pending { background:#2a1a0a; color:#e3b341; }
.alert-error { background:#2a0a0a; border:1px solid #da3633; border-radius:8px; padding:10px 13px; font-size:13px; color:#f85149; margin-bottom:14px; }
</style>
</head>
<body>
<div class="topbar">
  <div class="logo">
    <div class="logo-icon">POT</div>
    <div>
      <div class="logo-text">POT System</div>
      <div class="logo-sub">Subscriber Portal</div>
    </div>
  </div>
  <?php if ($client): ?>
  <a href="/subscriber.php?logout=1" class="btn-logout">Logout</a>
  <?php endif; ?>
</div>

<div class="container">
<?php if (!$client): ?>
  <!-- Login Form -->
  <div class="card">
    <div class="card-title">🔐 Subscriber Login</div>
    <?php if ($error): ?><div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="sub_login" value="1">
      <div class="form-group">
        <label>Username / Account Name</label>
        <input type="text" name="username" placeholder="e.g. user001" required>
      </div>
      <div class="form-group">
        <label>Phone Number (for verification)</label>
        <input type="text" name="phone" placeholder="e.g. 09XX-XXX-XXXX" required>
      </div>
      <button type="submit" class="btn btn-primary">Login →</button>
    </form>
  </div>
  <div style="text-align:center;font-size:12px;color:#6e7681;margin-top:12px">
    Forgot your credentials? Contact your ISP.
  </div>

<?php else: ?>
  <!-- Connection Status -->
  <div class="status-card <?= $client['status']==='active'?'status-active':'status-suspended' ?>">
    <div class="status-dot <?= $client['status']==='active'?'dot-green':'dot-red' ?>"></div>
    <div>
      <strong style="color:#e6edf3;display:block;font-size:15px">
        <?= $client['status']==='active' ? '🌐 Connected' : '🚫 Disconnected' ?>
      </strong>
      <span style="color:#8b949e;font-size:12px">
        <?= $client['status']==='active'
          ? 'Your internet connection is active'
          : 'Your connection has been suspended. Please pay your bill to reconnect.' ?>
      </span>
    </div>
  </div>

  <!-- Account Info -->
  <div class="card">
    <div class="card-title">👤 Account Information</div>
    <div class="info-grid">
      <div class="info-item">
        <div class="label">Name</div>
        <div class="value" style="font-size:14px"><?= htmlspecialchars($client['name']) ?></div>
      </div>
      <div class="info-item">
        <div class="label">Plan</div>
        <div class="value" style="font-size:14px"><?= htmlspecialchars($client['plan'] ?? '—') ?></div>
      </div>
      <div class="info-item">
        <div class="label">Connection</div>
        <div class="value"><?= strtoupper($client['connection_type']) ?></div>
      </div>
      <div class="info-item">
        <div class="label">Monthly Bill</div>
        <div class="value" style="color:#e3b341">₱<?= number_format($client['amount'] ?? 0, 2) ?></div>
      </div>
    </div>
  </div>

  <!-- Invoices -->
  <div class="card">
    <div class="card-title">📄 Billing History</div>
    <?php if (empty($invoices)): ?>
    <div style="text-align:center;color:#8b949e;padding:20px">No invoices yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>Due Date</th><th>Amount</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
      <?php foreach ($invoices as $inv): ?>
      <tr>
        <td><?= htmlspecialchars($inv['due_date']) ?></td>
        <td style="font-weight:600;color:#e6edf3">₱<?= number_format($inv['amount'], 2) ?></td>
        <td>
          <span class="badge badge-<?= $inv['status'] ?>">
            <?= strtoupper($inv['status']) ?>
          </span>
        </td>
        <td>
          <?php if (in_array($inv['status'], ['pending','overdue'])): ?>
          <a href="/payment.php?invoice=<?= $inv['id'] ?>" class="btn-pay">💳 Pay Now</a>
          <?php else: ?>
          <span style="color:#2ea043;font-size:12px">✓ Paid</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php endif; ?>
</div>
</body>
</html>
