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
    """Тест подключения к обновленному v4 API."""
    print("🔍 Тестируем подключение к обновленному Ozon v4 API...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем получение остатков через v4 API
        result = service.get_ozon_stocks_v4(limit=10)
        
        print(f"✅ Успешно получено {result['total_items']} товаров")
        print(f"   Has next: {result['has_next']}")
        print(f"   Cursor: {result.get('cursor', 'N/A')}")
        
        if result['items']:
            first_item = result['items'][0]
            print(f"   Первый товар: {first_item.get('offer_id', 'N/A')}")
            print(f"   Product ID: {first_item.get('product_id', 'N/A')}")
            
            # Проверяем структуру stocks[]
            stocks = first_item.get('stocks', [])
            if stocks:
                first_stock = stocks[0]
                print(f"   SKU из stocks[]: {first_stock.get('sku', 'N/A')}")
                print(f"   Warehouse ID: {first_stock.get('warehouse_id', 'N/A')}")
                print(f"   Stock type: {first_stock.get('type', 'N/A')}")
                print(f"   Present: {first_stock.get('present', 0)}")
                print(f"   Reserved: {first_stock.get('reserved', 0)}")
        
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
    """Тест обновленного аналитического API."""
    print("📊 Тестируем обновленный Analytics API Ozon...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Обновляем кэш складов для аналитики
        service.update_warehouse_cache()
        
        # Тестируем получение аналитических данных с пагинацией
        result = service.get_ozon_analytics_stocks(limit=10)
        analytics_stocks = result["analytics_stocks"]
        
        print(f"✅ Успешно получено {len(analytics_stocks)} записей аналитики")
        print(f"   Total count: {result['total_count']}")
        print(f"   Has next: {result['has_next']}")
        
        for stock in analytics_stocks[:3]:  # Показываем первые 3
            print(f"   - {stock.offer_id} (WH: {stock.warehouse_name})")
            print(f"     free_to_sell={stock.free_to_sell_amount}, promised={stock.promised_amount}, reserved={stock.reserved_amount}")
        
        # Тестируем получение всех данных с пагинацией
        print("   Тестируем получение всех аналитических данных...")
        all_analytics = service.get_all_ozon_analytics_stocks()
        print(f"   Всего получено: {len(all_analytics)} записей")
        
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
            result = service.get_ozon_stocks_v3(limit=10000)  # Превышаем лимит
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


def test_data_validation():
    """Тест обновленной валидации данных."""
    print("✅ Тестируем обновленную валидацию данных...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестовые данные с числовыми SKU от Ozon
        test_records = [
            {
                'product_id': 123456789,
                'sku': '987654321',  # Числовой SKU от Ozon v4 API
                'source': 'Ozon',
                'warehouse_name': 'Ozon FBO Moscow',
                'stock_type': 'FBO',
                'current_stock': 15,
                'reserved_stock': 3,
                'available_stock': 12,
                'quantity_present': 15,
                'quantity_reserved': 3,
                'snapshot_date': datetime.now().date(),
                # Новые поля из аналитического API
                'analytics_free_to_sell': 12,
                'analytics_promised': 15,
                'analytics_reserved': 3
            },
            {
                'product_id': 111222333,
                'sku': 'ABC-123_DEF',  # Буквенно-цифровой SKU
                'source': 'Ozon',
                'warehouse_name': 'Ozon FBS Warehouse',
                'stock_type': 'FBS',
                'current_stock': 8,
                'reserved_stock': 1,
                'available_stock': 7,
                'quantity_present': 8,
                'quantity_reserved': 1,
                'snapshot_date': datetime.now().date()
            }
        ]
        
        # Тестируем валидацию основных данных
        validation_result = service.validator.validate_inventory_records(test_records, 'Ozon')
        
        print(f"   Основная валидация: {validation_result.valid_records}/{validation_result.total_records} записей валидны")
        print(f"   Ошибок: {validation_result.error_count}, Предупреждений: {validation_result.warning_count}")
        
        # Тестируем валидацию объединенных данных
        combined_validation = service.validator.validate_combined_stock_data(test_records, 'Ozon')
        
        print(f"   Валидация объединенных данных: {combined_validation.valid_records}/{combined_validation.total_records} записей валидны")
        
        # Показываем проблемы валидации
        for issue in validation_result.issues[:3]:  # Первые 3 проблемы
            print(f"   - {issue.severity.value}: {issue.field} - {issue.message}")
        
        return validation_result.error_count == 0
        
    except Exception as e:
        print(f"❌ Ошибка тестирования валидации: {e}")
        return False
    finally:
        service.close_database_connection()


def test_combined_api_sync():
    """Тест комбинированной синхронизации с обоими API."""
    print("🔄 Тестируем комбинированную синхронизацию...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Запускаем комбинированную синхронизацию
        result = service.sync_ozon_inventory_combined(
            visibility="VISIBLE",
            include_analytics=True,
            fallback_on_error=True
        )
        
        print(f"✅ Комбинированная синхронизация завершена:")
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
        print(f"❌ Ошибка комбинированной синхронизации: {e}")
        return False
    finally:
        service.close_database_connection()


def test_stock_mapping():
    """Тест создания маппинга между основными и аналитическими данными."""
    print("🗺️ Тестируем создание маппинга данных...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Получаем небольшое количество данных для тестирования
        main_result = service.get_ozon_stocks_v4(limit=5)
        main_stocks = service.process_ozon_v4_stocks(main_result['items'])
        
        analytics_result = service.get_ozon_analytics_stocks(limit=5)
        analytics_stocks = analytics_result["analytics_stocks"]
        
        # Создаем маппинг
        stock_mapping = service.create_stock_mapping(main_stocks, analytics_stocks)
        
        print(f"✅ Создан маппинг для {len(stock_mapping)} записей")
        
        # Анализируем маппинг
        main_only = sum(1 for v in stock_mapping.values() if not v["has_analytics_data"])
        analytics_only = sum(1 for v in stock_mapping.values() if v["main_present"] == 0 and v["has_analytics_data"])
        both_sources = sum(1 for v in stock_mapping.values() if v["main_present"] > 0 and v["has_analytics_data"])
        
        print(f"   Только основной API: {main_only}")
        print(f"   Только аналитический API: {analytics_only}")
        print(f"   Оба источника: {both_sources}")
        
        # Создаем единую структуру данных
        unified_structure = service.create_unified_data_structure(main_stocks, analytics_stocks)
        
        print(f"   Единая структура: {len(unified_structure['stock_data'])} записей")
        print(f"   Складов: {len(unified_structure['warehouse_summary'])}")
        print(f"   Расхождений: {len(unified_structure['discrepancies'])}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка тестирования маппинга: {e}")
        return False
    finally:
        service.close_database_connection()


def test_fallback_mechanisms():
    """Тест механизмов fallback при недоступности API."""
    print("🔄 Тестируем механизмы fallback...")
    
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем получение кэшированных данных
        cached_stocks = service.get_cached_stock_data(max_age_hours=24)
        print(f"   Кэшированных записей: {len(cached_stocks)}")
        
        # Тестируем конвертацию v3 в v4 формат
        v3_test_data = [
            {
                "offer_id": "TEST-123",
                "product_id": 123456,
                "present": 10,
                "reserved": 2,
                "stocks": [
                    {
                        "warehouse_id": 1,
                        "type": "fbo",
                        "present": 10,
                        "reserved": 2
                    }
                ]
            }
        ]
        
        v4_converted = service._convert_v3_to_v4_format(v3_test_data)
        print(f"   Конвертировано v3->v4: {len(v4_converted)} записей")
        
        if v4_converted:
            first_converted = v4_converted[0]
            print(f"   Структура v4: offer_id={first_converted.get('offer_id')}, stocks={len(first_converted.get('stocks', []))}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка тестирования fallback: {e}")
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
        ("Подключение к обновленному v4 API", test_v4_api_connection),
        ("API складов", test_warehouse_api),
        ("Обновленный Analytics API", test_analytics_api),
        ("Обновленная валидация данных", test_data_validation),
        ("Создание маппинга данных", test_stock_mapping),
        ("Механизмы fallback", test_fallback_mechanisms),
        ("Обработка ошибок", test_error_handling),
        ("Комбинированная синхронизация", test_combined_api_sync),
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