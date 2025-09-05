#!/usr/bin/env python3
"""
Тестовые скрипты для проверки импорта товаров через новый API отчетов (обновленный Этап 2).
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import get_products_from_api, transform_product_data, load_products_to_db, connect_to_db

def test_2_1_api_request():
    """Тест 2.1: Получение товаров через новый API отчетов."""
    print("=== Тест 2.1: Получение товаров через API отчетов ===")
    
    try:
        products = get_products_from_api()
        print(f"✅ Получено товаров из API: {len(products)}")
        print("Сравните это число с количеством товаров в личном кабинете Ozon")
        
        # Показываем структуру первого товара
        if products:
            print("\nСтруктура первого товара из CSV:")
            sample_product = products[0]
            for key, value in list(sample_product.items())[:10]:  # Показываем первые 10 полей
                print(f"  {key}: {value}")
            print("  ...")
        
        return products
        
    except Exception as e:
        print(f"❌ Ошибка получения товаров из API: {e}")
        return []

def test_2_2_transformation(products):
    """Тест 2.2: Трансформация данных товара из CSV."""
    print("\n=== Тест 2.2: Трансформация данных товара из CSV ===")
    
    if not products:
        print("❌ Нет товаров для тестирования трансформации")
        return []
    
    try:
        # Берем первый товар для тестирования
        sample_product = products[0]
        print("Исходные данные товара (ключевые поля):")
        key_fields = ['Артикул', 'Barcode', 'Название товара', 'Ozon Product ID', 'SKU']
        for field in key_fields:
            print(f"  {field}: {sample_product.get(field, 'НЕТ')}")
        
        # Трансформируем
        transformed = transform_product_data(sample_product)
        print(f"\nПреобразованные данные: {transformed}")
        
        # Проверяем наличие всех необходимых полей
        required_fields = ['sku_ozon', 'barcode', 'product_name', 'cost_price']
        missing_fields = [field for field in required_fields if field not in transformed]
        
        if missing_fields:
            print(f"❌ Отсутствуют поля: {missing_fields}")
            return []
        
        # Проверяем, что поля не пустые (кроме cost_price)
        empty_fields = []
        for field in ['sku_ozon', 'barcode', 'product_name']:
            if not transformed[field]:
                empty_fields.append(field)
        
        if empty_fields:
            print(f"⚠️  Пустые поля: {empty_fields}")
        
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
        cursor = connection.cursor()
        
        cursor.execute("SELECT COUNT(*) FROM dim_products")
        result = cursor.fetchone()
        print(f"Количество товаров в базе данных: {result[0]}")
        
        # Показываем несколько примеров
        cursor.execute("SELECT id, sku_ozon, product_name FROM dim_products LIMIT 10")
        samples = cursor.fetchall()
        print("\nПримеры товаров в базе данных:")
        for sample in samples:
            product_name = sample[2][:50] + "..." if len(sample[2]) > 50 else sample[2]
            print(f"ID: {sample[0]}, SKU: {sample[1]}, Название: {product_name}")
        
        cursor.close()
        connection.close()
        print("✅ Загрузка товаров в базу данных прошла успешно")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка загрузки товаров в базу данных: {e}")
        return False

def run_all_product_tests():
    """Запуск всех тестов для товаров."""
    print("🧪 Запуск всех тестов для импорта товаров через новый API отчетов\n")
    
    # Тест 2.1: Получение через API отчетов
    products = test_2_1_api_request()
    
    # Тест 2.2: Трансформация из CSV
    transformed_products = test_2_2_transformation(products)
    
    # Тест 2.3: Загрузка в БД
    success = test_2_3_database_load(transformed_products)
    
    if success:
        print("\n🎉 Все тесты для товаров прошли успешно!")
        print("Новый API отчетов работает корректно!")
    else:
        print("\n❌ Некоторые тесты не прошли")

if __name__ == "__main__":
    run_all_product_tests()
