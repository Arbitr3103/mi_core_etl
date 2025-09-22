-- Исправление тестовых данных с английскими терминами
USE mi_core_db;

-- Очищаем старые данные
DELETE FROM metrics_daily;
DELETE FROM fact_transactions;
DELETE FROM fact_orders;
DELETE FROM dim_products;
DELETE FROM sources;
DELETE FROM clients;

-- Создаем тестовых клиентов
INSERT INTO clients (id, name) VALUES 
(1, 'Test Client 1'),
(2, 'Test Client 2');

-- Создаем источники
INSERT INTO sources (id, code, name) VALUES 
(1, 'ozon', 'Ozon'),
(2, 'wb', 'Wildberries');

-- Создаем тестовые товары с себестоимостью
INSERT INTO dim_products (id, sku_ozon, barcode, product_name, cost_price) VALUES 
(1, 'TEST_SKU_001', '1234567890123', 'Test Product 1', 100.00),
(2, 'TEST_SKU_002', '1234567890124', 'Test Product 2', 150.00),
(3, 'TEST_SKU_003', '1234567890125', 'Test Product 3', 200.00);

-- Создаем тестовые заказы (используем английские термины)
INSERT INTO fact_orders (product_id, order_id, transaction_type, sku, qty, price, order_date, cost_price, client_id, source_id) VALUES 
-- Продажи за 2024-09-20
(1, 'ORDER_001', 'sale', 'TEST_SKU_001', 2, 250.00, '2024-09-20', 100.00, 1, 1),
(2, 'ORDER_002', 'sale', 'TEST_SKU_002', 1, 400.00, '2024-09-20', 150.00, 1, 1),
(3, 'ORDER_003', 'sale', 'TEST_SKU_003', 3, 350.00, '2024-09-20', 200.00, 1, 2),
-- Продажи за 2024-09-21
(1, 'ORDER_004', 'sale', 'TEST_SKU_001', 1, 250.00, '2024-09-21', 100.00, 1, 1),
(2, 'ORDER_005', 'sale', 'TEST_SKU_002', 2, 400.00, '2024-09-21', 150.00, 2, 2),
-- Возврат
(1, 'ORDER_006', 'return', 'TEST_SKU_001', 1, 250.00, '2024-09-21', 100.00, 1, 1);

-- Создаем тестовые транзакции (используем английские термины)
INSERT INTO fact_transactions (client_id, source_id, transaction_id, order_id, transaction_type, amount, transaction_date, description) VALUES 
-- Комиссии за 2024-09-20
(1, 1, 'COMM_001', 'ORDER_001', 'commission', -50.00, '2024-09-20', 'Ozon commission 10%'),
(1, 1, 'COMM_002', 'ORDER_002', 'fee', -8.00, '2024-09-20', 'Acquiring fee 2%'),
(1, 2, 'COMM_003', 'ORDER_003', 'commission', -105.00, '2024-09-20', 'WB commission 10%'),
-- Логистика за 2024-09-20
(1, 1, 'LOG_001', 'ORDER_001', 'shipping', -30.00, '2024-09-20', 'Delivery'),
(1, 1, 'LOG_002', 'ORDER_002', 'delivery', -25.00, '2024-09-20', 'Delivery'),
(1, 2, 'LOG_003', 'ORDER_003', 'logistics', -45.00, '2024-09-20', 'WB delivery'),
-- Комиссии за 2024-09-21
(1, 1, 'COMM_004', 'ORDER_004', 'commission', -25.00, '2024-09-21', 'Ozon commission'),
(2, 2, 'COMM_005', 'ORDER_005', 'commission', -80.00, '2024-09-21', 'WB commission'),
-- Логистика за 2024-09-21
(1, 1, 'LOG_004', 'ORDER_004', 'shipping', -15.00, '2024-09-21', 'Delivery'),
(2, 2, 'LOG_005', 'ORDER_005', 'delivery', -40.00, '2024-09-21', 'WB delivery');

-- Показываем созданные данные
SELECT '=== ИСПРАВЛЕННЫЕ ТЕСТОВЫЕ ДАННЫЕ ===' as info;

SELECT 'Products:' as type, COUNT(*) as count FROM dim_products;
SELECT 'Orders:' as type, COUNT(*) as count FROM fact_orders;
SELECT 'Transactions:' as type, COUNT(*) as count FROM fact_transactions;

SELECT 'Orders by date:' as info;
SELECT order_date, transaction_type, COUNT(*) as orders, SUM(qty * price) as revenue 
FROM fact_orders 
GROUP BY order_date, transaction_type
ORDER BY order_date, transaction_type;

SELECT 'Transactions by date:' as info;
SELECT transaction_date, COUNT(*) as transactions, SUM(ABS(amount)) as total_amount 
FROM fact_transactions 
GROUP BY transaction_date;