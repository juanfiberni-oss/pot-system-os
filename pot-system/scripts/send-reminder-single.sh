#!/bin/bash
# POT System - Send payment reminder to a single client
# Usage: send-reminder-single.sh <name> <phone> <email> <amount> <due_date> <conn_type>

NAME="$1"
PHONE="$2"
EMAIL="$3"
AMOUNT="$4"
DUE_DATE="$5"
CONN_TYPE="$6"

LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [REMINDER]"
DB="/var/lib/pot-system/pot.db"

echo "$LOG_PREFIX Sending reminder to: $NAME"
echo "$LOG_PREFIX Phone: $PHONE | Email: $EMAIL"
echo "$LOG_PREFIX Amount: ₱$AMOUNT | Due: $DUE_DATE | Type: $CONN_TYPE"

MSG="Dear $NAME, your internet bill of P$AMOUNT is due on $DUE_DATE. Please pay to avoid disconnection of your $CONN_TYPE connection. - POT System"

# ── SMS Gateway (configure your provider) ───────────────────
# Uncomment and configure for your SMS provider:
# curl -s -X POST "https://api.semaphore.co/api/v4/messages" \
#   -d "apikey=YOUR_API_KEY" \
#   -d "number=$PHONE" \
#   -d "message=$MSG" \
#   -d "sendername=POTSystem"

# ── Email via sendmail/msmtp ─────────────────────────────────
# Uncomment if sendmail/msmtp is configured:
# echo -e "Subject: Payment Reminder - POT System\nTo: $EMAIL\n\n$MSG" | sendmail "$EMAIL"

# ── Log to database ──────────────────────────────────────────
TIMESTAMP=$(date +%s)
sqlite3 "$DB" "INSERT OR IGNORE INTO system_config (key, value) VALUES ('reminder_${TIMESTAMP}', '$(echo "$NAME|$AMOUNT|$DUE_DATE|$CONN_TYPE" | sed "s/'/''/g")');" 2>/dev/null

echo "$LOG_PREFIX Reminder logged for $NAME (SMS/Email integration: configure in this script)"
