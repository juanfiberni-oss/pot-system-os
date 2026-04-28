<?php
session_start();

// Already logged in
if (isset($_SESSION['user'])) {
    header('Location: /dashboard.php');
    exit;
}

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$trialMode = false;

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    // CSRF check
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        if ($action === 'trial') {
            // Start trial session
            $_SESSION['user'] = 'admin';
            $_SESSION['role'] = 'admin';
            $_SESSION['trial_mode'] = true;
            header('Location: /dashboard.php');
            exit;
        }

        // Brute force check
        $attempts = $_SESSION['login_attempts'] ?? 0;
        $lockUntil = $_SESSION['lock_until'] ?? 0;

        if ($lockUntil > time()) {
            $wait = ceil(($lockUntil - time()) / 60);
            $error = "Too many failed attempts. Try again in {$wait} minute(s).";
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                $error = 'Username and password are required.';
            } else {
                $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Success - reset attempts
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['user'] = $user['username'];
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];

                    // Check license
                    $lic = $db->query("SELECT status, expires_at FROM license WHERE id=1")->fetch(PDO::FETCH_ASSOC);
                    if ($lic && $lic['status'] === 'trial') {
                        $exp = new DateTime($lic['expires_at']);
                        $now = new DateTime();
                        if ($exp < $now) {
                            header('Location: /activate.php');
                            exit;
                        }
                    }

                    header('Location: /dashboard.php');
                    exit;
                } else {
                    $attempts++;
                    $_SESSION['login_attempts'] = $attempts;
                    if ($attempts >= 5) {
                        $_SESSION['lock_until'] = time() + 900; // 15 min
                        $error = 'Account locked for 15 minutes due to too many failed attempts.';
                    } else {
                        $remaining = 5 - $attempts;
                        $error = "Invalid username or password. {$remaining} attempt(s) remaining.";
                    }
                }
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POT System — Login</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; display:flex; align-items:center; justify-content:center; }
.bg-grid { position:fixed; inset:0; background-image:linear-gradient(#1f2937 1px,transparent 1px),linear-gradient(90deg,#1f2937 1px,transparent 1px); background-size:40px 40px; opacity:0.25; pointer-events:none; }
.container { position:relative; z-index:1; width:100%; max-width:400px; padding:20px; }
.logo-wrap { text-align:center; margin-bottom:28px; }
.logo-icon { width:64px; height:64px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:22px; font-weight:900; color:#fff; margin-bottom:12px; box-shadow:0 0 30px rgba(0,180,216,0.3); }
.logo-title { font-size:24px; font-weight:700; color:#e6edf3; }
.logo-sub { font-size:12px; color:#8b949e; margin-top:4px; }
.card { background:#161b22; border:1px solid #30363d; border-radius:14px; padding:28px; }
.card-title { font-size:16px; font-weight:600; color:#e6edf3; margin-bottom:4px; }
.card-sub { font-size:12px; color:#8b949e; margin-bottom:22px; }
.form-group { margin-bottom:14px; }
label { display:block; font-size:12px; color:#8b949e; margin-bottom:5px; font-weight:500; }
input[type=text], input[type=password] { width:100%; background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:10px 13px; color:#e6edf3; font-size:14px; outline:none; transition:border-color 0.2s; }
input:focus { border-color:#00b4d8; box-shadow:0 0 0 3px rgba(0,180,216,0.1); }
.btn { width:100%; padding:11px; border-radius:8px; border:none; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.2s; }
.btn-primary { background:linear-gradient(135deg,#0077b6,#00b4d8); color:#fff; }
.btn-primary:hover { opacity:0.9; }
.btn-trial { background:#1a2a3a; border:1px solid #1f6feb; color:#58a6ff; margin-top:10px; }
.btn-trial:hover { background:#1f3a5f; }
.divider { text-align:center; color:#8b949e; font-size:12px; margin:14px 0; position:relative; }
.divider::before,.divider::after { content:''; position:absolute; top:50%; width:44%; height:1px; background:#30363d; }
.divider::before { left:0; } .divider::after { right:0; }
.error-box { background:#2a0a0a; border:1px solid #da3633; border-radius:8px; padding:10px 13px; font-size:13px; color:#f85149; margin-bottom:14px; }
.footer-note { text-align:center; font-size:11px; color:#6e7681; margin-top:18px; }
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="container">
  <div class="logo-wrap">
    <div class="logo-icon">POT</div>
    <div class="logo-title">POT System</div>
    <div class="logo-sub">PC-Based Router · Firewall · Billing OS</div>
  </div>
  <div class="card">
    <div class="card-title">Sign In</div>
    <div class="card-sub">Enter your administrator credentials</div>

    <?php if ($error): ?>
    <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" placeholder="admin" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary">Sign In →</button>
    </form>

    <div class="divider">or</div>

    <form method="POST" action="/login.php">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="action" value="trial">
      <button type="submit" class="btn btn-trial">🚀 Start 7-Day Free Trial</button>
    </form>
  </div>
  <div class="footer-note">POT System v1.0.0 · Secure HTTPS · Default: admin / admin</div>
</div>
</body>
</html>
