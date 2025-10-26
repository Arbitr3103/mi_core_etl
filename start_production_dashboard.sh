#!/bin/bash

# 🚀 Запуск продакшен дашборда Analytics ETL
# Этот скрипт запускает веб-сервер с дашбордом для просмотра результатов

echo "🚀 Запуск продакшен дашборда Analytics ETL"
echo "=========================================="

# Проверка, что ETL данные есть
echo "📊 Проверка данных в базе..."
INVENTORY_COUNT=$(PGPASSWORD="mi_core_2024_secure" psql -h localhost -p 5432 -U mi_core_user -d mi_core_db -t -c "SELECT COUNT(*) FROM inventory;" 2>/dev/null | tr -d ' ')

if [ "$INVENTORY_COUNT" -gt 0 ]; then
    echo "✅ Найдено $INVENTORY_COUNT записей в базе данных"
else
    echo "⚠️ Данные в базе не найдены, запускаем ETL..."
    OZON_CLIENT_ID=26100 OZON_API_KEY=7e074977-e0db-4ace-ba9e-82903e088b4b php warehouse_etl_analytics.php --force --limit=100
fi

# Проверка API
echo "🔍 Проверка API..."
if php -r "
try {
    \$pdo = new PDO('pgsql:host=localhost;dbname=mi_core_db;port=5432', 'mi_core_user', 'mi_core_2024_secure');
    echo 'API готов к работе';
} catch (Exception \$e) {
    echo 'Ошибка подключения: ' . \$e->getMessage();
    exit(1);
}
"; then
    echo "✅ API готов"
else
    echo "❌ Проблема с API"
    exit 1
fi

# Запуск веб-сервера
echo ""
echo "🌐 Запуск веб-сервера..."
echo "📍 Дашборд будет доступен по адресу:"
echo "   http://localhost:8080/warehouse_dashboard.html"
echo ""
echo "📊 API endpoints:"
echo "   http://localhost:8080/warehouse_dashboard_api.php"
echo "   http://localhost:8080/src/api/controllers/AnalyticsETLController.php"
echo ""
echo "🔄 Для остановки нажмите Ctrl+C"
echo ""

# Запуск PHP сервера
php -S localhost:8080 -t .