<?php
// Sidebar partial - include at top of each page
// Requires: $pageTitle (string), $activePage (string)
$currentPage = basename($_SERVER['PHP_SELF']);

// Get trial info
$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$lic = $db->query("SELECT type, status, expires_at FROM license WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$trialDays = 0;
$isLicensed = false;
if ($lic) {
    if ($lic['status'] === 'licensed') {
        $isLicensed = true;
    } else {
        $exp = new DateTime($lic['expires_at']);
        $now = new DateTime();
        $trialDays = max(0, (int)$now->diff($exp)->days * ($exp > $now ? 1 : -1));
    }
}
?>
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">POT</div>
    <div>
      <div class="logo-text">POT System</div>
      <div class="logo-sub">Router · Firewall · Billing</div>
    </div>
  </div>
  <nav class="nav">
    <div class="nav-section">Main</div>
    <a class="nav-item <?= $currentPage==='dashboard.php'?'active':'' ?>" href="/dashboard.php"><span class="icon">📊</span> Dashboard</a>
    <a class="nav-item <?= $currentPage==='pppoe.php'?'active':'' ?>" href="/pppoe.php"><span class="icon">🌐</span> PPPoE Sessions</a>
    <a class="nav-item <?= $currentPage==='ipoe.php'?'active':'' ?>" href="/ipoe.php"><span class="icon">🔗</span> IPoE Sessions</a>
    <a class="nav-item <?= $currentPage==='interfaces.php'?'active':'' ?>" href="/interfaces.php"><span class="icon">📡</span> WAN / Interfaces</a>
    <div class="nav-section">Billing</div>
    <a class="nav-item <?= $currentPage==='clients.php'?'active':'' ?>" href="/clients.php"><span class="icon">💳</span> Clients</a>
    <a class="nav-item <?= $currentPage==='invoices.php'?'active':'' ?>" href="/invoices.php"><span class="icon">📄</span> Invoices</a>
    <a class="nav-item <?= $currentPage==='reminders.php'?'active':'' ?>" href="/reminders.php"><span class="icon">📬</span> Reminders</a>
    <div class="nav-section">System</div>
    <a class="nav-item <?= $currentPage==='libreqos.php'?'active':'' ?>" href="/libreqos.php"><span class="icon">⚡</span> LibreQoS</a>
    <a class="nav-item <?= $currentPage==='firewall.php'?'active':'' ?>" href="/firewall.php"><span class="icon">🔥</span> Firewall</a>
    <a class="nav-item <?= $currentPage==='vpn.php'?'active':'' ?>" href="/vpn.php"><span class="icon">🔒</span> VPN</a>
    <a class="nav-item <?= $currentPage==='logs.php'?'active':'' ?>" href="/logs.php"><span class="icon">📋</span> Logs</a>
    <a class="nav-item <?= $currentPage==='keygen.php'?'active':'' ?>" href="/keygen.php"><span class="icon">🔑</span> Key Generator</a>
    <a class="nav-item <?= $currentPage==='settings.php'?'active':'' ?>" href="/settings.php"><span class="icon">⚙️</span> Settings</a>
    <a class="nav-item" href="/logout.php"><span class="icon">🚪</span> Logout</a>
  </nav>
  <div class="sidebar-footer">
    <?php if ($isLicensed): ?>
      <span style="color:#2ea043">✓ Licensed</span>
    <?php elseif ($trialDays > 0): ?>
      <span style="color:#e3b341">⏳ Trial: <?= $trialDays ?> days left</span>
    <?php else: ?>
      <span style="color:#f85149">⚠ Trial Expired</span>
    <?php endif; ?>
    · v1.0.0
  </div>
</aside>
