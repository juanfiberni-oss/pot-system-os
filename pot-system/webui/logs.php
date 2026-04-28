<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

$logFiles = [
    'System'    => '/var/log/syslog',
    'accel-ppp' => '/var/log/accel-ppp/accel-ppp.log',
    'Billing'   => '/var/log/pot-billing.log',
    'Reminders' => '/var/log/pot-reminders.log',
    'Nginx'     => '/var/log/nginx/error.log',
    'dnsmasq'   => '/var/log/dnsmasq.log',
];

$selected = $_GET['log'] ?? 'System';
$lines    = (int)($_GET['lines'] ?? 100);
$search   = trim($_GET['search'] ?? '');

if (!array_key_exists($selected, $logFiles)) $selected = 'System';
$logPath = $logFiles[$selected];

$logContent = '';
if (file_exists($logPath)) {
    $cmd = "tail -n " . escapeshellarg($lines) . " " . escapeshellarg($logPath);
    if ($search) $cmd .= " | grep -i " . escapeshellarg($search);
    $logContent = shell_exec($cmd . ' 2>/dev/null') ?? 'No log content.';
} else {
    $logContent = "Log file not found: {$logPath}";
}

$pageTitle = 'System Logs';
?>
<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<div class="main">
<?php include 'partials/topbar.php'; ?>
<div class="content">

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">📋 System Logs</div>
    <button class="btn btn-ghost btn-sm" onclick="location.reload()">↻ Refresh</button>
  </div>
  <div style="padding:16px;border-bottom:1px solid #30363d;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select name="log" onchange="this.form.submit()" style="background:#0d1117;border:1px solid #30363d;border-radius:7px;padding:7px 12px;color:#e6edf3;font-size:13px;outline:none">
        <?php foreach ($logFiles as $name => $path): ?>
        <option value="<?= htmlspecialchars($name) ?>" <?= $selected===$name?'selected':'' ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="lines" onchange="this.form.submit()" style="background:#0d1117;border:1px solid #30363d;border-radius:7px;padding:7px 12px;color:#e6edf3;font-size:13px;outline:none">
        <option value="50" <?= $lines==50?'selected':'' ?>>Last 50 lines</option>
        <option value="100" <?= $lines==100?'selected':'' ?>>Last 100 lines</option>
        <option value="200" <?= $lines==200?'selected':'' ?>>Last 200 lines</option>
        <option value="500" <?= $lines==500?'selected':'' ?>>Last 500 lines</option>
      </select>
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search logs..." style="background:#0d1117;border:1px solid #30363d;border-radius:7px;padding:7px 12px;color:#e6edf3;font-size:13px;outline:none;width:200px">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
  </div>
  <div style="padding:16px">
    <pre style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px;font-size:11px;color:#8b949e;overflow:auto;max-height:600px;white-space:pre-wrap;line-height:1.5"><?php
    $lines2 = explode("\n", htmlspecialchars($logContent));
    foreach ($lines2 as $line) {
        if (stripos($line, 'error') !== false || stripos($line, 'fail') !== false) {
            echo '<span style="color:#f85149">' . $line . '</span>' . "\n";
        } elseif (stripos($line, 'warn') !== false) {
            echo '<span style="color:#e3b341">' . $line . '</span>' . "\n";
        } elseif (stripos($line, 'success') !== false || stripos($line, 'started') !== false || stripos($line, 'connected') !== false) {
            echo '<span style="color:#2ea043">' . $line . '</span>' . "\n";
        } elseif ($search && stripos($line, htmlspecialchars($search)) !== false) {
            echo '<span style="background:#2a2a0a;color:#e3b341">' . $line . '</span>' . "\n";
        } else {
            echo $line . "\n";
        }
    }
    ?></pre>
  </div>
</div>

</div></div>
</body></html>
