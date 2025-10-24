#!/bin/bash
# Точные команды для выполнения на сервере 178.72.129.61

echo "🚀 ПОШАГОВЫЕ КОМАНДЫ ДЛЯ СЕРВЕРА"
echo "================================"
echo ""
echo "Выполните эти команды после подключения:"
echo "ssh vladimir@178.72.129.61"
echo ""

echo "1️⃣ ПОИСК ДИРЕКТОРИИ С РАБОТАЮЩИМ API:"
echo "find /var/www -name 'inventory-analytics.php' 2>/dev/null"
echo "find /home -name 'inventory-analytics.php' 2>/dev/null"
echo "find /usr/share/nginx -name 'inventory-analytics.php' 2>/dev/null"
echo ""

echo "2️⃣ ПЕРЕХОД В НАЙДЕННУЮ ДИРЕКТОРИЮ:"
echo "# Замените /path/to/directory на найденный путь"
echo "cd /path/to/directory"
echo ""

echo "3️⃣ ПРОВЕРКА ТЕКУЩЕГО СОСТОЯНИЯ:"
echo "pwd"
echo "ls -la | grep -E '(html|php)'"
echo "git status"
echo "git log --oneline -3"
echo ""

echo "4️⃣ СИНХРОНИЗАЦИЯ С РЕПОЗИТОРИЕМ:"
echo "git pull origin main"
echo ""

echo "5️⃣ ПРОВЕРКА ЧТО ФАЙЛЫ ПОЯВИЛИСЬ:"
echo "ls -la inventory_products_dashboard.html"
echo "ls -la warehouse_dashboard*.html"
echo "ls -la analyze_*.php"
echo "ls -la critical_*.php"
echo ""

echo "6️⃣ УСТАНОВКА ПРАВ ДОСТУПА:"
echo "chmod 644 *.html *.css *.js *.php"
echo ""

echo "7️⃣ ФИНАЛЬНАЯ ПРОВЕРКА:"
echo "ls -la | grep -E '(inventory_products|warehouse_dashboard|analyze_|critical_)'"
echo ""

echo "✅ ПОСЛЕ ВЫПОЛНЕНИЯ ПРОВЕРЬТЕ В БРАУЗЕРЕ:"
echo "https://www.market-mi.ru/inventory_products_dashboard.html"
echo "https://www.market-mi.ru/analyze_inventory_structure.php"