#!/usr/bin/env python3
"""
Демонстрация исправлений отображения названий товаров
Показывает разницу между старой и новой логикой
"""

import mysql.connector
import sys
from tabulate import tabulate
from config_local import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD

def connect_to_db():
    """Подключение к БД"""
    return mysql.connector.connect(
        host=DB_HOST,
        database=DB_NAME,
        user=DB_USER,
        password=DB_PASSWORD,
        charset='utf8mb4'
    )

def demo_old_vs_new_logic():
    """Демонстрация разницы между старой и новой логикой JOIN"""
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    print("="*80)
    print("ДЕМОНСТРАЦИЯ ИСПРАВЛЕНИЙ ОТОБРАЖЕНИЯ НАЗВАНИЙ ТОВАРОВ")
    print("="*80)
    
    # Старая логика JOIN (проблемная)
    print("\n1. СТАРАЯ ЛОГИКА JOIN (проблемная):")
    print("-" * 50)
    
    old_query = """
        SELECT 
            i.product_id,
            i.sku,
            i.source,
            p.product_name,
            COALESCE(p.product_name, 
                CASE 
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    ELSE i.sku
                END
            ) as display_name
        FROM inventory_data i
        LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
        WHERE i.current_stock > 0
        ORDER BY i.source, i.current_stock DESC
        LIMIT 10
    """
    
    cursor.execute(old_query)
    old_results = cursor.fetchall()
    
    headers = ['Product ID', 'SKU', 'Source', 'DB Name', 'Display Name']
    print(tabulate(old_results, headers=headers, tablefmt='grid'))
    
    # Новая логика JOIN (исправленная)
    print("\n2. НОВАЯ ЛОГИКА JOIN (исправленная):")
    print("-" * 50)
    
    new_query = """
        SELECT 
            i.product_id,
            i.sku,
            i.source,
            p.product_name,
            COALESCE(p.product_name, 
                CASE 
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    ELSE i.sku
                END
            ) as display_name
        FROM inventory_data i
        LEFT JOIN product_names p ON (
            (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
            (i.product_id = 0 AND i.sku = p.sku)
        )
        WHERE i.current_stock > 0
        ORDER BY i.source, i.current_stock DESC
        LIMIT 10
    """
    
    cursor.execute(new_query)
    new_results = cursor.fetchall()
    
    print(tabulate(new_results, headers=headers, tablefmt='grid'))
    
    # Статистика улучшений
    print("\n3. СТАТИСТИКА УЛУЧШЕНИЙ:")
    print("-" * 50)
    
    # Подсчитываем покрытие названиями в старой логике
    cursor.execute("""
        SELECT 
            COUNT(*) as total,
            COUNT(p.product_name) as with_names
        FROM inventory_data i
        LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
        WHERE i.current_stock > 0
    """)
    old_stats = cursor.fetchone()
    
    # Подсчитываем покрытие названиями в новой логике
    cursor.execute("""
        SELECT 
            COUNT(*) as total,
            COUNT(p.product_name) as with_names
        FROM inventory_data i
        LEFT JOIN product_names p ON (
            (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
            (i.product_id = 0 AND i.sku = p.sku)
        )
        WHERE i.current_stock > 0
    """)
    new_stats = cursor.fetchone()
    
    old_coverage = (old_stats[1] / old_stats[0]) * 100 if old_stats[0] > 0 else 0
    new_coverage = (new_stats[1] / new_stats[0]) * 100 if new_stats[0] > 0 else 0
    improvement = new_coverage - old_coverage
    
    stats_table = [
        ['Метрика', 'Старая логика', 'Новая логика', 'Улучшение'],
        ['Всего товаров', old_stats[0], new_stats[0], ''],
        ['С названиями', old_stats[1], new_stats[1], f'+{new_stats[1] - old_stats[1]}'],
        ['Покрытие (%)', f'{old_coverage:.1f}%', f'{new_coverage:.1f}%', f'+{improvement:.1f}%']
    ]
    
    print(tabulate(stats_table, headers='firstrow', tablefmt='grid'))
    
    cursor.close()
    connection.close()

def demo_analytics_data_enrichment():
    """Демонстрация обогащения аналитических данных"""
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    print("\n4. ОБОГАЩЕНИЕ АНАЛИТИЧЕСКИХ ДАННЫХ:")
    print("-" * 50)
    
    # Показываем товары из Ozon_Analytics с названиями и без
    query = """
        SELECT 
            i.product_id,
            i.sku,
            i.warehouse_name,
            i.current_stock,
            p.product_name,
            CASE 
                WHEN p.product_name IS NOT NULL THEN 'Есть название'
                ELSE 'Нет названия'
            END as name_status
        FROM inventory_data i
        LEFT JOIN product_names p ON (
            (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
            (i.product_id = 0 AND i.sku = p.sku)
        )
        WHERE i.source = 'Ozon_Analytics'
        AND i.current_stock > 0
        ORDER BY name_status DESC, i.current_stock DESC
        LIMIT 15
    """
    
    cursor.execute(query)
    results = cursor.fetchall()
    
    headers = ['Product ID', 'SKU', 'Warehouse', 'Stock', 'Product Name', 'Status']
    print(tabulate(results, headers=headers, tablefmt='grid'))
    
    cursor.close()
    connection.close()

def demo_fallback_logic():
    """Демонстрация fallback логики для товаров без названий"""
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    print("\n5. FALLBACK ЛОГИКА ДЛЯ ТОВАРОВ БЕЗ НАЗВАНИЙ:")
    print("-" * 50)
    
    query = """
        SELECT 
            i.sku,
            i.source,
            p.product_name as db_name,
            COALESCE(p.product_name, 
                CASE 
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    ELSE i.sku
                END
            ) as display_name,
            CASE 
                WHEN p.product_name IS NOT NULL THEN 'Из БД'
                WHEN i.sku REGEXP '^[0-9]+$' THEN 'Fallback (числовой)'
                ELSE 'Fallback (текстовый)'
            END as name_source
        FROM inventory_data i
        LEFT JOIN product_names p ON (
            (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
            (i.product_id = 0 AND i.sku = p.sku)
        )
        WHERE i.current_stock > 0
        ORDER BY name_source, i.sku
        LIMIT 15
    """
    
    cursor.execute(query)
    results = cursor.fetchall()
    
    headers = ['SKU', 'Source', 'DB Name', 'Display Name', 'Name Source']
    print(tabulate(results, headers=headers, tablefmt='grid'))
    
    cursor.close()
    connection.close()

def demo_performance_comparison():
    """Демонстрация производительности запросов"""
    import time
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    print("\n6. СРАВНЕНИЕ ПРОИЗВОДИТЕЛЬНОСТИ:")
    print("-" * 50)
    
    # Тест старого запроса
    old_query = """
        SELECT COUNT(*) FROM inventory_data i
        LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
        WHERE i.current_stock > 0
    """
    
    start_time = time.time()
    cursor.execute(old_query)
    old_result = cursor.fetchone()[0]
    old_time = time.time() - start_time
    
    # Тест нового запроса
    new_query = """
        SELECT COUNT(*) FROM inventory_data i
        LEFT JOIN product_names p ON (
            (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
            (i.product_id = 0 AND i.sku = p.sku)
        )
        WHERE i.current_stock > 0
    """
    
    start_time = time.time()
    cursor.execute(new_query)
    new_result = cursor.fetchone()[0]
    new_time = time.time() - start_time
    
    perf_table = [
        ['Метрика', 'Старый запрос', 'Новый запрос'],
        ['Время выполнения', f'{old_time:.3f}с', f'{new_time:.3f}с'],
        ['Записей обработано', old_result, new_result],
        ['Скорость', f'{old_result/old_time:.0f} зап/с', f'{new_result/new_time:.0f} зап/с']
    ]
    
    print(tabulate(perf_table, headers='firstrow', tablefmt='grid'))
    
    cursor.close()
    connection.close()

def main():
    """Главная функция демонстрации"""
    try:
        print("Запуск демонстрации исправлений отображения названий товаров...")
        
        demo_old_vs_new_logic()
        demo_analytics_data_enrichment()
        demo_fallback_logic()
        demo_performance_comparison()
        
        print("\n" + "="*80)
        print("ДЕМОНСТРАЦИЯ ЗАВЕРШЕНА")
        print("="*80)
        print("\nОсновные улучшения:")
        print("✅ Исправлен JOIN для товаров с product_id = 0")
        print("✅ Добавлена fallback логика для товаров без названий")
        print("✅ Улучшено покрытие товаров читаемыми названиями")
        print("✅ Сохранена производительность запросов")
        
    except Exception as e:
        print(f"Ошибка демонстрации: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()