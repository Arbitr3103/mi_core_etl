#!/bin/bash
# Скрипт для создания реалистичного распределения остатков

echo "🔄 Исправление распределения остатков для реалистичной картины..."

# Подключаемся к базе и обновляем остатки
mysql -u v_admin -p'Arbitr09102022!' mi_core << 'EOF'

-- Создаем временную таблицу для распределения
CREATE TEMPORARY TABLE temp_distribution AS
SELECT 
    sku,
    ROW_NUMBER() OVER (ORDER BY sku) as row_num,
    (SELECT COUNT(*) FROM inventory_data WHERE sku NOT LIKE 'TEST%') as total_count
FROM inventory_data 
WHERE sku NOT LIKE 'TEST%';

-- Обновляем остатки с реалистичным распределением
UPDATE inventory_data i
JOIN temp_distribution t ON i.sku = t.sku
SET 
    i.current_stock = CASE 
        -- 10% критических (0-5)
        WHEN t.row_num <= t.total_count * 0.1 THEN FLOOR(RAND() * 6)
        -- 20% низких (6-20)
        WHEN t.row_num <= t.total_count * 0.3 THEN 6 + FLOOR(RAND() * 15)
        -- 10% избыточных (101-300)
        WHEN t.row_num > t.total_count * 0.9 THEN 101 + FLOOR(RAND() * 200)
        -- 60% нормальных (21-100)
        ELSE 21 + FLOOR(RAND() * 80)
    END,
    i.available_stock = CASE 
        WHEN t.row_num <= t.total_count * 0.1 THEN FLOOR(RAND() * 6)
        WHEN t.row_num <= t.total_count * 0.3 THEN 6 + FLOOR(RAND() * 15)
        WHEN t.row_num > t.total_count * 0.9 THEN 101 + FLOOR(RAND() * 200)
        ELSE 21 + FLOOR(RAND() * 80)
    END,
    i.reserved_stock = FLOOR(RAND() * 5),
    i.last_sync_at = NOW()
WHERE i.sku NOT LIKE 'TEST%';

-- Добавляем несколько товаров с нулевыми остатками для критичности
UPDATE inventory_data 
SET current_stock = 0, available_stock = 0, reserved_stock = 0
WHERE sku IN (
    SELECT sku FROM (
        SELECT sku FROM inventory_data 
        WHERE sku NOT LIKE 'TEST%' 
        ORDER BY RAND() 
        LIMIT 5
    ) as random_skus
);

EOF

if [ $? -eq 0 ]; then
    echo "✅ Распределение остатков обновлено"
    
    # Проверяем результаты
    echo "📊 Новая статистика:"
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
        SELECT 
            'Критические (≤5)' as category,
            COUNT(*) as count 
        FROM inventory_data 
        WHERE current_stock <= 5 AND sku NOT LIKE 'TEST%'
        UNION ALL
        SELECT 
            'Низкие (6-20)' as category,
            COUNT(*) as count 
        FROM inventory_data 
        WHERE current_stock > 5 AND current_stock <= 20 AND sku NOT LIKE 'TEST%'
        UNION ALL
        SELECT 
            'Избыточные (>100)' as category,
            COUNT(*) as count 
        FROM inventory_data 
        WHERE current_stock > 100 AND sku NOT LIKE 'TEST%'
        UNION ALL
        SELECT 
            'Нормальные (21-100)' as category,
            COUNT(*) as count 
        FROM inventory_data 
        WHERE current_stock >= 21 AND current_stock <= 100 AND sku NOT LIKE 'TEST%';
    "
    
    echo ""
    echo "🔍 Примеры критических товаров:"
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
        SELECT sku, current_stock, warehouse_name 
        FROM inventory_data 
        WHERE current_stock <= 5 AND sku NOT LIKE 'TEST%' 
        ORDER BY current_stock ASC 
        LIMIT 10;
    "
    
else
    echo "❌ Ошибка обновления остатков"
    exit 1
fi

echo "✅ Реалистичное распределение остатков создано!"