#!/bin/bash
# Скрипт для настройки локального тестирования системы маржинальности

echo "🚀 Настройка локального тестирования системы маржинальности"
echo "============================================================"

# Проверяем наличие Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker не установлен. Установите Docker Desktop для macOS"
    echo "   Скачать: https://www.docker.com/products/docker-desktop"
    exit 1
fi

echo "✅ Docker найден"

# Останавливаем и удаляем существующий контейнер (если есть)
echo "🧹 Очистка старых контейнеров..."
docker stop mysql-margin-test 2>/dev/null || true
docker rm mysql-margin-test 2>/dev/null || true

# Запускаем MySQL контейнер
echo "🐳 Запуск MySQL контейнера..."
docker run --name mysql-margin-test \
    -e MYSQL_ROOT_PASSWORD=testpassword \
    -e MYSQL_DATABASE=mi_core_db \
    -e MYSQL_USER=testuser \
    -e MYSQL_PASSWORD=testpass \
    -p 3307:3306 \
    -d mysql:8.0

# Ждем запуска MySQL
echo "⏳ Ожидание запуска MySQL..."
sleep 30

# Проверяем подключение
echo "🔍 Проверка подключения..."
if docker exec mysql-margin-test mysql -u root -ptestpassword -e "SELECT 1;" &>/dev/null; then
    echo "✅ MySQL запущен успешно"
else
    echo "❌ Ошибка запуска MySQL"
    exit 1
fi

# Создаем тестовую конфигурацию
echo "📝 Создание тестовой конфигурации..."
cat > .env.local << EOF
# Локальная конфигурация для тестирования
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=mi_core_db
DB_USER=root
DB_PASSWORD=testpassword

# Ozon API (тестовые значения)
OZON_CLIENT_ID=test_client_id
OZON_API_KEY=test_api_key

# Wildberries API (тестовые значения)
WB_API_KEY=test_wb_key
EOF

echo "🗄️  Создание структуры базы данных..."
docker exec -i mysql-margin-test mysql -u root -ptestpassword mi_core_db < create_tables.sql

echo "📊 Создание тестовых данных..."
cat > create_test_data.sql << 'EOF'
USE mi_core_db;

-- Создаем тестовых клиентов
INSERT INTO clients (id, name) VALUES 
(1, 'Тестовый клиент 1'),
(2, 'Тестовый клиент 2');

-- Создаем источники
INSERT INTO sources (id, code, name) VALUES 
(1, 'ozon', 'Ozon'),
(2, 'wb', 'Wildberries');

-- Создаем тестовые товары с себестоимостью
INSERT INTO dim_products (id, sku_ozon, barcode, product_name, cost_price) VALUES 
(1, 'TEST_SKU_001', '1234567890123', 'Тестовый товар 1', 100.00),
(2, 'TEST_SKU_002', '1234567890124', 'Тестовый товар 2', 150.00),
(3, 'TEST_SKU_003', '1234567890125', 'Тестовый товар 3', 200.00);

-- Создаем тестовые заказы
INSERT INTO fact_orders (product_id, order_id, transaction_type, sku, qty, price, order_date, cost_price, client_id, source_id) VALUES 
-- Продажи за 2024-09-20
(1, 'ORDER_001', 'продажа', 'TEST_SKU_001', 2, 250.00, '2024-09-20', 100.00, 1, 1),
(2, 'ORDER_002', 'продажа', 'TEST_SKU_002', 1, 400.00, '2024-09-20', 150.00, 1, 1),
(3, 'ORDER_003', 'продажа', 'TEST_SKU_003', 3, 350.00, '2024-09-20', 200.00, 1, 2),
-- Продажи за 2024-09-21
(1, 'ORDER_004', 'продажа', 'TEST_SKU_001', 1, 250.00, '2024-09-21', 100.00, 1, 1),
(2, 'ORDER_005', 'продажа', 'TEST_SKU_002', 2, 400.00, '2024-09-21', 150.00, 2, 2),
-- Возврат
(1, 'ORDER_006', 'возврат', 'TEST_SKU_001', 1, 250.00, '2024-09-21', 100.00, 1, 1);

-- Создаем тестовые транзакции
INSERT INTO fact_transactions (client_id, source_id, transaction_id, order_id, transaction_type, amount, transaction_date, description) VALUES 
-- Комиссии за 2024-09-20
(1, 1, 'COMM_001', 'ORDER_001', '��омиссия маркетплейса', -50.00, '2024-09-20', 'Комиссия Ozon 10%'),
(1, 1, 'COMM_002', 'ORDER_002', 'эквайринг', -8.00, '2024-09-20', 'Эквайринг 2%'),
(1, 2, 'COMM_003', 'ORDER_003', 'комиссия маркетплейса', -105.00, '2024-09-20', 'Комиссия WB 10%'),
-- Логистика за 2024-09-20
(1, 1, 'LOG_001', 'ORDER_001', 'логистика', -30.00, '2024-09-20', 'Доставка'),
(1, 1, 'LOG_002', 'ORDER_002', 'доставка', -25.00, '2024-09-20', 'Доставка'),
(1, 2, 'LOG_003', 'ORDER_003', 'логистика', -45.00, '2024-09-20', 'Доставка WB'),
-- Комиссии за 2024-09-21
(1, 1, 'COMM_004', 'ORDER_004', 'комиссия маркетплейса', -25.00, '2024-09-21', 'Комиссия Ozon'),
(2, 2, 'COMM_005', 'ORDER_005', 'комиссия маркетплейса', -80.00, '2024-09-21', 'Комиссия WB'),
-- Логистика за 2024-09-21
(1, 1, 'LOG_004', 'ORDER_004', 'логистика', -15.00, '2024-09-21', 'Доставка'),
(2, 2, 'LOG_005', 'ORDER_005', 'доставка', -40.00, '2024-09-21', 'Доставка WB');

-- Показываем созданные данные
SELECT '=== СОЗДАННЫЕ ТЕСТОВЫЕ ДАННЫЕ ===' as info;

SELECT 'Товары:' as type, COUNT(*) as count FROM dim_products;
SELECT 'Заказы:' as type, COUNT(*) as count FROM fact_orders;
SELECT 'Транзакции:' as type, COUNT(*) as count FROM fact_transactions;

SELECT 'Заказы по датам:' as info;
SELECT order_date, COUNT(*) as orders, SUM(qty * price) as revenue 
FROM fact_orders 
WHERE transaction_type = 'продажа'
GROUP BY order_date;

SELECT 'Транзакции по датам:' as info;
SELECT transaction_date, COUNT(*) as transactions, SUM(ABS(amount)) as total_amount 
FROM fact_transactions 
GROUP BY transaction_date;
EOF

docker exec -i mysql-margin-test mysql -u root -ptestpassword mi_core_db < create_test_data.sql

echo "🔧 Добавление колонки margin_percent..."
docker exec -i mysql-margin-test mysql -u root -ptestpassword mi_core_db < add_margin_percent_column.sql

echo ""
echo "🎉 Локальное тестирование настроено!"
echo ""
echo "📋 Информация о подключении:"
echo "   Host: 127.0.0.1"
echo "   Port: 3307"
echo "   Database: mi_core_db"
echo "   User: root"
echo "   Password: testpassword"
echo ""
echo "🧪 Для тестирования выполните:"
echo "   export $(cat .env.local | xargs)"
echo "   python3 quick_margin_test.py"
echo ""
echo "🛑 Для остановки:"
echo "   docker stop mysql-margin-test"
echo "   docker rm mysql-margin-test"