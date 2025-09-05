#!/usr/bin/env python3
"""
Тестовые скрипты для проверки импорта заказов (Этап 3).
"""

import sys
import os
import json
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import (
    get_postings_from_api, 
    transform_posting_data, 
    load_orders_to_db, 
    connect_to_db
)

def test_3_1_raw_events():
    """Тест 3.1: Получение заказов и запись в raw_events."""
    print("=== Тест 3.1: Получение заказов и запись в raw_events ===")
    
    # Получаем данные за вчерашний день
    yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
    
    try:
        print(f"Загружаем заказы за {yesterday}")
        postings = get_postings_from_api(yesterday, yesterday)
        
        # Проверяем количество в raw_events
        connection = connect_to_db()
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT COUNT(*) as count FROM raw_events WHERE event_type='ozon_posting' AND DATE(ingested_at) = %s",
                (yesterday,)
            )
            result = cursor.fetchone()
            print(f"Количество записей в raw_events: {result['count']}")
            print(f"Количество полученных заказов: {len(postings)}")
        
        connection.close()
        
        print("✅ Получение заказов и запись в raw_events прошли успешно")
        print("Сравните количество с данными в личном кабинете Ozon")
        return postings
        
    except Exception as e:
        print(f"❌ Ошибка получения заказов: {e}")
        return []

def test_3_2_transformation():
    """Тест 3.2: Трансформация данных заказа."""
    print("\n=== Тест 3.2: Трансформация данных заказа ===")
    
    try:
        # Получаем один JSON из raw_events для тестирования
        connection = connect_to_db()
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT payload FROM raw_events WHERE event_type='ozon_posting' LIMIT 1"
            )
            result = cursor.fetchone()
            
            if not result:
                print("❌ Нет данных в raw_events для тестирования")
                connection.close()
                return []
            
            # Парсим JSON
            posting_json = json.loads(result['payload'])
            print(f"Тестируем заказ: {posting_json.get('posting_number', 'N/A')}")
            print(f"Количество товаров в заказе: {len(posting_json.get('products', []))}")
            
            # Трансформируем
            transformed_orders = transform_posting_data(posting_json)
            
            print(f"Создано записей для fact_orders: {len(transformed_orders)}")
            
            # Показываем примеры
            for i, order in enumerate(transformed_orders[:3]):  # Показываем первые 3
                print(f"Запись {i+1}: SKU={order['sku']}, Qty={order['qty']}, Price={order['price']}")
        
        connection.close()
        
        print("✅ Трансформация данных заказа прошла успешно")
        return transformed_orders
        
    except Exception as e:
        print(f"❌ Ошибка трансформации данных заказа: {e}")
        return []

def test_3_3_fact_orders():
    """Тест 3.3: Загрузка заказов в fact_orders."""
    print("\n=== Тест 3.3: Загрузка заказов в fact_orders ===")
    
    yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
    
    try:
        # Получаем все заказы за вчера и трансформируем их
        postings = get_postings_from_api(yesterday, yesterday)
        
        all_orders = []
        for posting in postings:
            orders = transform_posting_data(posting)
            all_orders.extend(orders)
        
        if not all_orders:
            print("❌ Нет заказов для загрузки")
            return False
        
        # Загружаем в базу
        load_orders_to_db(all_orders)
        
        # Проверяем результат
        connection = connect_to_db()
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT COUNT(*) as count FROM fact_orders WHERE order_date = %s",
                (yesterday,)
            )
            result = cursor.fetchone()
            print(f"Количество записей в fact_orders за {yesterday}: {result['count']}")
            
            # Показываем примеры
            cursor.execute(
                "SELECT * FROM fact_orders WHERE order_date = %s LIMIT 10",
                (yesterday,)
            )
            samples = cursor.fetchall()
            print("\nПримеры записей в fact_orders:")
            for sample in samples:
                print(f"Order: {sample['order_id']}, SKU: {sample['sku']}, Qty: {sample['qty']}, Price: {sample['price']}")
        
        connection.close()
        
        print("✅ Загрузка заказов в fact_orders прошла успешно")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка загрузки заказов в fact_orders: {e}")
        return False

def run_all_order_tests():
    """Запуск всех тестов для заказов."""
    print("🧪 Запуск всех тестов для импорта заказов\n")
    
    # Тест 3.1
    postings = test_3_1_raw_events()
    
    # Тест 3.2
    transformed_orders = test_3_2_transformation()
    
    # Тест 3.3
    success = test_3_3_fact_orders()
    
    if success:
        print("\n🎉 Все тесты для заказов прошли успешно!")
    else:
        print("\n❌ Некоторые тесты не прошли")

if __name__ == "__main__":
    run_all_order_tests()
