<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = ''; $success = '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Load all settings
$settings = $db->query("SELECT key, value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $error = 'Invalid request.';
    } else {
        $section = $_POST['section'] ?? '';

        if ($section === 'paymongo') {
            $keys = ['paymongo_secret_key', 'paymongo_public_key', 'paymongo_webhook_secret'];
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO system_config (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                   ->execute([$k, $val]);
            }
            $success = 'PayMongo settings saved.';
        } elseif ($section === 'sms') {
            $keys = ['sms_provider', 'sms_api_key', 'sms_sender_name'];
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO system_config (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                   ->execute([$k, $val]);
            }
            $success = 'SMS settings saved.';
        } elseif ($section === 'network') {
            $keys = ['hostname', 'wan_interface', 'lan_interface', 'lan_ip', 'dns1', 'dns2'];
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO system_config (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                   ->execute([$k, $val]);
            }
            $success = 'Network settings saved. Reboot to apply changes.';
        } elseif ($section === 'billing') {
            $keys = ['billing_grace_days', 'billing_reminder_days', 'company_name', 'company_address', 'company_phone'];
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO system_config (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                   ->execute([$k, $val]);
            }
            $success = 'Billing settings saved.';
        } elseif ($section === 'password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE username=?");
            $stmt->execute([$_SESSION['user']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($current, $user['password_hash'])) {
                $error = 'Current password is incorrect.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } elseif (strlen($new) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $db->prepare("UPDATE users SET password_hash=? WHERE username=?")->execute([$hash, $_SESSION['user']]);
                $success = 'Password changed successfully.';
            }
        }

        // Reload settings
        $settings = $db->query("SELECT key, value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

$pageTitle = 'Settings';
?>
<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<div class="main">
<?php include 'partials/topbar.php'; ?>
<div class="content">

<?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- PayMongo / GCash / Maya -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header">
    <div class="panel-title">💳 Payment Gateway (GCash / Maya via PayMongo)</div>
  </div>
  <div style="padding:20px">
    <div class="alert alert-warn" style="margin-bottom:16px;font-size:12px">
      📋 <strong>Setup:</strong> Register at <a href="https://paymongo.com" target="_blank" style="color:#58a6ff">paymongo.com</a> →
      Get API keys → Paste below → Set webhook URL to <code style="color:#00b4d8">https://YOUR_DOMAIN/api/api.php?action=paymongo_webhook</code>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="section" value="paymongo">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Secret Key (sk_live_... or sk_test_...)</label>
          <input type="password" name="paymongo_secret_key" value="<?= htmlspecialchars($settings['paymongo_secret_key'] ?? '') ?>" placeholder="sk_live_xxxxxxxxxxxxxxxx">
        </div>
        <div class="form-group">
          <label>Public Key (pk_live_... or pk_test_...)</label>
          <input type="text" name="paymongo_public_key" value="<?= htmlspecialchars($settings['paymongo_public_key'] ?? '') ?>" placeholder="pk_live_xxxxxxxxxxxxxxxx">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Webhook Secret</label>
          <input type="password" name="paymongo_webhook_secret" value="<?= htmlspecialchars($settings['paymongo_webhook_secret'] ?? '') ?>" placeholder="whsk_xxxxxxxxxxxxxxxx">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save PayMongo Settings</button>
    </form>
  </div>
</div>

<!-- SMS Settings -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header">
    <div class="panel-title">📱 SMS Gateway (Payment Reminders)</div>
  </div>
  <div style="padding:20px">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="section" value="sms">
      <div class="form-grid-2">
        <div class="form-group">
          <label>SMS Provider</label>
          <select name="sms_provider" style="background:#0d1117;border:1px solid #30363d;border-radius:7px;padding:9px 12px;color:#e6edf3;font-size:13px;outline:none;width:100%">
            <option value="semaphore" <?= ($settings['sms_provider']??'')==='semaphore'?'selected':'' ?>>Semaphore (PH)</option>
            <option value="vonage" <?= ($settings['sms_provider']??'')==='vonage'?'selected':'' ?>>Vonage</option>
            <option value="twilio" <?= ($settings['sms_provider']??'')==='twilio'?'selected':'' ?>>Twilio</option>
          </select>
        </div>
        <div class="form-group">
          <label>API Key</label>
          <input type="password" name="sms_api_key" value="<?= htmlspecialchars($settings['sms_api_key'] ?? '') ?>" placeholder="Your SMS API key">
        </div>
        <div class="form-group">
          <label>Sender Name</label>
          <input type="text" name="sms_sender_name" value="<?= htmlspecialchars($settings['sms_sender_name'] ?? 'POTSystem') ?>" placeholder="POTSystem" maxlength="11">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save SMS Settings</button>
    </form>
  </div>
</div>

<!-- Billing Settings -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header">
    <div class="panel-title">💰 Billing Configuration</div>
  </div>
  <div style="padding:20px">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="section" value="billing">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Company Name</label>
          <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" placeholder="My ISP Business">
        </div>
        <div class="form-group">
          <label>Company Phone</label>
          <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="form-group">
          <label>Grace Period (days before auto-cut)</label>
          <input type="number" name="billing_grace_days" value="<?= htmlspecialchars($settings['billing_grace_days'] ?? '3') ?>" min="0" max="30">
        </div>
        <div class="form-group">
          <label>Send Reminder (days before due)</label>
          <input type="number" name="billing_reminder_days" value="<?= htmlspecialchars($settings['billing_reminder_days'] ?? '3') ?>" min="1" max="14">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Company Address</label>
          <input type="text" name="company_address" value="<?= htmlspecialchars($settings['company_address'] ?? '') ?>" placeholder="123 Main St, City">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save Billing Settings</button>
    </form>
  </div>
</div>

<!-- Network Settings -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header">
    <div class="panel-title">🌐 Network Configuration</div>
  </div>
  <div style="padding:20px">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="section" value="network">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Hostname</label>
          <input type="text" name="hostname" value="<?= htmlspecialchars($settings['hostname'] ?? 'pot-router') ?>">
        </div>
        <div class="form-group">
          <label>LAN IP Address</label>
          <input type="text" name="lan_ip" value="<?= htmlspecialchars($settings['lan_ip'] ?? '192.168.10.1') ?>">
        </div>
        <div class="form-group">
          <label>WAN Interface</label>
          <input type="text" name="wan_interface" value="<?= htmlspecialchars($settings['wan_interface'] ?? 'eth0') ?>">
        </div>
        <div class="form-group">
          <label>LAN Interface</label>
          <input type="text" name="lan_interface" value="<?= htmlspecialchars($settings['lan_interface'] ?? 'eth1') ?>">
        </div>
        <div class="form-group">
          <label>Primary DNS</label>
          <input type="text" name="dns1" value="<?= htmlspecialchars($settings['dns1'] ?? '1.1.1.1') ?>">
        </div>
        <div class="form-group">
          <label>Secondary DNS</label>
          <input type="text" name="dns2" value="<?= htmlspecialchars($settings['dns2'] ?? '8.8.8.8') ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save Network Settings</button>
    </form>
  </div>
</div>

<!-- Change Password -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">🔒 Change Admin Password</div>
  </div>
  <div style="padding:20px">
    <form method="POST" style="max-width:400px">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="section" value="password">
      <div class="form-group"><label>Current Password</label><input type="password" name="current_password" required></div>
      <div class="form-group"><label>New Password (min 8 chars)</label><input type="password" name="new_password" required minlength="8"></div>
      <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" required></div>
      <button type="submit" class="btn btn-primary">Change Password</button>
    </form>
  </div>
</div>

</div></div>
</body></html>
