#!/usr/bin/env python3
"""
Тестовые скрипты для проверки импорта товаров (Этап 2).
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import get_products_from_api, transform_product_data, load_products_to_db, connect_to_db

def test_2_1_api_request():
    """Тест 2.1: Запрос к API для получения товаров."""
    print("=== Тест 2.1: Получение товаров из API ===")
    
    try:
        products = get_products_from_api()
        print(f"✅ Получено товаров из API: {len(products)}")
        print("Сравните это число с количеством товаров в личном кабинете Ozon")
        return products
        
    except Exception as e:
        print(f"❌ Ошибка получения товаров из API: {e}")
        return []

def test_2_2_transformation(products):
    """Тест 2.2: Трансформация данных товара."""
    print("\n=== Тест 2.2: Трансформация данных товара ===")
    
    if not products:
        print("❌ Нет товаров для тестирования трансформации")
        return []
    
    try:
        # Берем первый товар для тестирования
        sample_product = products[0]
        print(f"Исходные данные товара: {sample_product}")
        
        # Трансформируем
        transformed = transform_product_data(sample_product)
        print(f"Преобразованные данные: {transformed}")
        
        # Проверяем наличие всех необходимых полей
        required_fields = ['sku_ozon', 'barcode', 'product_name', 'cost_price']
        missing_fields = [field for field in required_fields if field not in transformed]
        
        if missing_fields:
            print(f"❌ Отсутствуют поля: {missing_fields}")
            return []
        
        print("✅ Трансформация данных прошла успешно")
        
        # Трансформируем все товары
        transformed_products = [transform_product_data(product) for product in products]
        return transformed_products
        
    except Exception as e:
        print(f"❌ Ошибка трансформации данных: {e}")
        return []

def test_2_3_database_load(transformed_products):
    """Тест 2.3: Загрузка товаров в базу данных."""
    print("\n=== Тест 2.3: Загрузка товаров в базу данных ===")
    
    if not transformed_products:
        print("❌ Нет преобразованных товаров для загрузки")
        return False
    
    try:
        # Загружаем товары в базу
        load_products_to_db(transformed_products)
        
        # Проверяем результат в базе данных
        connection = connect_to_db()
        with connection.cursor() as cursor:
            cursor.execute("SELECT COUNT(*) as count FROM dim_products")
            result = cursor.fetchone()
            print(f"Количество товаров в базе данных: {result['count']}")
            
            # Показываем несколько примеров
            cursor.execute("SELECT * FROM dim_products LIMIT 10")
            samples = cursor.fetchall()
            print("\nПримеры товаров в базе данных:")
            for sample in samples:
                print(f"ID: {sample['id']}, SKU: {sample['sku_ozon']}, Название: {sample['product_name'][:50]}...")
        
        connection.close()
        print("✅ Загрузка товаров в базу данных прошла успешно")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка загрузки товаров в базу данных: {e}")
        return False

def run_all_product_tests():
    """Запуск всех тестов для товаров."""
    print("🧪 Запуск всех тестов для импорта товаров\n")
    
    # Тест 2.1
    products = test_2_1_api_request()
    
    # Тест 2.2
    transformed_products = test_2_2_transformation(products)
    
    # Тест 2.3
    success = test_2_3_database_load(transformed_products)
    
    if success:
        print("\n🎉 Все тесты для товаров прошли успешно!")
    else:
        print("\n❌ Некоторые тесты не прошли")

if __name__ == "__main__":
    run_all_product_tests()
