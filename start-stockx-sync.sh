#!/bin/bash

SESSION="stockx"
COMMAND="wp stockx sync-all --allow-root"

# Перевірка, чи сесія вже існує
if tmux has-session -t "$SESSION" 2>/dev/null; then
  echo "🔁 Сесія '$SESSION' вже існує. Підключення..."
  tmux attach-session -t "$SESSION"
else
  echo "🚀 Створення нової tmux-сесії '$SESSION' і запуск команди..."
  tmux new-session -d -s "$SESSION" "$COMMAND"
  sleep 1
  tmux attach-session -t "$SESSION"
fi
