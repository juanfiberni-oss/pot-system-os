<?php
/**
 * POT System - Payment Portal
 * Subscriber self-service payment page
 * Supports GCash, Maya via PayMongo
 */
session_start();

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$success = '';
$invoice = null;
$client = null;

// Get invoice from URL or session
$invoiceId = (int)($_GET['invoice'] ?? $_SESSION['pay_invoice'] ?? 0);
$clientId  = (int)($_GET['client'] ?? 0);

if ($invoiceId) {
    $stmt = $db->prepare("
        SELECT i.*, c.name, c.email, c.phone, c.connection_type, c.username, c.plan
        FROM billing_invoices i
        JOIN billing_clients c ON c.id = i.client_id
        WHERE i.id = ? AND i.status IN ('pending','overdue')
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle payment initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_method'])) {
    $method = $_POST['pay_method']; // gcash or maya
    $invId  = (int)$_POST['invoice_id'];

    $stmt = $db->prepare("
        SELECT i.*, c.name, c.email, c.phone, c.connection_type, c.username
        FROM billing_invoices i
        JOIN billing_clients c ON c.id = i.client_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($inv) {
        // Get PayMongo config
        $cfg = $db->query("SELECT key, value FROM system_config WHERE key LIKE 'paymongo_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $secretKey = $cfg['paymongo_secret_key'] ?? '';

        if (empty($secretKey)) {
            $error = 'Payment gateway not configured. Please contact your ISP.';
        } else {
            // Create PayMongo payment link
            $payload = [
                'data' => [
                    'attributes' => [
                        'amount'      => (int)($inv['amount'] * 100), // centavos
                        'currency'    => 'PHP',
                        'description' => 'POT System - Internet Bill for ' . $inv['name'],
                        'remarks'     => 'Invoice #' . $invId,
                        'payment_method_types' => [$method === 'gcash' ? 'gcash' : 'paymaya'],
                        'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/payment-success.php?invoice=' . $invId . '&token=' . hash('sha256', $invId . $secretKey),
                        'cancel_url'  => 'https://' . $_SERVER['HTTP_HOST'] . '/payment.php?invoice=' . $invId,
                        'metadata'    => [
                            'invoice_id' => $invId,
                            'client'     => $inv['name'],
                            'username'   => $inv['username'],
                        ]
                    ]
                ]
            ];

            $ch = curl_init('https://api.paymongo.com/v1/links');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode($secretKey . ':'),
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($httpCode === 200 && isset($result['data']['attributes']['checkout_url'])) {
                // Save payment link reference
                $db->prepare("UPDATE billing_invoices SET paymongo_ref=? WHERE id=?")
                   ->execute([$result['data']['id'], $invId]);

                // Redirect to payment page
                header('Location: ' . $result['data']['attributes']['checkout_url']);
                exit;
            } else {
                $error = 'Payment gateway error. Please try again. (' . ($result['errors'][0]['detail'] ?? 'Unknown error') . ')';
            }
        }
    }
}

$pageTitle = 'Pay Bill';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POT System — Pay Your Bill</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.container { width:100%; max-width:480px; }
.logo-wrap { text-align:center; margin-bottom:24px; }
.logo-icon { width:56px; height:56px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:20px; font-weight:900; color:#fff; margin-bottom:10px; }
.logo-title { font-size:20px; font-weight:700; color:#e6edf3; }
.logo-sub { font-size:12px; color:#8b949e; }
.card { background:#161b22; border:1px solid #30363d; border-radius:14px; padding:28px; margin-bottom:16px; }
.card-title { font-size:16px; font-weight:700; color:#e6edf3; margin-bottom:16px; }
.info-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #21262d; font-size:13px; }
.info-row:last-child { border-bottom:none; }
.info-row span:first-child { color:#8b949e; }
.info-row span:last-child { color:#e6edf3; font-weight:500; }
.amount-big { text-align:center; padding:20px 0; }
.amount-big .label { font-size:12px; color:#8b949e; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
.amount-big .value { font-size:40px; font-weight:800; color:#f85149; }
.pay-methods { display:flex; flex-direction:column; gap:10px; margin-top:16px; }
.pay-btn { display:flex; align-items:center; gap:14px; padding:14px 18px; border-radius:10px; border:2px solid #30363d; background:#0d1117; cursor:pointer; transition:all 0.2s; width:100%; text-align:left; }
.pay-btn:hover { border-color:#00b4d8; background:#0d2137; }
.pay-btn .pay-icon { font-size:28px; }
.pay-btn .pay-info strong { display:block; color:#e6edf3; font-size:14px; }
.pay-btn .pay-info span { color:#8b949e; font-size:12px; }
.pay-btn .pay-arrow { margin-left:auto; color:#8b949e; font-size:18px; }
.gcash-btn { border-color:#0066cc; }
.gcash-btn:hover { border-color:#0088ff; background:#001a33; }
.maya-btn { border-color:#00a651; }
.maya-btn:hover { border-color:#00cc66; background:#001a0d; }
.alert-error { background:#2a0a0a; border:1px solid #da3633; border-radius:8px; padding:12px 16px; font-size:13px; color:#f85149; margin-bottom:16px; }
.alert-success { background:#0d2a1a; border:1px solid #238636; border-radius:8px; padding:12px 16px; font-size:13px; color:#2ea043; margin-bottom:16px; }
.no-invoice { text-align:center; padding:40px 20px; color:#8b949e; }
.no-invoice .icon { font-size:48px; margin-bottom:16px; }
.footer-note { text-align:center; font-size:11px; color:#6e7681; margin-top:16px; }
.secured { display:flex; align-items:center; justify-content:center; gap:6px; font-size:11px; color:#8b949e; margin-top:8px; }
</style>
</head>
<body>
<div class="container">
  <div class="logo-wrap">
    <div class="logo-icon">POT</div>
    <div class="logo-title">POT System</div>
    <div class="logo-sub">Secure Online Payment</div>
  </div>

  <?php if ($error): ?>
  <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$invoice): ?>
  <div class="card">
    <div class="no-invoice">
      <div class="icon">🔍</div>
      <div style="font-size:16px;color:#e6edf3;margin-bottom:8px">No Invoice Found</div>
      <div>Please contact your ISP for your payment link.</div>
    </div>
  </div>
  <?php else: ?>

  <!-- Invoice Details -->
  <div class="card">
    <div class="card-title">📄 Invoice Details</div>
    <div class="info-row"><span>Subscriber</span><span><?= htmlspecialchars($invoice['name']) ?></span></div>
    <div class="info-row"><span>Plan</span><span><?= htmlspecialchars($invoice['plan'] ?? '—') ?></span></div>
    <div class="info-row"><span>Connection</span><span><?= strtoupper($invoice['connection_type']) ?></span></div>
    <div class="info-row"><span>Due Date</span><span><?= htmlspecialchars($invoice['due_date']) ?></span></div>
    <div class="info-row"><span>Status</span>
      <span style="color:<?= $invoice['status']==='overdue'?'#f85149':'#e3b341' ?>;font-weight:700">
        <?= strtoupper($invoice['status']) ?>
      </span>
    </div>
    <div class="amount-big">
      <div class="label">Amount Due</div>
      <div class="value">₱<?= number_format($invoice['amount'], 2) ?></div>
    </div>
  </div>

  <!-- Payment Methods -->
  <div class="card">
    <div class="card-title">💳 Choose Payment Method</div>
    <form method="POST" action="/payment.php">
      <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
      <div class="pay-methods">
        <button type="submit" name="pay_method" value="gcash" class="pay-btn gcash-btn">
          <span class="pay-icon">💙</span>
          <div class="pay-info">
            <strong>GCash</strong>
            <span>Pay using your GCash wallet</span>
          </div>
          <span class="pay-arrow">→</span>
        </button>
        <button type="submit" name="pay_method" value="maya" class="pay-btn maya-btn">
          <span class="pay-icon">💚</span>
          <div class="pay-info">
            <strong>Maya (PayMaya)</strong>
            <span>Pay using your Maya wallet</span>
          </div>
          <span class="pay-arrow">→</span>
        </button>
      </div>
    </form>
  </div>

  <?php endif; ?>

  <div class="footer-note">
    Powered by PayMongo · Secure Payment Processing
  </div>
  <div class="secured">🔒 SSL Encrypted · Your payment is safe</div>
</div>
</body>
</html>
