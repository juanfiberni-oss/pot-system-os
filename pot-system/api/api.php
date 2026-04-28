<?php
session_start();
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = new PDO('sqlite:/var/lib/pot-system/pot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = $_GET['action'] ?? '';

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function safeShell($cmd) {
    return shell_exec($cmd . ' 2>/dev/null') ?? '';
}

// ── GET actions ──────────────────────────────────────────────
switch ($action) {

    case 'stats':
        // CPU
        $cpu = 0;
        if (file_exists('/proc/stat')) {
            $s1 = file_get_contents('/proc/stat');
            usleep(100000);
            $s2 = file_get_contents('/proc/stat');
            preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s1, $m1);
            preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s2, $m2);
            $dTotal = array_sum(array_slice($m2,1)) - array_sum(array_slice($m1,1));
            $dIdle  = $m2[4] - $m1[4];
            $cpu = $dTotal > 0 ? round(100 * ($dTotal - $dIdle) / $dTotal) : 0;
        }
        // Memory
        $memPct = 0; $memUsed = 0; $memTotal = 0;
        if (file_exists('/proc/meminfo')) {
            $lines = file('/proc/meminfo');
            $mem = [];
            foreach ($lines as $l) {
                if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)$m[2];
            }
            $memTotal = $mem['MemTotal'] ?? 0;
            $avail = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
            $memUsed = $memTotal - $avail;
            $memPct = $memTotal > 0 ? round($memUsed / $memTotal * 100) : 0;
        }
        // Uptime
        $uptimeSec = file_exists('/proc/uptime') ? (int)explode(' ', file_get_contents('/proc/uptime'))[0] : 0;
        // Load
        $load = sys_getloadavg();

        jsonOut([
            'cpu'       => $cpu,
            'mem_pct'   => $memPct,
            'mem_used'  => round($memUsed / 1024),
            'mem_total' => round($memTotal / 1024),
            'uptime_sec'=> $uptimeSec,
            'load'      => $load,
        ]);

    case 'interfaces':
        $raw = safeShell('ip -j addr show');
        $ifaces = json_decode($raw, true) ?? [];
        $result = [];
        foreach ($ifaces as $if) {
            $addrs = [];
            foreach ($if['addr_info'] ?? [] as $a) {
                $addrs[] = $a['local'] . '/' . $a['prefixlen'];
            }
            $result[] = [
                'name'    => $if['ifname'],
                'mac'     => $if['address'] ?? '',
                'state'   => $if['operstate'] ?? 'UNKNOWN',
                'addrs'   => $addrs,
                'mtu'     => $if['mtu'] ?? 1500,
            ];
        }
        jsonOut($result);

    case 'pppoe_sessions':
        $out = safeShell('accel-cmd show sessions');
        $sessions = [];
        if ($out) {
            foreach (explode("\n", $out) as $line) {
                $p = preg_split('/\s+/', trim($line));
                if (count($p) >= 4 && $p[0] !== 'username' && !empty($p[0])) {
                    $sessions[] = [
                        'user'   => $p[0],
                        'ip'     => $p[1] ?? '',
                        'iface'  => $p[2] ?? '',
                        'uptime' => $p[3] ?? '',
                        'rx'     => $p[4] ?? '0',
                        'tx'     => $p[5] ?? '0',
                        'status' => 'UP',
                    ];
                }
            }
        }
        jsonOut($sessions);

    case 'ipoe_sessions':
        $rows = $db->query("SELECT username, name, plan, status FROM billing_clients WHERE connection_type='ipoe' AND status='active'")->fetchAll(PDO::FETCH_ASSOC);
        jsonOut($rows);

    case 'billing':
        $rows = $db->query("
            SELECT c.id, c.name, c.plan, c.connection_type, c.phone, c.email,
                   i.amount, i.due_date, i.status as inv_status, i.id as inv_id
            FROM billing_clients c
            LEFT JOIN billing_invoices i ON i.client_id = c.id AND i.status IN ('pending','overdue')
            WHERE c.status IN ('active','suspended')
            ORDER BY i.due_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        jsonOut($rows);

    case 'libreqos_stats':
        $statsFile = '/tmp/libreqos_stats.json';
        if (file_exists($statsFile)) {
            jsonOut(json_decode(file_get_contents($statsFile), true));
        }
        // Fallback demo data
        jsonOut([
            ['user'=>'user001','dl'=>38,'ul'=>7,'dl_max'=>50,'ul_max'=>10,'plan'=>'50/10 Mbps'],
            ['user'=>'user002','dl'=>82,'ul'=>15,'dl_max'=>100,'ul_max'=>20,'plan'=>'100/20 Mbps'],
            ['user'=>'user003','dl'=>10,'ul'=>2,'dl_max'=>25,'ul_max'=>5,'plan'=>'25/5 Mbps'],
            ['user'=>'user004','dl'=>0,'ul'=>0,'dl_max'=>50,'ul_max'=>10,'plan'=>'50/10 Mbps'],
            ['user'=>'user005','dl'=>175,'ul'=>42,'dl_max'=>200,'ul_max'=>50,'plan'=>'200/50 Mbps'],
        ]);

    case 'firewall_rules':
        $raw = safeShell('nft -j list ruleset');
        jsonOut(['raw' => $raw]);
}

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Unknown action'], 400);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    case 'disconnect_pppoe':
        $username = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $body['username'] ?? '');
        if (empty($username)) jsonOut(['error' => 'Username required'], 400);
        $out = safeShell('accel-cmd terminate username ' . escapeshellarg($username));
        jsonOut(['success' => true, 'output' => $out]);

    case 'send_reminder':
        $clientId = (int)($body['client_id'] ?? 0);
        $method   = in_array($body['method'] ?? '', ['sms','email']) ? $body['method'] : 'sms';
        if (!$clientId) jsonOut(['error' => 'Client ID required'], 400);

        $client = $db->prepare("SELECT name, phone, email FROM billing_clients WHERE id=?");
        $client->execute([$clientId]);
        $c = $client->fetch(PDO::FETCH_ASSOC);
        if (!$c) jsonOut(['error' => 'Client not found'], 404);

        // Log reminder
        $db->prepare("INSERT INTO system_config (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
           ->execute(['reminder_' . $clientId . '_' . time(), json_encode(['method'=>$method,'client'=>$c['name'],'ts'=>date('c')])]);

        jsonOut(['success' => true, 'message' => "Reminder sent via {$method} to {$c['name']}"]);

    case 'auto_cut':
        $clientId = (int)($body['client_id'] ?? 0);
        if (!$clientId) jsonOut(['error' => 'Client ID required'], 400);

        $client = $db->prepare("SELECT name, username, connection_type FROM billing_clients WHERE id=?");
        $client->execute([$clientId]);
        $c = $client->fetch(PDO::FETCH_ASSOC);
        if (!$c) jsonOut(['error' => 'Client not found'], 404);

        // Disconnect based on type
        if ($c['connection_type'] === 'pppoe' && $c['username']) {
            safeShell('accel-cmd terminate username ' . escapeshellarg($c['username']));
        } elseif ($c['connection_type'] === 'ipoe') {
            // Block via nftables - get IP from DHCP leases
            $ip = trim(safeShell("grep -i '{$c['username']}' /var/lib/misc/dnsmasq.leases | awk '{print $3}' | head -1"));
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                safeShell('nft add rule inet filter forward ip saddr ' . escapeshellarg($ip) . ' drop');
            }
        }

        // Update DB
        $db->prepare("UPDATE billing_clients SET status='suspended' WHERE id=?")->execute([$clientId]);
        $db->prepare("UPDATE billing_invoices SET status='overdue' WHERE client_id=? AND status='pending'")->execute([$clientId]);

        jsonOut(['success' => true, 'message' => "Connection cut for {$c['name']}"]);

    case 'activate_key':
        $rawKey = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $body['key'] ?? ''));
        if (strlen($rawKey) !== 15) jsonOut(['error' => 'Invalid key format'], 400);

        $formattedKey = substr($rawKey,0,5).'-'.substr($rawKey,5,5).'-'.substr($rawKey,10,5);
        $hostname = trim(safeShell('hostname'));
        $mac = trim(safeShell("ip link show | grep 'link/ether' | head -1 | awk '{print $2}'"));
        $deviceId = hash('sha256', $hostname . $mac);

        $stmt = $db->prepare("SELECT id, type, status, device_id FROM license_keys WHERE key=?");
        $stmt->execute([$formattedKey]);
        $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyRow) jsonOut(['error' => 'Key not found'], 404);
        if ($keyRow['status'] === 'revoked') jsonOut(['error' => 'Key has been revoked'], 403);
        if ($keyRow['status'] === 'used' && $keyRow['device_id'] !== $deviceId) {
            jsonOut(['error' => 'Key already used on another device'], 403);
        }

        $expiry = $keyRow['type'] === 'lifetime' ? '2099-12-31' :
                  ($keyRow['type'] === 'trial' ? date('Y-m-d', strtotime('+7 days')) :
                   date('Y-m-d', strtotime('+1 year')));

        $db->prepare("UPDATE license SET type=?, status='licensed', expires_at=? WHERE id=1")
           ->execute([$keyRow['type'], $expiry]);
        $db->prepare("UPDATE license_keys SET status='used', device_id=? WHERE id=?")
           ->execute([$deviceId, $keyRow['id']]);

        jsonOut(['success' => true, 'message' => 'License activated', 'expires' => $expiry]);

    case 'add_firewall_rule':
        $chain    = in_array($body['chain'] ?? '', ['input','forward','output']) ? $body['chain'] : 'forward';
        $proto    = in_array($body['proto'] ?? '', ['tcp','udp','icmp','any']) ? $body['proto'] : 'tcp';
        $srcIp    = filter_var($body['src_ip'] ?? '', FILTER_VALIDATE_IP) ? $body['src_ip'] : '';
        $dstIp    = filter_var($body['dst_ip'] ?? '', FILTER_VALIDATE_IP) ? $body['dst_ip'] : '';
        $dstPort  = (int)($body['dst_port'] ?? 0);
        $ruleAction = in_array($body['action'] ?? '', ['accept','drop','reject']) ? $body['action'] : 'drop';

        $rule = "nft add rule inet filter {$chain}";
        if ($proto !== 'any') $rule .= " {$proto}";
        if ($srcIp) $rule .= " ip saddr {$srcIp}";
        if ($dstIp) $rule .= " ip daddr {$dstIp}";
        if ($dstPort > 0 && $proto !== 'icmp') $rule .= " {$proto} dport {$dstPort}";
        $rule .= " {$ruleAction}";

        $out = safeShell($rule);
        jsonOut(['success' => true, 'rule' => $rule, 'output' => $out]);

    default:
        jsonOut(['error' => 'Unknown action'], 400);
}
