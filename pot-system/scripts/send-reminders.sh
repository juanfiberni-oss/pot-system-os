#!/bin/bash
# POT System - Daily payment reminder script
# Runs at 9am via cron: 0 9 * * * root /opt/pot-system/scripts/send-reminders.sh

DB="/var/lib/pot-system/pot.db"
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [DAILY-REMINDER]"

echo "$LOG_PREFIX Running daily payment reminders..."

# Find invoices due in 3 days or less (not yet paid)
UPCOMING=$(sqlite3 "$DB" "
  SELECT c.name, c.phone, c.email, i.amount, i.due_date, c.connection_type
  FROM billing_invoices i
  JOIN billing_clients c ON c.id = i.client_id
  WHERE i.status = 'pending'
    AND i.due_date BETWEEN date('now') AND date('now', '+3 days')
    AND c.status = 'active';
" 2>/dev/null)

COUNT=0
if [ -n "$UPCOMING" ]; then
  while IFS='|' read -r name phone email amount due_date conn_type; do
    [ -z "$name" ] && continue
    /opt/pot-system/scripts/send-reminder-single.sh "$name" "$phone" "$email" "$amount" "$due_date" "$conn_type"
    COUNT=$((COUNT + 1))
  done <<< "$UPCOMING"
fi

echo "$LOG_PREFIX Sent $COUNT reminder(s)."
