<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'POT System') ?> — POT System</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0d1117; color:#c9d1d9; min-height:100vh; display:flex; }
.sidebar { width:220px; min-height:100vh; background:#161b22; border-right:1px solid #30363d; display:flex; flex-direction:column; position:fixed; top:0; left:0; z-index:50; }
.sidebar-logo { padding:20px 16px; border-bottom:1px solid #30363d; display:flex; align-items:center; gap:10px; }
.logo-icon { width:38px; height:38px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:900; color:#fff; flex-shrink:0; }
.logo-text { font-size:15px; font-weight:700; color:#e6edf3; }
.logo-sub { font-size:10px; color:#8b949e; }
.nav { flex:1; padding:12px 8px; overflow-y:auto; }
.nav-section { font-size:10px; color:#6e7681; font-weight:600; text-transform:uppercase; letter-spacing:1px; padding:8px 8px 4px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:8px; cursor:pointer; font-size:13px; color:#8b949e; transition:all 0.15s; margin-bottom:2px; text-decoration:none; }
.nav-item:hover { background:#21262d; color:#c9d1d9; }
.nav-item.active { background:#1f3a5f; color:#58a6ff; }
.nav-item .icon { font-size:15px; width:18px; text-align:center; }
.sidebar-footer { padding:12px 16px; border-top:1px solid #30363d; font-size:11px; color:#6e7681; }
.main { margin-left:220px; flex:1; display:flex; flex-direction:column; min-height:100vh; }
.topbar { background:#161b22; border-bottom:1px solid #30363d; padding:0 24px; height:56px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:40; }
.topbar-title { font-size:16px; font-weight:600; color:#e6edf3; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.badge-trial { background:#1a2a3a; border:1px solid #1f6feb; color:#58a6ff; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; }
.badge-trial.warn { background:#2a1a0a; border-color:#d29922; color:#e3b341; }
.badge-trial.danger { background:#2a0a0a; border-color:#f85149; color:#f85149; }
.badge-trial.licensed { background:#0d2a1a; border-color:#238636; color:#2ea043; }
.badge-uptime { background:#0d2137; border:1px solid #1f6feb; color:#8b949e; font-size:11px; padding:4px 10px; border-radius:20px; }
.topbar-user { display:flex; align-items:center; gap:8px; font-size:13px; color:#8b949e; }
.avatar { width:30px; height:30px; background:linear-gradient(135deg,#00b4d8,#0077b6); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#fff; }
.content { padding:20px 24px; flex:1; }
.panel { background:#161b22; border:1px solid #30363d; border-radius:10px; margin-bottom:20px; }
.panel-header { padding:14px 18px; border-bottom:1px solid #30363d; display:flex; align-items:center; justify-content:space-between; }
.panel-title { font-size:14px; font-weight:600; color:#e6edf3; display:flex; align-items:center; gap:8px; }
.panel-badge { font-size:11px; background:#21262d; border:1px solid #30363d; color:#8b949e; padding:2px 8px; border-radius:10px; }
.panel-body { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { padding:10px 14px; text-align:left; font-size:11px; color:#8b949e; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #30363d; background:#0d1117; }
td { padding:10px 14px; border-bottom:1px solid #21262d; color:#c9d1d9; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:#1c2128; }
.status-up { color:#2ea043; font-size:12px; font-weight:600; }
.status-down { color:#f85149; font-size:12px; font-weight:600; }
.status-paid { color:#2ea043; font-size:12px; font-weight:600; }
.status-overdue { color:#f85149; font-size:12px; font-weight:600; }
.status-pending { color:#e3b341; font-size:12px; font-weight:600; }
.status-suspended { color:#f85149; font-size:12px; font-weight:600; }
.mono { font-family:monospace; font-size:12px; color:#8b949e; }
.btn { padding:7px 16px; border-radius:7px; border:none; font-size:12px; font-weight:600; cursor:pointer; transition:all 0.15s; text-decoration:none; display:inline-block; }
.btn-primary { background:linear-gradient(135deg,#0077b6,#00b4d8); color:#fff; }
.btn-primary:hover { opacity:0.85; }
.btn-danger { background:#da3633; color:#fff; }
.btn-danger:hover { opacity:0.85; }
.btn-warn { background:#d29922; color:#0d1117; }
.btn-warn:hover { opacity:0.85; }
.btn-ghost { background:#21262d; border:1px solid #30363d; color:#c9d1d9; }
.btn-ghost:hover { background:#30363d; }
.btn-green { background:#238636; color:#fff; }
.btn-green:hover { opacity:0.85; }
.btn-sm { padding:4px 10px; font-size:11px; }
.stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
.stat-card { background:#161b22; border:1px solid #30363d; border-radius:10px; padding:16px 18px; }
.stat-label { font-size:11px; color:#8b949e; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
.stat-value { font-size:26px; font-weight:700; color:#e6edf3; line-height:1; }
.stat-sub { font-size:11px; color:#8b949e; margin-top:4px; }
.stat-bar { height:4px; background:#21262d; border-radius:2px; margin-top:10px; overflow:hidden; }
.stat-bar-fill { height:100%; border-radius:2px; }
.bar-blue { background:linear-gradient(90deg,#0077b6,#00b4d8); }
.bar-green { background:linear-gradient(90deg,#238636,#2ea043); }
.bar-orange { background:linear-gradient(90deg,#d29922,#e3b341); }
.bar-purple { background:linear-gradient(90deg,#6e40c9,#a371f7); }
.alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px; }
.alert-success { background:#0d2a1a; border:1px solid #238636; color:#2ea043; }
.alert-error { background:#2a0a0a; border:1px solid #da3633; color:#f85149; }
.alert-warn { background:#2a1a0a; border:1px solid #d29922; color:#e3b341; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; color:#8b949e; margin-bottom:5px; font-weight:500; }
.form-group input, .form-group select, .form-group textarea {
  width:100%; background:#0d1117; border:1px solid #30363d; border-radius:7px;
  padding:9px 12px; color:#e6edf3; font-size:13px; outline:none; font-family:inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  border-color:#00b4d8; box-shadow:0 0 0 3px rgba(0,180,216,0.1);
}
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal { background:#161b22; border:1px solid #30363d; border-radius:14px; padding:28px; width:100%; max-width:460px; }
.modal-title { font-size:16px; font-weight:700; color:#e6edf3; margin-bottom:4px; }
.modal-sub { font-size:12px; color:#8b949e; margin-bottom:20px; }
.modal-close { float:right; background:none; border:none; color:#8b949e; font-size:20px; cursor:pointer; margin-top:-4px; }
.modal-close:hover { color:#e6edf3; }
.row-overdue td { background:#1a0808 !important; }
.row-suspended td { background:#1a0808 !important; }
.trial-banner { border-radius:10px; padding:12px 18px; display:flex; align-items:center; gap:14px; margin-bottom:20px; font-size:13px; }
.trial-banner.ok { background:#0d2137; border:1px solid #1f6feb; }
.trial-banner.warn { background:#2a1a0a; border:1px solid #d29922; }
.trial-banner.danger { background:#2a0a0a; border:1px solid #f85149; }
@media(max-width:900px){ .stats-grid{grid-template-columns:repeat(2,1fr);} }
</style>
</head>
<body>
