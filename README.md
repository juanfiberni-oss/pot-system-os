# POT System — PC-Based Router / Firewall / Billing OS

> A MikroTik-like operating system built on Debian Linux for standard PC hardware.  
> Supports PPPoE, IPoE, LibreQoS, built-in billing with auto-cut, and a web management UI.

---

## Features

| Feature | Description |
|---|---|
| 🌐 PPPoE Server | Full PPPoE server via accel-ppp, supports unlimited sessions |
| 🔗 IPoE Server | IP-over-Ethernet sessions with DHCP binding |
| ⚡ LibreQoS | Built-in bandwidth shaping per user (CAKE algorithm) |
| 🛡️ Firewall | nftables-based stateful firewall with web UI rule management |
| 🔄 NAT | Masquerade + port forwarding |
| 📡 DHCP/DNS | dnsmasq with static leases and DNS caching |
| 🗺️ Routing | FRRouting (OSPF, BGP, static routes) |
| 🔒 VPN | WireGuard, OpenVPN, IPsec (strongSwan) |
| 💳 Billing | Built-in client billing with auto-cut and payment reminders |
| 📬 Reminders | Auto SMS/Email reminders for overdue accounts |
| ✂️ Auto-Cut | Automatic disconnection after 3-day grace period |
| 🔑 License | 7-day free trial + activation key system (1 key = 1 device) |
| 🖥️ Web UI | HTTPS web management interface (PHP + SQLite) |
| 💻 SSH CLI | Full SSH access for advanced configuration |
| 📋 Logs | Centralized log viewer in web UI |
| 🌐 WAN Failover | Multi-WAN with automatic failover |

---

## Hardware Requirements

| Component | Minimum | Recommended |
|---|---|---|
| CPU | x86_64, 2 cores | 4+ cores (Intel/AMD) |
| RAM | 2 GB | 4–8 GB |
| Storage | 16 GB SSD | 32–64 GB SSD |
| NIC 1 (WAN) | PCI/PCIe LAN card | Intel i210/i350 |
| NIC 2 (LAN) | PCI/PCIe or USB-to-LAN | Realtek RTL8153 USB |
| Additional NICs | USB-to-LAN adapters | Up to 8 interfaces |

---

## Build ISO via GitHub Actions

### 1. Fork / Clone this repository

```bash
git clone https://github.com/YOUR_USERNAME/pot-system-os.git
cd pot-system-os
```

### 2. Push to GitHub

```bash
git add .
git commit -m "Initial POT System build"
git push origin main
```

### 3. GitHub Actions will automatically build the ISO

- Go to your repo → **Actions** tab
- Watch the **Build POT System ISO** workflow run
- When complete, download the `.iso` from **Artifacts**

### 4. Create a Release (optional)

```bash
git tag v1.0.0
git push origin v1.0.0
```

This triggers a GitHub Release with the ISO attached for download.

---

## Flash to SSD with Rufus

1. Download the `.iso` file from GitHub Actions artifacts or Releases
2. Connect your SSD via USB enclosure
3. Open **Rufus** (Windows)
4. Settings:
   - **Device**: Select your SSD
   - **Boot selection**: Select the `.iso` file
   - **Partition scheme**: GPT
   - **Target system**: UEFI (non-CSM)
   - **File system**: FAT32
5. Click **START**
6. When prompted: choose **"Write in DD Image mode"**
7. Wait for completion (~5–10 minutes)

---

## First Boot

1. Install the SSD into your PC
2. Boot from the SSD (set in BIOS/UEFI boot order)
3. POT System will auto-detect your network interfaces
4. First-boot setup runs automatically (~30 seconds)
5. Connect a PC to the LAN port (eth1 / second NIC)
6. Open browser: **https://192.168.1.1**
7. Accept the self-signed SSL certificate warning
8. Login with default credentials

---

## Default Credentials

| Service | URL / Address | Username | Password |
|---|---|---|---|
| Web UI | https://192.168.1.1 | admin | admin |
| SSH | ssh admin@192.168.1.1 | admin | admin |

> ⚠️ **Change the default password immediately after first login!**

---

## Trial & Licensing

- **Free Trial**: 7 days full access, no key required
- **After trial**: Enter a 15-digit activation key (`XXXXX-XXXXX-XXXXX`)
- **Key types**: Trial (7d), Standard (1 year), Lifetime
- **1 Key = 1 Device**: Each key is bound to the machine's hardware fingerprint
- **Key Generator**: Available at `https://192.168.1.1/keygen.php` (admin only)

---

## Directory Structure

```
pot-system-os/
├── .github/
│   └── workflows/
│       └── build-iso.yml          # GitHub Actions ISO build pipeline
├── config/
│   ├── packages.list.chroot       # Debian packages to install
│   └── hooks/
│       ├── 10-accel-ppp.hook.chroot   # Build accel-ppp (PPPoE/IPoE)
│       ├── 20-libreqos.hook.chroot    # Install LibreQoS
│       └── 30-pot-system.hook.chroot  # Configure all services
├── pot-system/
│   ├── webui/                     # PHP web management interface
│   │   ├── login.php
│   │   ├── dashboard.php
│   │   ├── firewall.php
│   │   ├── clients.php
│   │   ├── keygen.php
│   │   ├── activate.php
│   │   ├── logs.php
│   │   └── partials/              # Reusable UI components
│   ├── api/
│   │   └── api.php                # REST API backend
│   └── scripts/
│       ├── firstboot.sh           # First-boot auto-configuration
│       ├── billing-check.sh       # Hourly billing + auto-cut
│       ├── send-reminders.sh      # Daily payment reminders
│       └── send-reminder-single.sh
└── README.md
```

---

## Network Topology

```
Internet
    │
  [WAN] eth0 / usb0 (DHCP or PPPoE from ISP)
    │
 POT System PC
    │
  [LAN] eth1 / usb1 (192.168.1.1/24)
    │
  Switch
  ├── PC clients (IPoE / DHCP)
  └── PPPoE clients (DSL modems / routers in bridge mode)
```

---

## Billing Auto-Cut Flow

```
Invoice Created → Due Date Arrives
    → Day 1-2 overdue: Send SMS/Email reminder
    → Day 3+ overdue:  Auto-cut connection (PPPoE terminate / IPoE nftables block)
    → Client pays:     Admin clicks "Reconnect" in web UI
```

---

## License

MIT License — Free to use, modify, and distribute.  
Built with: Debian Linux, accel-ppp, LibreQoS, FRRouting, nftables, dnsmasq, nginx, PHP, SQLite.
