#!/bin/bash

# Налаштування
BOT_TOKEN="7730095471:AAGpZ9e8h1tdO5PiAimA11oIeuXD8E6rheM"
CHAT_ID="211228499"
LOG_PATH="/var/log/wp-stockx-sync.log"

(
  echo "🔄 Sync started at $(date)" >> "$LOG_PATH"

wp --allow-root stockx sync-product \
8527 8106 6973 6347 6329 6312 5955 >> "$LOG_PATH" 2>&1


  curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
       -d chat_id="${CHAT_ID}" \
       -d text="✅ StockX sync complete at $(date)%0A📄 Log: ${LOG_PATH}"

) &
