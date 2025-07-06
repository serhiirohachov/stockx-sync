#!/bin/bash

LOG_FILE="/var/www/html/wp-content/plugins/stockx-sync/sync-log.txt"
cd /var/www/html/wp-content/plugins/stockx-sync/ || exit 1

while true; do
  echo "🔄 [$(date)] Запуск StockX Sync" | tee -a "$LOG_FILE"
  
  ./start-stockx-sync.sh &>> "$LOG_FILE" &

  echo "⏳ Очікування завершення tmux-сесії 'stockx'..." | tee -a "$LOG_FILE"
  while tmux has-session -t stockx 2>/dev/null; do
    sleep 10
  done

  echo "✅ [$(date)] Sync завершено. Очікування 2 години..." | tee -a "$LOG_FILE"
  sleep 2h
done

