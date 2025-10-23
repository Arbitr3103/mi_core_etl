#!/usr/bin/env python3
"""
Диагностический скрипт для проверки состояния dim_products на сервере.
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

def check_dim_products():
    """Проверяет состояние таблицы dim_products."""
    print('=== ДИАГНОСТИКА dim_products ===')
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем общее количество товаров
        cursor.execute("SELECT COUNT(*) as total FROM dim_products")
        total = cursor.fetchone()["total"]
        print(f"Общее количество товаров в dim_products: {total}")
        
        if total == 0:
            print("❌ ПРОБЛЕМА: dim_products пуста!")
            print("Необходимо запустить test_products.py для заполнения")
            return False
        
        # 2. Показываем структуру таблицы
        cursor.execute("DESCRIBE dim_products")
        columns = cursor.fetchall()
        print("\nСтруктура таблицы dim_products:")
        for col in columns:
            print(f"  {col['Field']} - {col['Type']} - {col['Null']} - {col['Key']}")
        
        # 3. Показываем примеры товаров (используем существующие колонки)
        cursor.execute("SELECT * FROM dim_products LIMIT 3")
        samples = cursor.fetchall()
        print("\nПримеры товаров:")
        for i, sample in enumerate(samples, 1):
            print(f"  Товар {i}:")
            for key, value in sample.items():
                print(f"    {key}: {value}")
            print()
        
        # 4. Проверяем конкретные SKU из тестовых данных
        test_skus = ['Хлопья овсяные 700г', 'Фруктовый0,5', 'Молоко 1л']
        print("\nПроверка тестовых SKU:")
        for sku in test_skus:
            cursor.execute("SELECT id FROM dim_products WHERE sku_ozon = %s", (sku,))
            result = cursor.fetchone()
            if result:
                print(f"  ✅ {sku} → ID: {result['id']}")
            else:
                print(f"  ❌ {sku} → НЕ НАЙДЕН")
        
        # 4. Проверяем права доступа
        cursor.execute("SHOW GRANTS FOR CURRENT_USER()")
        grants = cursor.fetchall()
        print("\nПрава текущего пользователя:")
        for grant in grants:
            print(f"  {list(grant.values())[0]}")
        
        cursor.close()
        connection.close()
        
        print("\n✅ Диагностика завершена")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка диагностики: {e}")
        return False

if __name__ == "__main__":
    check_dim_products()
