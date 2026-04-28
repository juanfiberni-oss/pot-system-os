#!/bin/bash
# POT System - Billing Auto-Cut & Reminder Script
# Runs every hour via cron

DB="/var/lib/pot-system/pot.db"
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [BILLING]"

echo "$LOG_PREFIX Starting billing check..."

# ── Find overdue invoices (past due date, unpaid) ────────────
OVERDUE=$(sqlite3 "$DB" "
  SELECT 
    c.id, c.name, c.username, c.phone, c.email,
    c.connection_type, c.plan, i.amount, i.due_date, i.id as invoice_id
  FROM billing_invoices i
  JOIN billing_clients c ON c.id = i.client_id
  WHERE i.status = 'pending'
    AND i.due_date < date('now')
    AND c.status = 'active'
  ORDER BY i.due_date ASC;
")

if [ -z "$OVERDUE" ]; then
  echo "$LOG_PREFIX No overdue accounts found."
  exit 0
fi

# ── Process each overdue account ────────────────────────────
while IFS='|' read -r client_id name username phone email conn_type plan amount due_date invoice_id; do
  DAYS_OVERDUE=$(( ($(date +%s) - $(date -d "$due_date" +%s)) / 86400 ))
  echo "$LOG_PREFIX Overdue: $name ($username) | $conn_type | Due: $due_date | Days: $DAYS_OVERDUE"

  # Grace period: 3 days before auto-cut
  if [ "$DAYS_OVERDUE" -ge 3 ]; then
    echo "$LOG_PREFIX AUTO-CUT: $name ($username) - $DAYS_OVERDUE days overdue"

    # Disconnect PPPoE session
    if [ "$conn_type" = "pppoe" ] && [ -n "$username" ]; then
      # Send disconnect via accel-ppp CLI
      if command -v accel-cmd &>/dev/null; then
        accel-cmd terminate username "$username" 2>/dev/null && \
          echo "$LOG_PREFIX PPPoE session terminated for $username" || \
          echo "$LOG_PREFIX WARNING: Could not terminate PPPoE for $username"
      fi
    fi

    # Disconnect IPoE (block via nftables)
    if [ "$conn_type" = "ipoe" ] && [ -n "$username" ]; then
      CLIENT_IP=$(sqlite3 "$DB" "SELECT ip_address FROM billing_clients WHERE username='$username' LIMIT 1;")
      if [ -n "$CLIENT_IP" ]; then
        nft add rule inet filter forward ip saddr "$CLIENT_IP" drop 2>/dev/null && \
          echo "$LOG_PREFIX IPoE blocked for $CLIENT_IP ($username)" || \
          echo "$LOG_PREFIX WARNING: Could not block IPoE for $CLIENT_IP"
      fi
    fi

    # Update client status to suspended
    sqlite3 "$DB" "UPDATE billing_clients SET status='suspended' WHERE id=$client_id;"
    sqlite3 "$DB" "UPDATE billing_invoices SET status='overdue' WHERE id=$invoice_id;"

    echo "$LOG_PREFIX $name marked as SUSPENDED."

  elif [ "$DAYS_OVERDUE" -ge 1 ]; then
    # Send reminder (1-2 days overdue)
    echo "$LOG_PREFIX REMINDER: $name ($username) - $DAYS_OVERDUE day(s) overdue"
    /opt/pot-system/scripts/send-reminder-single.sh "$name" "$phone" "$email" "$amount" "$due_date" "$conn_type" &
  fi

done <<< "$OVERDUE"

echo "$LOG_PREFIX Billing check complete."
