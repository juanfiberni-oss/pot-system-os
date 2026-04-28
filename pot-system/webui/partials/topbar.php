<?php
// Topbar partial
// Requires: $pageTitle (string)
// Reads uptime from /proc/uptime
$uptimeSec = 0;
if (file_exists('/proc/uptime')) {
    $uptimeSec = (int)explode(' ', file_get_contents('/proc/uptime'))[0];
}
$days = floor($uptimeSec / 86400);
$hours = floor(($uptimeSec % 86400) / 3600);
$mins = floor(($uptimeSec % 3600) / 60);
$uptimeStr = "{$days}d {$hours}h {$mins}m";

// Trial badge
$db2 = new PDO('sqlite:/var/lib/pot-system/pot.db');
$lic2 = $db2->query("SELECT type, status, expires_at FROM license WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$trialBadge = '';
if ($lic2) {
    if ($lic2['status'] === 'licensed') {
        $trialBadge = '<span class="badge-trial licensed">✓ Licensed</span>';
    } else {
        $exp2 = new DateTime($lic2['expires_at']);
        $now2 = new DateTime();
        $td = (int)$now2->diff($exp2)->days * ($exp2 > $now2 ? 1 : -1);
        $cls = $td <= 2 ? 'danger' : ($td <= 4 ? 'warn' : '');
        $trialBadge = "<span class=\"badge-trial {$cls}\">⏳ Trial: {$td} days left</span>";
    }
}
?>
<div class="topbar">
  <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></div>
  <div class="topbar-right">
    <span class="badge-uptime">⏱ <?= $uptimeStr ?></span>
    <?= $trialBadge ?>
    <div class="topbar-user">
      <div class="avatar"><?= strtoupper(substr($_SESSION['user'] ?? 'A', 0, 2)) ?></div>
      <span><?= htmlspecialchars($_SESSION['user'] ?? 'admin') ?></span>
    </div>
  </div>
</div>
