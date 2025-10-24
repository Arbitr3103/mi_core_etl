#!/bin/bash

echo "🔍 ДИАГНОСТИКА ПРОБЛЕМЫ С WAREHOUSE DASHBOARD"
echo "=============================================="
echo ""

echo "1. Проверяем тестовый файл:"
echo "URL: https://www.market-mi.ru/warehouse-dashboard/test.html"
curl -s https://www.market-mi.ru/warehouse-dashboard/test.html | head -3
echo ""

echo "2. Проверяем основной index.html:"
echo "URL: https://www.market-mi.ru/warehouse-dashboard/"
curl -s https://www.market-mi.ru/warehouse-dashboard/ | head -10
echo ""

echo "3. Проверяем, какой CSS файл ссылается в HTML:"
curl -s https://www.market-mi.ru/warehouse-dashboard/ | grep -o 'index-[^"]*\.css'
echo ""

echo "4. Проверяем размер CSS файла:"
curl -I https://www.market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css 2>/dev/null | grep -i content-length
echo ""

echo "5. Проверяем первые строки CSS (должен быть Tailwind):"
curl -s https://www.market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css | head -3
echo ""

echo "6. Проверяем, есть ли в CSS наши классы (sticky, z-50, etc):"
if curl -s https://www.market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css | grep -q "\.sticky"; then
    echo "✅ CSS содержит .sticky класс"
else
    echo "❌ CSS НЕ содержит .sticky класс"
fi

if curl -s https://www.market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css | grep -q "\.z-50"; then
    echo "✅ CSS содержит .z-50 класс"
else
    echo "❌ CSS НЕ содержит .z-50 класс"
fi

if curl -s https://www.market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css | grep -q "\.top-16"; then
    echo "✅ CSS содержит .top-16 класс"
else
    echo "❌ CSS НЕ содержит .top-16 класс"
fi
echo ""

echo "7. Проверяем файлы на сервере:"
ssh root@www.market-mi.ru "ls -lh /var/www/market-mi.ru/warehouse-dashboard/assets/css/"
echo ""

echo "8. Проверяем дату последнего изменения index.html:"
ssh root@www.market-mi.ru "ls -lh /var/www/market-mi.ru/warehouse-dashboard/index.html"
echo ""

echo "=============================================="
echo "ИНСТРУКЦИИ ДЛЯ ПРОВЕРКИ:"
echo ""
echo "1. Откройте в браузере: https://www.market-mi.ru/warehouse-dashboard/test.html"
echo "   Должен показать красный заголовок с датой"
echo ""
echo "2. Если тестовый файл показывается - проблема в кэше браузера"
echo "   Сделайте жесткую перезагрузку: Cmd+Shift+R"
echo ""
echo "3. Если тестовый файл НЕ показывается - проблема в веб-сервере"
echo "   Нужно проверить конфигурацию Nginx/Apache"
echo ""
echo "4. Откройте DevTools (F12) и проверьте вкладку Network"
echo "   При загрузке страницы должен загружаться index-BnGjtDq2.css"
echo ""