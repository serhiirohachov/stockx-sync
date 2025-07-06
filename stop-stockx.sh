#!/bin/bash

echo "🛑 Зупинка stockx-сесій і пов'язаних процесів..."

# Зупинка tmux-сесії, якщо існує
if tmux has-session -t stockx 2>/dev/null; then
  echo "🧹 Закриваю tmux-сесію 'stockx'..."
  tmux kill-session -t stockx
fi

# Завершення всіх пов’язаних процесів
echo "🔍 Пошук і зупинка процесів..."
pkill -f 'wp stockx' && echo "✅ wp stockx завершено"
pkill -f 'stockx_product_' && echo "✅ Логи stockx_product_ завершено"
pkill -f 'bash' && echo "✅ bash процеси завершено"
pkill -f 'php' && echo "✅ php процеси завершено"
pkill -f 'sleep' && echo "✅ sleep завершено"

echo "✅ Всі stockx-процеси зупинено."
