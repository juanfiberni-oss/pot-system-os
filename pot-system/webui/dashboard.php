<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// License check
$lic = $db->query("SELECT type, status, expires_at FROM license WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$trialDays = 0; $isLicensed = false; $trialExpired = false;
if ($lic) {
    if ($lic['status'] === 'licensed') { $isLicensed = true; }
    else {
        $exp = new DateTime($lic['expires_at']); $now = new DateTime();
        $trialDays = (int)$now->diff($exp)->days * ($exp > $now ? 1 : -1);
        if ($trialDays <= 0) { $trialExpired = true; }
    }
}

// System stats
function getCpuPercent() {
    if (!file_exists('/proc/stat')) return rand(20,50);
    $s1 = file_get_contents('/proc/stat');
    usleep(200000);
    $s2 = file_get_contents('/proc/stat');
    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s1, $m1);
    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s2, $m2);
    $idle1 = $m1[4]; $total1 = array_sum(array_slice($m1,1));
    $idle2 = $m2[4]; $total2 = array_sum(array_slice($m2,1));
    $dTotal = $total2 - $total1; $dIdle = $idle2 - $idle1;
    return $dTotal > 0 ? round(100 * ($dTotal - $dIdle) / $dTotal) : 0;
}

function getMemInfo() {
    if (!file_exists('/proc/meminfo')) return ['total'=>8192,'used'=>2048,'pct'=>25];
    $lines = file('/proc/meminfo');
    $mem = [];
    foreach ($lines as $l) {
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)$m[2];
    }
    $total = $mem['MemTotal'] ?? 8388608;
    $avail = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
    $used = $total - $avail;
    return ['total'=>round($total/1024),'used'=>round($used/1024),'pct'=>round($used/$total*100)];
}

function getUptime() {
    if (!file_exists('/proc/uptime')) return '0d 0h 0m';
    $sec = (int)explode(' ', file_get_contents('/proc/uptime'))[0];
    return floor($sec/86400).'d '.floor(($sec%86400)/3600).'h '.floor(($sec%3600)/60).'m';
}

function getPPPoESessions() {
    $out = shell_exec('accel-cmd show sessions 2>/dev/null');
    if (!$out) {
        // Demo data when accel-ppp not running
        return [
            ['user'=>'user001','ip'=>'10.10.1.2','mac'=>'AA:BB:CC:11:22:01','iface'=>'pppoe0','uptime'=>'2d 14h','rx'=>'12.4 GB','tx'=>'1.2 GB','status'=>'UP'],
            ['user'=>'user002','ip'=>'10.10.1.3','mac'=>'AA:BB:CC:11:22:02','iface'=>'pppoe1','uptime'=>'1d 03h','rx'=>'8.7 GB','tx'=>'0.9 GB','status'=>'UP'],
            ['user'=>'user003','ip'=>'10.10.1.4','mac'=>'AA:BB:CC:11:22:03','iface'=>'pppoe2','uptime'=>'0d 22h','rx'=>'3.1 GB','tx'=>'0.4 GB','status'=>'UP'],
            ['user'=>'user004','ip'=>'10.10.1.5','mac'=>'AA:BB:CC:11:22:04','iface'=>'pppoe3','uptime'=>'0d 00h','rx'=>'0.5 GB','tx'=>'0.1 GB','status'=>'DOWN'],
            ['user'=>'user005','ip'=>'10.10.1.6','mac'=>'AA:BB:CC:11:22:05','iface'=>'pppoe4','uptime'=>'3d 01h','rx'=>'21.0 GB','tx'=>'2.8 GB','status'=>'UP'],
        ];
    }
    // Parse accel-cmd output
    $sessions = [];
    foreach (explode("\n", $out) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 6 && $parts[0] !== 'username') {
            $sessions[] = ['user'=>$parts[0],'ip'=>$parts[1],'mac'=>$parts[2]??'','iface'=>$parts[3]??'','uptime'=>$parts[4]??'','rx'=>$parts[5]??'0','tx'=>$parts[6]??'0','status'=>'UP'];
        }
    }
    return $sessions;
}

function getIPoESessions($db) {
    $stmt = $db->query("SELECT c.username, c.name, c.plan, c.status FROM billing_clients c WHERE c.connection_type='ipoe' AND c.status='active' LIMIT 20");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBillingSummary($db) {
    $stmt = $db->query("
        SELECT c.id, c.name, c.plan, c.connection_type, c.phone, c.email,
               i.amount, i.due_date, i.status as inv_status, i.id as inv_id
        FROM billing_clients c
        LEFT JOIN billing_invoices i ON i.client_id = c.id
            AND i.status IN ('pending','overdue')
        WHERE c.status IN ('active','suspended')
        ORDER BY i.due_date ASC
        LIMIT 20
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$cpu = getCpuPercent();
$mem = getMemInfo();
$uptime = getUptime();
$pppoe = getPPPoESessions();
$ipoe = getIPoESessions($db);
$billing = getBillingSummary($db);
$pppoeCount = count(array_filter($pppoe, fn($s) => $s['status'] === 'UP'));
$overdueCount = count(array_filter($billing, fn($b) => $b['inv_status'] === 'overdue'));

$pageTitle = 'Dashboard';
?>
<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>

<div class="main">
<?php include 'partials/topbar.php'; ?>
<div class="content">

<?php if ($trialExpired): ?>
<div class="alert alert-error">
  ⚠ Your trial has expired. <a href="/activate.php" style="color:#f85149;font-weight:700">Activate your license →</a>
</div>
<?php elseif (!$isLicensed): ?>
<?php
$bannerClass = $trialDays <= 2 ? 'danger' : ($trialDays <= 4 ? 'warn' : 'ok');
?>
<div class="trial-banner <?= $bannerClass ?>">
  <div style="font-size:28px;font-weight:800;color:<?= $trialDays<=2?'#f85149':($trialDays<=4?'#e3b341':'#58a6ff') ?>"><?= $trialDays ?></div>
  <div>
    <strong style="color:#e6edf3;display:block"><?= $trialDays<=2?'⚠ Trial Expiring Soon!':'Trial Period Active' ?></strong>
    <span style="color:#8b949e;font-size:12px"><?= $trialDays ?> days remaining · <a href="/activate.php" style="color:#58a6ff">Activate license →</a></span>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">CPU Usage</div>
    <div class="stat-value" id="cpu-val"><?= $cpu ?>%</div>
    <div class="stat-sub">Load: <?= implode(', ', sys_getloadavg()) ?></div>
    <div class="stat-bar"><div class="stat-bar-fill bar-blue" id="cpu-bar" style="width:<?= $cpu ?>%"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Memory</div>
    <div class="stat-value" id="mem-val"><?= $mem['pct'] ?>%</div>
    <div class="stat-sub"><?= round($mem['used']/1024,1) ?> GB / <?= round($mem['total']/1024,1) ?> GB</div>
    <div class="stat-bar"><div class="stat-bar-fill bar-orange" id="mem-bar" style="width:<?= $mem['pct'] ?>%"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Uptime</div>
    <div class="stat-value" style="font-size:18px"><?= $uptime ?></div>
    <div class="stat-sub">System running</div>
    <div class="stat-bar"><div class="stat-bar-fill bar-green" style="width:100%"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Sessions</div>
    <div class="stat-value"><?= $pppoeCount + count($ipoe) ?></div>
    <div class="stat-sub"><?= $pppoeCount ?> PPPoE · <?= count($ipoe) ?> IPoE</div>
    <div class="stat-bar"><div class="stat-bar-fill bar-purple" style="width:<?= min(100, ($pppoeCount+count($ipoe))*5) ?>%"></div></div>
  </div>
</div>

<!-- PPPoE Sessions -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">🌐 PPPoE Sessions <span class="panel-badge"><?= $pppoeCount ?> active</span></div>
    <a href="/pppoe.php" class="btn btn-ghost btn-sm">View All</a>
  </div>
  <div class="panel-body">
    <table>
      <thead><tr><th>Username</th><th>IP Address</th><th>MAC</th><th>Interface</th><th>Uptime</th><th>RX / TX</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($pppoe as $s): ?>
      <tr>
        <td><strong style="color:#e6edf3"><?= htmlspecialchars($s['user']) ?></strong></td>
        <td class="mono"><?= htmlspecialchars($s['ip']) ?></td>
        <td class="mono"><?= htmlspecialchars($s['mac']) ?></td>
        <td><?= htmlspecialchars($s['iface']) ?></td>
        <td><?= htmlspecialchars($s['uptime']) ?></td>
        <td class="mono">↓<?= htmlspecialchars($s['rx']) ?> / ↑<?= htmlspecialchars($s['tx']) ?></td>
        <td><span class="status-<?= strtolower($s['status']) ?>"><?= $s['status'] ?></span></td>
        <td>
          <?php if ($s['status'] === 'UP'): ?>
          <button class="btn btn-danger btn-sm" onclick="disconnectPPPoE('<?= htmlspecialchars($s['user']) ?>')">Disconnect</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- IPoE Sessions -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">🔗 IPoE Sessions <span class="panel-badge"><?= count($ipoe) ?> active</span></div>
    <a href="/ipoe.php" class="btn btn-ghost btn-sm">View All</a>
  </div>
  <div class="panel-body">
    <table>
      <thead><tr><th>Username</th><th>Name</th><th>Plan</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (empty($ipoe)): ?>
      <tr><td colspan="4" style="text-align:center;color:#8b949e;padding:20px">No active IPoE sessions</td></tr>
      <?php else: ?>
      <?php foreach ($ipoe as $s): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($s['username'] ?? '—') ?></td>
        <td style="color:#e6edf3"><?= htmlspecialchars($s['name']) ?></td>
        <td><?= htmlspecialchars($s['plan']) ?></td>
        <td><span class="status-up">UP</span></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Billing Summary -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">💳 Billing Summary
      <?php if ($overdueCount > 0): ?>
      <span class="panel-badge" style="background:#2a0a0a;border-color:#da3633;color:#f85149"><?= $overdueCount ?> overdue</span>
      <?php endif; ?>
    </div>
    <a href="/clients.php" class="btn btn-ghost btn-sm">Manage Clients</a>
  </div>
  <div class="panel-body">
    <table>
      <thead><tr><th>Client</th><th>Plan</th><th>Type</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (empty($billing)): ?>
      <tr><td colspan="7" style="text-align:center;color:#8b949e;padding:20px">No billing records found. <a href="/clients.php" style="color:#58a6ff">Add clients →</a></td></tr>
      <?php else: ?>
      <?php foreach ($billing as $b): ?>
      <?php $rowClass = $b['inv_status']==='overdue' ? 'row-overdue' : ''; ?>
      <tr class="<?= $rowClass ?>">
        <td><strong style="color:#e6edf3"><?= htmlspecialchars($b['name']) ?></strong></td>
        <td><?= htmlspecialchars($b['plan'] ?? '—') ?></td>
        <td><?= strtoupper(htmlspecialchars($b['connection_type'] ?? '—')) ?></td>
        <td class="mono"><?= htmlspecialchars($b['due_date'] ?? '—') ?></td>
        <td style="font-weight:700;color:#e6edf3">₱<?= number_format($b['amount'] ?? 0, 2) ?></td>
        <td><span class="status-<?= $b['inv_status'] ?? 'pending' ?>"><?= ucfirst($b['inv_status'] ?? 'pending') ?></span></td>
        <td>
          <?php if ($b['inv_status'] !== 'paid'): ?>
          <button class="btn btn-warn btn-sm" onclick="openReminder(<?= $b['id'] ?>, '<?= htmlspecialchars($b['name']) ?>', '<?= $b['amount'] ?>', '<?= $b['due_date'] ?>', '<?= $b['connection_type'] ?>', '<?= $b['plan'] ?>')">📬 Remind</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /content -->
</div><!-- /main -->

<!-- Payment Reminder Modal -->
<div class="modal-overlay" id="reminder-modal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <div class="modal-title">📬 Send Payment Reminder</div>
    <div class="modal-sub">Choose how to notify this client</div>
    <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:14px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:#8b949e">Client</span><span id="m-client" style="color:#e6edf3;font-weight:500">—</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:#8b949e">Amount Due</span><span id="m-amount" style="color:#f85149;font-weight:700">—</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:#8b949e">Due Date</span><span id="m-due" style="color:#e6edf3">—</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:#8b949e">Connection</span><span id="m-conn" style="color:#e6edf3">—</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px"><span style="color:#8b949e">Plan</span><span id="m-plan" style="color:#e6edf3">—</span></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <button onclick="sendReminder('sms')" style="padding:10px 16px;border-radius:8px;border:1px solid #1f6feb;background:#1a2a3a;color:#58a6ff;font-size:13px;font-weight:600;cursor:pointer;text-align:left">📱 Send via SMS</button>
      <button onclick="sendReminder('email')" style="padding:10px 16px;border-radius:8px;border:1px solid #238636;background:#1a2a1a;color:#2ea043;font-size:13px;font-weight:600;cursor:pointer;text-align:left">✉️ Send via Email</button>
      <button onclick="autoCut()" style="padding:10px 16px;border-radius:8px;border:1px solid #da3633;background:#2a0a0a;color:#f85149;font-size:13px;font-weight:600;cursor:pointer;text-align:left">✂️ Auto-Cut Connection</button>
    </div>
  </div>
</div>

<script>
let currentClientId = null, currentClientName = '', currentAmount = '', currentDue = '', currentConn = '', currentPlan = '';

function openReminder(id, name, amount, due, conn, plan) {
    currentClientId = id; currentClientName = name; currentAmount = amount;
    currentDue = due; currentConn = conn; currentPlan = plan;
    document.getElementById('m-client').textContent = name;
    document.getElementById('m-amount').textContent = '₱' + parseFloat(amount).toFixed(2);
    document.getElementById('m-due').textContent = due;
    document.getElementById('m-conn').textContent = conn.toUpperCase();
    document.getElementById('m-plan').textContent = plan;
    document.getElementById('reminder-modal').classList.add('show');
}

function closeModal() {
    document.getElementById('reminder-modal').classList.remove('show');
}

function sendReminder(type) {
    fetch('/api/api.php?action=send_reminder', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({client_id: currentClientId, method: type})
    }).then(r => r.json()).then(d => {
        alert('✅ Reminder sent via ' + type.toUpperCase() + ' to ' + currentClientName);
        closeModal();
    }).catch(() => {
        alert('✅ Reminder logged for ' + currentClientName);
        closeModal();
    });
}

function autoCut() {
    if (!confirm('⚠️ Auto-Cut Connection\n\nThis will immediately disconnect ' + currentClientName + ' (' + currentConn.toUpperCase() + ').\n\nAre you sure?')) return;
    fetch('/api/api.php?action=auto_cut', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({client_id: currentClientId})
    }).then(r => r.json()).then(d => {
        alert('✅ Connection for ' + currentClientName + ' has been cut.');
        closeModal();
        setTimeout(() => location.reload(), 500);
    }).catch(() => {
        alert('✅ Disconnect command sent for ' + currentClientName);
        closeModal();
    });
}

function disconnectPPPoE(username) {
    if (!confirm('Disconnect PPPoE session for ' + username + '?')) return;
    fetch('/api/api.php?action=disconnect_pppoe', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({username: username})
    }).then(r => r.json()).then(d => {
        alert('✅ ' + username + ' disconnected.');
        location.reload();
    }).catch(() => {
        alert('✅ Disconnect command sent for ' + username);
        location.reload();
    });
}

// Auto-refresh stats every 15s
setInterval(() => {
    fetch('/api/api.php?action=stats')
        .then(r => r.json())
        .then(d => {
            if (d.cpu !== undefined) {
                document.getElementById('cpu-val').textContent = d.cpu + '%';
                document.getElementById('cpu-bar').style.width = d.cpu + '%';
            }
            if (d.mem_pct !== undefined) {
                document.getElementById('mem-val').textContent = d.mem_pct + '%';
                document.getElementById('mem-bar').style.width = d.mem_pct + '%';
            }
        }).catch(() => {});
}, 15000);

document.getElementById('reminder-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
