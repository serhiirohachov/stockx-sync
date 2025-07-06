#!/bin/bash

SELENIUM_URL=http://localhost:4444/status
CHROME_BINARY="/usr/bin/google-chrome"

echo "🔍 Перевірка Selenium..."
if curl -s --max-time 5 "$SELENIUM_URL" | grep -q '"ready":true'; then
    echo "✅ Selenium готовий до роботи."
else
    echo "❌ Selenium не готовий або не працює на $SELENIUM_URL"
    exit 1
fi

echo "🔍 Перевірка Chrome за шляхом $CHROME_BINARY..."
if [ -f "$CHROME_BINARY" ]; then
    echo "✅ Chrome знайдено."
else
    echo "❌ Chrome не знайдено. Задай шлях вручну або встанови його."
    exit 1
fi

echo "✅ Все готово до запуску плагіна."
