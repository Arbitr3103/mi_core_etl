#!/usr/bin/env python3
"""
Простой тест для проверки работы v4 API с реальными данными.
"""

import os
import sys
import json
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_v4 import InventorySyncServiceV4
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

def test_real_v4_data():
    """Тест с реальными данными v4 API."""
    print("🔍 Тестируем v4 API с реальными данными...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Получаем данные через v4 API
        result = service.get_ozon_stocks_v4(limit=5)
        
        print(f"✅ Получено товаров: {len(result['items'])}")
        print(f"   Всего в системе: {result.get('total', 0)}")
        print(f"   Есть еще данные: {result['has_next']}")
        print(f"   Cursor: {result.get('cursor', 'N/A')}")
        
        # Обрабатываем данные
        stock_records = service.process_ozon_v4_stocks(result['items'])
        
        print(f"✅ Обработано записей остатков: {len(stock_records)}")
        
        # Показываем первые записи
        for i, record in enumerate(stock_records[:3]):
            print(f"\n📦 Товар {i+1}:")
            print(f"   Offer ID: {record.offer_id}")
            print(f"   Product ID: {record.product_id}")
            print(f"   SKU: {record.sku}")
            print(f"   Warehouse: {record.warehouse_name} (ID: {record.warehouse_id})")
            print(f"   Type: {record.stock_type}")
            print(f"   Present: {record.present}")
            print(f"   Reserved: {record.reserved}")
        
        # Конвертируем в inventory records
        inventory_records = service.convert_to_inventory_records(stock_records)
        
        print(f"\n✅ Конвертировано inventory записей: {len(inventory_records)}")
        
        # Показываем первую конвертированную запись
        if inventory_records:
            record = inventory_records[0]
            print(f"\n📋 Пример inventory записи:")
            print(f"   Product ID: {record.product_id}")
            print(f"   SKU: {record.sku}")
            print(f"   Source: {record.source}")
            print(f"   Warehouse: {record.warehouse_name}")
            print(f"   Stock Type: {record.stock_type}")
            print(f"   Current Stock: {record.current_stock}")
            print(f"   Reserved Stock: {record.reserved_stock}")
            print(f"   Available Stock: {record.available_stock}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")
        return False
    finally:
        service.close_database_connection()

def test_analytics_data():
    """Тест аналитических данных."""
    print("\n📊 Тестируем Analytics API с реальными данными...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Получаем аналитические данные
        result = service.get_ozon_analytics_stocks(limit=5)
        analytics_stocks = result["analytics_stocks"]
        
        print(f"✅ Получено аналитических записей: {len(analytics_stocks)}")
        print(f"   Всего доступно: {result['total_count']}")
        print(f"   Есть еще данные: {result['has_next']}")
        
        # Показываем первые записи
        for i, record in enumerate(analytics_stocks[:3]):
            print(f"\n📈 Аналитика {i+1}:")
            print(f"   SKU: {record.offer_id}")
            print(f"   Warehouse: {record.warehouse_name} (ID: {record.warehouse_id})")
            print(f"   Free to sell: {record.free_to_sell_amount}")
            print(f"   Promised: {record.promised_amount}")
            print(f"   Reserved: {record.reserved_amount}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")
        return False
    finally:
        service.close_database_connection()

def test_combined_mapping():
    """Тест объединения данных."""
    print("\n🗺️ Тестируем объединение данных...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Получаем данные из обоих источников
        main_result = service.get_ozon_stocks_v4(limit=3)
        main_stocks = service.process_ozon_v4_stocks(main_result['items'])
        
        analytics_result = service.get_ozon_analytics_stocks(limit=10)
        analytics_stocks = analytics_result["analytics_stocks"]
        
        print(f"✅ Основных записей: {len(main_stocks)}")
        print(f"✅ Аналитических записей: {len(analytics_stocks)}")
        
        # Создаем маппинг
        stock_mapping = service.create_stock_mapping(main_stocks, analytics_stocks)
        
        print(f"✅ Создан маппинг: {len(stock_mapping)} записей")
        
        # Анализируем маппинг
        main_only = sum(1 for v in stock_mapping.values() if not v["has_analytics_data"])
        analytics_only = sum(1 for v in stock_mapping.values() if v["main_present"] == 0 and v["has_analytics_data"])
        both_sources = sum(1 for v in stock_mapping.values() if v["main_present"] > 0 and v["has_analytics_data"])
        
        print(f"   📊 Только основной API: {main_only}")
        print(f"   📊 Только аналитический API: {analytics_only}")
        print(f"   📊 Оба источника: {both_sources}")
        
        # Показываем пример объединенной записи
        for key, data in list(stock_mapping.items())[:1]:
            print(f"\n🔗 Пример объединенной записи:")
            print(f"   Offer ID: {data['offer_id']}")
            print(f"   Warehouse: {data['warehouse_name']}")
            print(f"   Основной API - Present: {data['main_present']}, Reserved: {data['main_reserved']}")
            print(f"   Аналитика - Free to sell: {data['analytics_free_to_sell']}, Reserved: {data['analytics_reserved']}")
            print(f"   Есть аналитика: {data['has_analytics_data']}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")
        return False
    finally:
        service.close_database_connection()

if __name__ == "__main__":
    print("🚀 Простой тест v4 API с реальными данными")
    print("=" * 50)
    
    tests = [
        test_real_v4_data,
        test_analytics_data,
        test_combined_mapping
    ]
    
    results = []
    for test_func in tests:
        try:
            success = test_func()
            results.append(success)
        except Exception as e:
            print(f"❌ Критическая ошибка: {e}")
            results.append(False)
    
    print("\n" + "=" * 50)
    print(f"📋 Результат: {sum(results)}/{len(results)} тестов прошли успешно")
    
    if all(results):
        print("🎉 Все тесты прошли! v4 API работает корректно с реальными данными!")
    else:
        print("⚠️ Некоторые тесты не прошли.")