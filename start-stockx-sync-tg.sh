#!/bin/bash

SESSION="stockx"
WOMEN_IDS=(19966 20548 20285 19229 19228 19227 8534 8533)
TELEGRAM_BOT_TOKEN="7730095471:AAGpZ9e8h1tdO5PiAimA11oIeuXD8E6rheM"
TELEGRAM_CHAT_ID="-1001234567890"
SHEET_WEBHOOK="https://script.google.com/macros/s/AKfycbyT-dAT7-P66SWOfcoM22YhbmEhL12PYsOvKOGNEzi4rF8tBcviH134fMwb664ea22U/exec"
SITE_URL="https://pogo.com.ua/product"
UAH_RATE=39.5

send_telegram() {
  local message="$1"
  curl -s -X POST "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendMessage" \
    -d chat_id="$TELEGRAM_CHAT_ID" \
    -d text="$message" \
    -d parse_mode="Markdown"
}

send_to_sheet() {
  curl -s -X POST -H "Content-Type: application/json" \
    -d "$1" "$SHEET_WEBHOOK"
}

if tmux has-session -t "$SESSION" 2>/dev/null; then
  echo "🔁 Сесія '$SESSION' вже існує. Підключення..."
  tmux attach-session -t "$SESSION"
else
  echo "🚀 Створення нової tmux-сесії '$SESSION' і запуск команд..."
  tmux new-session -d -s "$SESSION"
  tmux send-keys -t "$SESSION" "$(declare -f send_telegram)" C-m
  tmux send-keys -t "$SESSION" "$(declare -f send_to_sheet)" C-m

  for ID in "${WOMEN_IDS[@]}"; do
    tmux send-keys -t "$SESSION" "echo '▶️ Синхронізація продукту ID $ID...'" C-m
    tmux send-keys -t "$SESSION" "start=\$(date +%s)" C-m

    tmux send-keys -t "$SESSION" "wp stockx sync-women $ID --allow-root | tee /tmp/stockx_product_$ID.log" C-m
    tmux send-keys -t "$SESSION" "end=\$(date +%s)" C-m
    tmux send-keys -t "$SESSION" "duration=\$((end - start))" C-m

    tmux send-keys -t "$SESSION" "title=\$(wp post get $ID --field=post_title --allow-root)" C-m
    tmux send-keys -t "$SESSION" "permalink=\$(wp post url $ID --allow-root)" C-m
    tmux send-keys -t "$SESSION" "stockx_url=\$(wp post meta get $ID _stockx_product_base_url --allow-root)" C-m

    tmux send-keys -t "$SESSION" "prices_uah=(\$(grep -oP '✅ Price \K[0-9.]+' /tmp/stockx_product_$ID.log))" C-m
    tmux send-keys -t "$SESSION" "count=\${#prices_uah[@]}" C-m

    tmux send-keys -t "$SESSION" "if [ \$count -gt 0 ]; then \
      sorted=(\$(printf '%s\n' \"\${prices_uah[@]}\" | sort -n)); \
      min_uah=\${sorted[0]}; \
      max_uah=\${sorted[-1]}; \
      min_usd=\$(awk \"BEGIN {printf \\\"%.2f\\\", \$min_uah / $UAH_RATE}\"); \
      max_usd=\$(awk \"BEGIN {printf \\\"%.2f\\\", \$max_uah / $UAH_RATE}\"); \
      msg=\"👟 *Продукт:* \$title\\n🔢 Варіацій: \$count\\n💸 Мін. ціна: \$min_uah грн (\$min_usd \$) (на StockX)\\n💰 Макс. ціна: \$max_uah грн (\$max_usd \$) (на StockX)\\n🌐 [На сайті](\$permalink)\\n🔗 [StockX](\$stockx_url)\\n⏱️ Час: \$duration сек.\"; \
      send_telegram \"\$msg\"; \
      json=\"{ \
        \\\"title\\\": \\\"\$title\\\", \
        \\\"count\\\": \\\"\$count\\\", \
        \\\"min_uah\\\": \\\"\$min_uah\\\", \
        \\\"max_uah\\\": \\\"\$max_uah\\\", \
        \\\"min_usd\\\": \\\"\$min_usd\\\", \
        \\\"max_usd\\\": \\\"\$max_usd\\\", \
        \\\"duration\\\": \\\"\$duration\\\", \
        \\\"permalink\\\": \\\"\$permalink\\\", \
        \\\"stockx_url\\\": \\\"\$stockx_url\\\" \
      }\"; \
      send_to_sheet \"\$json\"; \
    else \
      msg=\"⚠️ *Продукт:* \$title\\n⛔ Жодної варіації не синхронізовано\\n🌐 [На сайті](\$permalink)\\n🔗 [StockX](\$stockx_url)\\n⏱️ Час: \$duration сек.\"; \
      send_telegram \"\$msg\"; \
    fi" C-m

    tmux send-keys -t "$SESSION" "echo '✅ Готово: \$title'" C-m
    tmux send-keys -t "$SESSION" "sleep 2" C-m
  done

  sleep 1
  tmux attach-session -t "$SESSION"
fi
