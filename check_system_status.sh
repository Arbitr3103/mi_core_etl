#!/bin/bash

echo "=== Проверка состояния системы ==="
echo "Дата: $(date)"
echo ""

echo "1. Проверка основных файлов:"
files=("index.php" "test_dashboard.html" "images/market_mi_logo.jpeg" "api/inventory-analytics.php" "config.php")

for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "✅ $file - найден"
    else
        echo "❌ $file - НЕ НАЙДЕН"
    fi
done

echo ""
echo "2. Проверка размеров файлов:"
ls -lh index.php test_dashboard.html images/market_mi_logo.jpeg 2>/dev/null | awk '{print $5 " " $9}'

echo ""
echo "3. Проверка логотипа:"
if [ -f "images/market_mi_logo.jpeg" ]; then
    file_size=$(stat -f%z "images/market_mi_logo.jpeg" 2>/dev/null || stat -c%s "images/market_mi_logo.jpeg" 2>/dev/null)
    echo "Размер логотипа: $file_size байт"
    if [ "$file_size" -gt 10000 ]; then
        echo "✅ Логотип имеет нормальный размер"
    else
        echo "⚠️ Логотип может быть поврежден (слишком маленький)"
    fi
else
    echo "❌ Логотип не найден"
fi

echo ""
echo "4. Проверка API файла:"
if grep -q "inventory-analytics.php" api/inventory-analytics.php 2>/dev/null; then
    echo "✅ API файл содержит корректный код"
else
    echo "⚠️ API файл может быть поврежден"
fi

echo ""
echo "5. Проверка конфигурации:"
if grep -q "getDatabaseConnection" config.php 2>/dev/null; then
    echo "✅ Конфигурация базы данных найдена"
else
    echo "❌ Конфигурация базы данных не найдена"
fi

echo ""
echo "=== Рекомендации ==="
echo "1. Откройте в браузере: http://your-domain/index.php"
echo "2. Проверьте API: http://your-domain/test_api_simple.php"
echo "3. Протестируйте дашборд: http://your-domain/test_dashboard.html"
echo ""
echo "=== Конец проверки ==="