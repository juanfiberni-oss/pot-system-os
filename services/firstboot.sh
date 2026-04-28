#!/bin/bash
# POT System - First Boot Setup
set -e

LOG="/var/log/pot-firstboot.log"
exec > >(tee -a "$LOG") 2>&1

echo "============================================"
echo " POT System First Boot - $(date)"
echo "============================================"

DB="/var/lib/pot-system/pot.db"

# ── Detect network interfaces ────────────────────────────────
echo "[1/6] Detecting network interfaces..."
INTERFACES=($(ip link show | grep -E '^[0-9]+: (eth|en|usb)' | awk -F': ' '{print $2}' | tr -d '@*'))
echo "Found interfaces: ${INTERFACES[*]}"

WAN_IF="${INTERFACES[0]:-eth0}"
LAN_IF="${INTERFACES[1]:-eth1}"

echo "WAN: $WAN_IF | LAN: $LAN_IF"
sqlite3 "$DB" "UPDATE system_config SET value='$WAN_IF' WHERE key='wan_interface';"
sqlite3 "$DB" "UPDATE system_config SET value='$LAN_IF' WHERE key='lan_interface';"

# ── Configure LAN interface ──────────────────────────────────
echo "[2/6] Configuring LAN interface ($LAN_IF)..."
cat > /etc/network/interfaces.d/pot-lan << EOF
auto $LAN_IF
iface $LAN_IF inet static
  address 192.168.10.1
  netmask 255.255.255.0
EOF

ip addr add 192.168.10.1/24 dev "$LAN_IF" 2>/dev/null || true
ip link set "$LAN_IF" up 2>/dev/null || true

# ── Configure WAN (DHCP by default) ─────────────────────────
echo "[3/6] Configuring WAN interface ($WAN_IF)..."
cat > /etc/network/interfaces.d/pot-wan << EOF
auto $WAN_IF
iface $WAN_IF inet dhcp
EOF

# ── Configure dnsmasq (DHCP + DNS) ──────────────────────────
echo "[4/6] Configuring DHCP/DNS..."
cat > /etc/dnsmasq.d/pot-system.conf << EOF
# POT System DHCP/DNS
interface=$LAN_IF
bind-interfaces
dhcp-range=192.168.10.100,192.168.10.254,24h
dhcp-option=3,192.168.10.1
dhcp-option=6,192.168.10.1
server=1.1.1.1
server=8.8.8.8
cache-size=1000
log-queries
log-facility=/var/log/dnsmasq.log
EOF

# ── Update nftables with correct interfaces ──────────────────
echo "[5/6] Updating firewall rules..."
sed -i "s/iif eth1 oif eth0/iif $LAN_IF oif $WAN_IF/g" /etc/nftables.conf
sed -i "s/oif eth0 masquerade/oif $WAN_IF masquerade/g" /etc/nftables.conf
sed -i "s/udp dport 67/udp dport 67/g" /etc/nftables.conf

# ── Update accel-ppp with correct interface ──────────────────
echo "[6/6] Updating accel-ppp config..."
sed -i "s/interface=eth1/interface=$LAN_IF/g" /etc/accel-ppp/accel-ppp.conf

# ── Start services ───────────────────────────────────────────
echo "[DONE] Starting services..."
systemctl restart networking 2>/dev/null || true
systemctl restart nftables
systemctl restart dnsmasq
systemctl restart nginx
systemctl restart php8.2-fpm
systemctl restart accel-ppp 2>/dev/null || true

echo ""
echo "============================================"
echo " POT System Ready!"
echo " Web UI: https://192.168.1.1"
echo " Username: admin | Password: admin"
echo " SSH: ssh admin@192.168.1.1"
echo "============================================"
