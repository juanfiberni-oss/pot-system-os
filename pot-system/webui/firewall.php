<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$error = ''; $success = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $error = 'Invalid request.';
    } else {
        $chain  = in_array($_POST['chain'] ?? '', ['input','forward','output']) ? $_POST['chain'] : 'forward';
        $proto  = in_array($_POST['proto'] ?? '', ['tcp','udp','icmp','any']) ? $_POST['proto'] : 'tcp';
        $srcIp  = trim($_POST['src_ip'] ?? '');
        $dstIp  = trim($_POST['dst_ip'] ?? '');
        $dstPort= (int)($_POST['dst_port'] ?? 0);
        $act    = in_array($_POST['action'] ?? '', ['accept','drop','reject']) ? $_POST['action'] : 'drop';
        $comment= preg_replace('/[^a-zA-Z0-9 _\-]/', '', $_POST['comment'] ?? '');

        // Validate IPs
        if ($srcIp && !filter_var($srcIp, FILTER_VALIDATE_IP) && !preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $srcIp)) {
            $error = 'Invalid source IP address.';
        } elseif ($dstIp && !filter_var($dstIp, FILTER_VALIDATE_IP) && !preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $dstIp)) {
            $error = 'Invalid destination IP address.';
        } else {
            $rule = "nft add rule inet filter {$chain}";
            if ($proto !== 'any') $rule .= " {$proto}";
            if ($srcIp) $rule .= " ip saddr " . escapeshellarg($srcIp);
            if ($dstIp) $rule .= " ip daddr " . escapeshellarg($dstIp);
            if ($dstPort > 0 && $proto !== 'icmp') $rule .= " {$proto} dport {$dstPort}";
            $rule .= " {$act}";
            if ($comment) $rule .= " comment " . escapeshellarg($comment);

            $out = shell_exec($rule . ' 2>&1');
            if ($out && stripos($out, 'error') !== false) {
                $error = 'nftables error: ' . htmlspecialchars($out);
            } else {
                $success = "Rule added: {$proto} {$srcIp}→{$dstIp}:{$dstPort} → " . strtoupper($act);
            }
        }
    }
}

// Get current rules
$rulesRaw = shell_exec('nft list ruleset 2>/dev/null') ?? '';
$pageTitle = 'Firewall Rules';
?>
<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<div class="main">
<?php include 'partials/topbar.php'; ?>
<div class="content">

<?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Add Rule -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header"><div class="panel-title">➕ Add Firewall Rule</div></div>
  <div style="padding:20px">
    <form method="POST" action="/firewall.php">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Chain</label>
          <select name="chain">
            <option value="input">INPUT (to router)</option>
            <option value="forward" selected>FORWARD (through router)</option>
            <option value="output">OUTPUT (from router)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Protocol</label>
          <select name="proto">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
            <option value="icmp">ICMP</option>
            <option value="any">ANY</option>
          </select>
        </div>
        <div class="form-group">
          <label>Source IP / Subnet (blank = any)</label>
          <input type="text" name="src_ip" placeholder="e.g. 192.168.1.0/24">
        </div>
        <div class="form-group">
          <label>Destination IP / Subnet (blank = any)</label>
          <input type="text" name="dst_ip" placeholder="e.g. 10.0.0.1">
        </div>
        <div class="form-group">
          <label>Destination Port (0 = any)</label>
          <input type="number" name="dst_port" placeholder="e.g. 80" min="0" max="65535" value="0">
        </div>
        <div class="form-group">
          <label>Action</label>
          <select name="action">
            <option value="accept">ACCEPT</option>
            <option value="drop" selected>DROP</option>
            <option value="reject">REJECT</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Comment (optional)</label>
          <input type="text" name="comment" placeholder="e.g. Block SSH from WAN">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:4px">Add Rule</button>
    </form>
  </div>
</div>

<!-- Current Rules -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">🔥 Current nftables Ruleset</div>
    <button class="btn btn-ghost btn-sm" onclick="location.reload()">↻ Refresh</button>
  </div>
  <div style="padding:16px">
    <pre style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px;font-size:12px;color:#8b949e;overflow-x:auto;white-space:pre-wrap;line-height:1.6"><?php
    if ($rulesRaw) {
        // Colorize output
        $lines = explode("\n", htmlspecialchars($rulesRaw));
        foreach ($lines as $line) {
            if (strpos($line, 'accept') !== false) echo '<span style="color:#2ea043">' . $line . '</span>' . "\n";
            elseif (strpos($line, 'drop') !== false) echo '<span style="color:#f85149">' . $line . '</span>' . "\n";
            elseif (strpos($line, 'reject') !== false) echo '<span style="color:#e3b341">' . $line . '</span>' . "\n";
            elseif (strpos($line, 'table') !== false || strpos($line, 'chain') !== false) echo '<span style="color:#58a6ff">' . $line . '</span>' . "\n";
            else echo $line . "\n";
        }
    } else {
        echo 'No rules loaded or nftables not running.';
    }
    ?></pre>
  </div>
</div>

</div></div>
</body></html>
