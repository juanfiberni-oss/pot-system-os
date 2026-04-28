<?php
/**
 * POT System - Payment Success Handler
 * Called after successful GCash/Maya payment
 */
session_start();

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$invoiceId = (int)($_GET['invoice'] ?? 0);
$token     = $_GET['token'] ?? '';

$cfg = $db->query("SELECT key, value FROM system_config WHERE key LIKE 'paymongo_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$secretKey = $cfg['paymongo_secret_key'] ?? '';

$valid = hash('sha256', $invoiceId . $secretKey) === $token;
$reconnected = false;
$clientName = '';

if ($valid && $invoiceId) {
    // Get invoice and client
    $stmt = $db->prepare("
        SELECT i.*, c.name, c.username, c.connection_type
        FROM billing_invoices i
        JOIN billing_clients c ON c.id = i.client_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($inv) {
        $clientName = $inv['name'];

        // Mark invoice as paid
        $db->prepare("UPDATE billing_invoices SET status='paid', paid_date=date('now') WHERE id=?")
           ->execute([$invoiceId]);

        // Reactivate client
        $db->prepare("UPDATE billing_clients SET status='active' WHERE username=?")
           ->execute([$inv['username']]);

        // Reconnect based on connection type
        if ($inv['connection_type'] === 'pppoe' && $inv['username']) {
            // PPPoE - client will reconnect automatically when they retry
            // Remove any blocks
            shell_exec('accel-cmd unblock username ' . escapeshellarg($inv['username']) . ' 2>/dev/null');
        } elseif ($inv['connection_type'] === 'ipoe') {
            // IPoE - remove nftables block
            $ip = trim(shell_exec("grep -i '{$inv['username']}' /var/lib/misc/dnsmasq.leases 2>/dev/null | awk '{print $3}' | head -1") ?? '');
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                shell_exec('nft delete rule inet filter forward ip saddr ' . escapeshellarg($ip) . ' drop 2>/dev/null');
            }
        }

        $reconnected = true;

        // Log payment
        $db->prepare("INSERT OR IGNORE INTO system_config (key, value) VALUES (?, ?)")
           ->execute(['payment_' . time(), json_encode([
               'invoice' => $invoiceId,
               'client'  => $inv['name'],
               'amount'  => $inv['amount'],
               'method'  => 'online',
               'ts'      => date('c'),
           ])]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Successful — POT System</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.container { width:100%; max-width:420px; text-align:center; }
.success-icon { font-size:72px; margin-bottom:20px; animation:bounce 0.5s ease; }
@keyframes bounce { 0%{transform:scale(0)} 70%{transform:scale(1.1)} 100%{transform:scale(1)} }
.card { background:#161b22; border:1px solid #238636; border-radius:14px; padding:32px; }
.title { font-size:22px; font-weight:700; color:#2ea043; margin-bottom:8px; }
.sub { font-size:14px; color:#8b949e; margin-bottom:20px; line-height:1.6; }
.info-box { background:#0d2a1a; border:1px solid #238636; border-radius:8px; padding:14px; margin-bottom:20px; font-size:13px; }
.info-box strong { color:#e6edf3; display:block; font-size:16px; margin-bottom:4px; }
.btn { display:inline-block; padding:11px 24px; border-radius:8px; background:linear-gradient(135deg,#238636,#2ea043); color:#fff; font-size:14px; font-weight:600; text-decoration:none; margin-top:8px; }
.reconnect-note { background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:12px; font-size:12px; color:#8b949e; margin-top:16px; }
</style>
</head>
<body>
<div class="container">
  <?php if ($reconnected): ?>
  <div class="success-icon">✅</div>
  <div class="card">
    <div class="title">Payment Successful!</div>
    <div class="sub">Thank you, <strong style="color:#e6edf3"><?= htmlspecialchars($clientName) ?></strong>!<br>Your internet connection has been restored.</div>
    <div class="info-box">
      <strong>🌐 You're back online!</strong>
      Your connection has been automatically reconnected.
    </div>
    <div class="reconnect-note">
      💡 If you're still not connected, please restart your router/device or wait 1-2 minutes for the connection to restore.
    </div>
  </div>
  <?php else: ?>
  <div class="success-icon">⚠️</div>
  <div class="card" style="border-color:#d29922">
    <div class="title" style="color:#e3b341">Payment Received</div>
    <div class="sub">Your payment was received but we could not automatically verify it. Please contact your ISP to restore your connection.</div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
