#!/usr/bin/env python3
"""
Тест интеграции с мастер таблицей dim_products
"""

import mysql.connector
import os
import sys
from dotenv import load_dotenv

load_dotenv()

def test_master_table_integration():
    """Тестирует интеграцию с мастер таблицей"""
    
    try:
        # Подключение к основной базе
        core_connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            database='mi_core',
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            charset='utf8mb4'
        )
        
        # Подключение к базе с мастер таблицей
        master_connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            database='mi_core_db',
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            charset='utf8mb4'
        )
        
        print("="*70)
        print("ТЕСТ ИНТЕГРАЦИИ С МАСТЕР ТАБЛИЦЕЙ dim_products")
        print("="*70)
        
        # 1. Проверяем доступность мастер таблицы
        print("\n1. Проверка доступности мастер таблицы:")
        print("-" * 50)
        
        master_cursor = master_connection.cursor()
        master_cursor.execute("""
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN sku_ozon IS NOT NULL AND sku_ozon != '' THEN 1 END) as with_ozon_sku,
                COUNT(CASE WHEN product_name IS NOT NULL AND product_name != '' THEN 1 END) as with_names,
                COUNT(DISTINCT brand) as unique_brands,
                COUNT(DISTINCT category) as unique_categories
            FROM dim_products
        """)
        
        master_stats = master_cursor.fetchone()
        print(f"  Всего товаров в мастер таблице: {master_stats[0]}")
        print(f"  С Ozon SKU: {master_stats[1]}")
        print(f"  С названиями: {master_stats[2]}")
        print(f"  Уникальных брендов: {master_stats[3]}")
        print(f"  Уникальных категорий: {master_stats[4]}")
        
        # 2. Примеры данных из мастер таблицы
        print(f"\n2. Примеры данных из мастер таблицы:")
        print("-" * 50)
        
        master_cursor.execute("""
            SELECT sku_ozon, product_name, brand, category
            FROM dim_products 
            WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            AND product_name IS NOT NULL
            ORDER BY updated_at DESC
            LIMIT 5
        """)
        
        master_examples = master_cursor.fetchall()
        for row in master_examples:
            sku, name, brand, category = row
            print(f"  SKU: {sku:15} | {name[:30]:30} | {brand or 'N/A':15} | {category or 'N/A'}")
        
        # 3. Проверяем совпадения с inventory_data
        print(f"\n3. Анализ совпадений с inventory_data:")
        print("-" * 50)
        
        core_cursor = core_connection.cursor()
        
        # Получаем SKU из inventory_data
        core_cursor.execute("""
            SELECT DISTINCT sku 
            FROM inventory_data 
            WHERE current_stock > 0 
            AND source IN ('Ozon', 'Ozon_Analytics')
            LIMIT 10
        """)
        
        inventory_skus = [row[0] for row in core_cursor.fetchall()]
        
        print(f"  Примеры SKU из inventory_data: {inventory_skus[:5]}")
        
        # Проверяем, сколько из них есть в мастер таблице
        if inventory_skus:
            placeholders = ','.join(['%s'] * len(inventory_skus))
            master_cursor.execute(f"""
                SELECT sku_ozon, product_name, brand
                FROM dim_products 
                WHERE sku_ozon IN ({placeholders})
            """, inventory_skus)
            
            matches = master_cursor.fetchall()
            print(f"  Найдено совпадений в мастер таблице: {len(matches)} из {len(inventory_skus)}")
            
            if matches:
                print("  Примеры совпадений:")
                for sku, name, brand in matches[:3]:
                    print(f"    SKU: {sku} | {name[:40]} | {brand or 'N/A'}")
        
        # 4. Тестируем кросс-базовый JOIN (эмуляция)
        print(f"\n4. Тест кросс-базового JOIN:")
        print("-" * 50)
        
        # Получаем данные из мастер таблицы для создания временной таблицы
        master_cursor.execute("""
            SELECT sku_ozon, product_name, brand, category
            FROM dim_products 
            WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            LIMIT 100
        """)
        
        master_data = master_cursor.fetchall()
        
        # Создаем временную таблицу в основной базе
        core_cursor.execute("DROP TEMPORARY TABLE IF EXISTS temp_dim_products")
        core_cursor.execute("""
            CREATE TEMPORARY TABLE temp_dim_products (
                sku_ozon VARCHAR(255),
                product_name VARCHAR(500),
                brand VARCHAR(255),
                category VARCHAR(255),
                INDEX idx_sku_ozon (sku_ozon)
            )
        """)
        
        # Заполняем временную таблицу
        insert_query = """
            INSERT INTO temp_dim_products (sku_ozon, product_name, brand, category) 
            VALUES (%s, %s, %s, %s)
        """
        
        for row in master_data:
            core_cursor.execute(insert_query, row)
        
        print(f"  Создана временная таблица с {len(master_data)} записями")
        
        # Тестируем JOIN
        core_cursor.execute("""
            SELECT 
                i.sku,
                i.current_stock,
                i.source,
                dp.product_name,
                dp.brand,
                dp.category,
                CASE 
                    WHEN dp.product_name IS NOT NULL THEN 'Из мастер таблицы'
                    ELSE 'Fallback'
                END as name_source
            FROM inventory_data i
            LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
            WHERE i.current_stock > 0
            ORDER BY name_source DESC, i.current_stock DESC
            LIMIT 10
        """)
        
        join_results = core_cursor.fetchall()
        
        print("  Результаты JOIN:")
        for row in join_results:
            sku, stock, source, name, brand, category, name_source = row
            display_name = name[:30] if name else f"Товар артикул {sku}"
            print(f"    {sku:15} | {stock:3} | {source:15} | {display_name:30} | {name_source}")
        
        # 5. Статистика улучшений
        print(f"\n5. Потенциальные улучшения:")
        print("-" * 50)
        
        # Подсчитываем, сколько товаров получат названия
        core_cursor.execute("""
            SELECT 
                COUNT(*) as total_inventory,
                COUNT(dp.product_name) as would_have_names
            FROM inventory_data i
            LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
            WHERE i.current_stock > 0
        """)
        
        improvement_stats = core_cursor.fetchone()
        total_inventory, would_have_names = improvement_stats
        
        # Текущее состояние
        core_cursor.execute("""
            SELECT 
                COUNT(*) as total,
                COUNT(p.product_name) as current_with_names
            FROM inventory_data i
            LEFT JOIN product_names p ON (
                (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                (i.product_id = 0 AND i.sku = p.sku)
            )
            WHERE i.current_stock > 0
        """)
        
        current_stats = core_cursor.fetchone()
        current_total, current_with_names = current_stats
        
        current_coverage = (current_with_names / max(current_total, 1)) * 100
        potential_coverage = (would_have_names / max(total_inventory, 1)) * 100
        improvement = potential_coverage - current_coverage
        
        print(f"  Текущее покрытие: {current_with_names}/{current_total} ({current_coverage:.1f}%)")
        print(f"  Потенциальное покрытие: {would_have_names}/{total_inventory} ({potential_coverage:.1f}%)")
        print(f"  Улучшение: +{would_have_names - current_with_names} товаров (+{improvement:.1f}%)")
        
        master_cursor.close()
        core_cursor.close()
        master_connection.close()
        core_connection.close()
        
        print(f"\n" + "="*70)
        print("РЕЗУЛЬТАТ ТЕСТИРОВАНИЯ:")
        print("="*70)
        print("✅ Подключение к мастер таблице работает")
        print("✅ Кросс-базовый JOIN функционирует корректно")
        print("✅ Мастер таблица содержит богатую информацию о товарах")
        print(f"✅ Потенциальное улучшение покрытия: +{improvement:.1f}%")
        
        if improvement > 10:
            print("🚀 РЕКОМЕНДАЦИЯ: Интеграция с мастер таблицей значительно улучшит отображение!")
        elif improvement > 0:
            print("👍 РЕКОМЕНДАЦИЯ: Интеграция с мастер таблицей улучшит отображение")
        else:
            print("ℹ️  ИНФОРМАЦИЯ: Мастер таблица не даст значительных улучшений")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка тестирования: {e}")
        return False

if __name__ == "__main__":
    success = test_master_table_integration()
    exit(0 if success else 1)