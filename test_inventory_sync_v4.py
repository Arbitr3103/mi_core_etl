#!/usr/bin/env python3
"""
Тестовый скрипт для проверки работы улучшенного сервиса синхронизации с Ozon v4 API.

Автор: ETL System
Дата: 07 января 2025
"""

import os
import sys
import logging
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_v4 import InventorySyncServiceV4
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def test_v4_api_connection():
    """Тест подключения к v4 API."""
    print("🔍 Тестируем подключение к Ozon v4 API...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем получение остатков
        result = service.get_ozon_stocks_v4(limit=10)
        
        print(f"✅ Успешно получено {result['total_items']} товаров")
        print(f"   Has next: {result['has_next']}")
        print(f"   Last ID: {result['last_id']}")
        
        if result['items']:
            first_item = result['items'][0]
            print(f"   Первый товар: {first_item.get('offer_id', 'N/A')}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка подключения к v4 API: {e}")
        return False
    finally:
        service.close_database_connection()


def test_warehouse_api():
    """Тест API складов."""
    print("🏪 Тестируем API складов Ozon...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем получение складов
        warehouses = service.get_ozon_warehouses()
        
        print(f"✅ Успешно получено {len(warehouses)} складов")
        
        for warehouse in warehouses[:3]:  # Показываем первые 3
            print(f"   - {warehouse.warehouse_name} (ID: {warehouse.warehouse_id}, Type: {warehouse.warehouse_type})")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка получения складов: {e}")
        return False
    finally:
        service.close_database_connection()


def test_analytics_api():
    """Тест аналитического API."""
    print("📊 Тестируем Analytics API Ozon...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Обновляем кэш складов для аналитики
        service.update_warehouse_cache()
        
        # Тестируем получение аналитических данных
        analytics_stocks = service.get_ozon_analytics_stocks()
        
        print(f"✅ Успешно получено {len(analytics_stocks)} записей аналитики")
        
        for stock in analytics_stocks[:3]:  # Показываем первые 3
            print(f"   - {stock.offer_id}: free_to_sell={stock.free_to_sell_amount}, reserved={stock.reserved_amount}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка получения аналитики: {e}")
        return False
    finally:
        service.close_database_connection()


def test_error_handling():
    """Тест обработки ошибок."""
    print("⚠️ Тестируем обработку ошибок...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем с неверными параметрами
        try:
            result = service.get_ozon_stocks_v4(limit=10000)  # Превышаем лимит
            print("❌ Ожидалась ошибка валидации")
            return False
        except Exception as e:
            print(f"✅ Корректно обработана ошибка: {type(e).__name__}")
        
        # Тестируем retry логику
        print("   Тестируем retry логику...")
        service.max_retries = 2  # Уменьшаем для быстрого теста
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка тестирования обработки ошибок: {e}")
        return False
    finally:
        service.close_database_connection()


def test_full_sync():
    """Тест полной синхронизации."""
    print("🔄 Тестируем полную синхронизацию...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Запускаем синхронизацию с ограничением
        result = service.sync_ozon_inventory_v4(visibility="VISIBLE")
        
        print(f"✅ Синхронизация завершена:")
        print(f"   Статус: {result.status.value}")
        print(f"   Обработано: {result.records_processed}")
        print(f"   Вставлено: {result.records_inserted}")
        print(f"   Ошибок: {result.records_failed}")
        print(f"   API запросов: {result.api_requests_count}")
        print(f"   Время выполнения: {result.duration_seconds} сек")
        
        if result.error_message:
            print(f"   Сообщение: {result.error_message}")
        
        return result.status.value != "failed"
        
    except Exception as e:
        print(f"❌ Ошибка полной синхронизации: {e}")
        return False
    finally:
        service.close_database_connection()


def main():
    """Основная функция тестирования."""
    print("🚀 Запуск тестов Ozon v4 API интеграции")
    print("=" * 50)
    
    # Проверяем конфигурацию
    if not config.OZON_CLIENT_ID or not config.OZON_API_KEY:
        print("❌ Не настроены API ключи Ozon")
        return
    
    tests = [
        ("Подключение к v4 API", test_v4_api_connection),
        ("API складов", test_warehouse_api),
        ("Analytics API", test_analytics_api),
        ("Обработка ошибок", test_error_handling),
        ("Полная синхронизация", test_full_sync),
    ]
    
    results = []
    
    for test_name, test_func in tests:
        print(f"\n{test_name}:")
        print("-" * 30)
        
        try:
            success = test_func()
            results.append((test_name, success))
        except Exception as e:
            print(f"❌ Критическая ошибка в тесте: {e}")
            results.append((test_name, False))
    
    # Выводим итоги
    print("\n" + "=" * 50)
    print("📋 ИТОГИ ТЕСТИРОВАНИЯ:")
    
    passed = 0
    for test_name, success in results:
        status = "✅ PASSED" if success else "❌ FAILED"
        print(f"   {test_name}: {status}")
        if success:
            passed += 1
    
    print(f"\nПройдено тестов: {passed}/{len(results)}")
    
    if passed == len(results):
        print("🎉 Все тесты пройдены успешно!")
    else:
        print("⚠️ Некоторые тесты не прошли. Проверьте логи.")


if __name__ == "__main__":
    main()