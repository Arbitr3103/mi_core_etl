#!/usr/bin/env python3
"""
Финальный тест интеграции v4 API с аналитическим API.

Проверяет:
1. Работу v4 API для получения основных остатков
2. Работу аналитического API для детализации по складам
3. Корректность валидации данных
4. Сохранение данных в БД с детализацией по складам
5. Объединение данных из разных источников

Автор: ETL System
Дата: 08 октября 2025
"""

import sys
import os
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(__file__))

from inventory_sync_service_v4 import InventorySyncServiceV4

def test_v4_api_integration():
    """Полный тест интеграции v4 API."""
    print("🧪 Финальный тест интеграции v4 API с аналитическим API")
    print("=" * 60)
    
    service = InventorySyncServiceV4()
    
    try:
        # Подключаемся к БД
        service.connect_to_database()
        
        # Тест 1: Основной v4 API
        print("\n1️⃣ Тестируем основной v4 API...")
        main_result = service.get_ozon_stocks_v4(limit=10)
        main_stocks = service.process_ozon_v4_stocks(main_result['items'])
        
        print(f"   ✅ Получено {len(main_stocks)} записей основных остатков")
        print(f"   📊 Всего товаров в API: {main_result.get('total', 0)}")
        print(f"   🔄 Есть еще данные: {main_result.get('has_next', False)}")
        
        # Тест 2: Аналитический API
        print("\n2️⃣ Тестируем аналитический API...")
        today = datetime.now().strftime('%Y-%m-%d')
        analytics_result = service.get_ozon_analytics_stocks(
            date_from=today,
            date_to=today,
            limit=50,
            offset=0
        )
        analytics_stocks = analytics_result.get("analytics_stocks", [])
        
        print(f"   ✅ Получено {len(analytics_stocks)} аналитических записей")
        
        # Анализируем склады
        warehouses = {}
        for stock in analytics_stocks:
            wh_name = stock.warehouse_name
            if wh_name not in warehouses:
                warehouses[wh_name] = 0
            warehouses[wh_name] += 1
        
        print(f"   🏭 Уникальных складов: {len(warehouses)}")
        top_warehouses = sorted(warehouses.items(), key=lambda x: x[1], reverse=True)[:5]
        for wh_name, count in top_warehouses:
            print(f"      - {wh_name}: {count} записей")
        
        # Тест 3: Валидация данных
        print("\n3️⃣ Тестируем валидацию данных...")
        
        # Конвертируем в формат для валидации
        inventory_records = service.convert_to_inventory_records(main_stocks)
        analytics_inventory_records = service.convert_analytics_to_inventory_records(analytics_stocks)
        
        # Валидируем основные данные
        main_validation = service.validate_inventory_data(inventory_records, 'Ozon')
        print(f"   ✅ Основные данные: {main_validation.valid_records}/{main_validation.total_records} валидны")
        print(f"      Ошибок: {main_validation.error_count}, Предупреждений: {main_validation.warning_count}")
        
        # Валидируем аналитические данные
        analytics_validation = service.validate_inventory_data(analytics_inventory_records, 'Ozon_Analytics')
        print(f"   ✅ Аналитические данные: {analytics_validation.valid_records}/{analytics_validation.total_records} валидны")
        print(f"      Ошибок: {analytics_validation.error_count}, Предупреждений: {analytics_validation.warning_count}")
        
        # Тест 4: Создание маппинга
        print("\n4️⃣ Тестируем создание маппинга...")
        stock_mapping = service.create_stock_mapping(main_stocks, analytics_stocks)
        
        main_only = sum(1 for v in stock_mapping.values() if not v["has_analytics_data"])
        analytics_only = sum(1 for v in stock_mapping.values() if v["main_present"] == 0 and v["has_analytics_data"])
        both_sources = sum(1 for v in stock_mapping.values() if v["main_present"] > 0 and v["has_analytics_data"])
        
        print(f"   ✅ Создан маппинг для {len(stock_mapping)} записей")
        print(f"      📈 Только основной API: {main_only}")
        print(f"      📊 Только аналитический API: {analytics_only}")
        print(f"      🔄 Оба источника: {both_sources}")
        
        # Тест 5: Проверка данных в БД
        print("\n5️⃣ Проверяем данные в БД...")
        
        # Проверяем основные данные
        service.cursor.execute("""
            SELECT COUNT(*) as count FROM inventory_data 
            WHERE source = 'Ozon' AND DATE(last_sync_at) = CURDATE()
        """)
        main_db_count = service.cursor.fetchone()['count']
        
        # Проверяем аналитические данные
        service.cursor.execute("""
            SELECT COUNT(*) as count FROM inventory_data 
            WHERE source = 'Ozon_Analytics' AND DATE(last_sync_at) = CURDATE()
        """)
        analytics_db_count = service.cursor.fetchone()['count']
        
        # Проверяем уникальные склады
        service.cursor.execute("""
            SELECT COUNT(DISTINCT warehouse_name) as count FROM inventory_data 
            WHERE source = 'Ozon_Analytics'
        """)
        unique_warehouses = service.cursor.fetchone()['count']
        
        print(f"   ✅ Основные данные в БД: {main_db_count} записей")
        print(f"   ✅ Аналитические данные в БД: {analytics_db_count} записей")
        print(f"   🏭 Уникальных складов в БД: {unique_warehouses}")
        
        # Тест 6: Полная синхронизация
        print("\n6️⃣ Тестируем полную синхронизацию...")
        sync_result = service.sync_ozon_inventory_combined()
        
        print(f"   ✅ Статус синхронизации: {sync_result.status.value}")
        print(f"   📊 Обработано записей: {sync_result.records_processed}")
        print(f"   ➕ Вставлено записей: {sync_result.records_inserted}")
        print(f"   🔄 Обновлено записей: {sync_result.records_updated}")
        print(f"   ❌ Ошибок: {sync_result.records_failed}")
        print(f"   ⏱️ Длительность: {sync_result.duration_seconds} сек")
        print(f"   🌐 API запросов: {sync_result.api_requests_count}")
        
        # Финальная проверка
        print("\n🎯 ФИНАЛЬНАЯ ПРОВЕРКА:")
        
        success_criteria = [
            (len(main_stocks) > 0, "Получены основные остатки"),
            (len(analytics_stocks) > 0, "Получены аналитические данные"),
            (len(warehouses) > 5, "Найдено достаточно складов"),
            (main_validation.error_count == 0, "Нет ошибок валидации основных данных"),
            (analytics_validation.error_count == 0, "Нет ошибок валидации аналитических данных"),
            (main_db_count > 0, "Основные данные сохранены в БД"),
            (analytics_db_count > 0, "Аналитические данные сохранены в БД"),
            (unique_warehouses > 5, "Сохранена детализация по складам"),
            (sync_result.status.value == "success", "Синхронизация успешна")
        ]
        
        passed = 0
        for condition, description in success_criteria:
            status = "✅" if condition else "❌"
            print(f"   {status} {description}")
            if condition:
                passed += 1
        
        print(f"\n🏆 РЕЗУЛЬТАТ: {passed}/{len(success_criteria)} тестов пройдено")
        
        if passed == len(success_criteria):
            print("🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ! Интеграция v4 API работает идеально!")
            return True
        else:
            print("⚠️ Некоторые тесты не пройдены. Требуется доработка.")
            return False
        
    except Exception as e:
        print(f"❌ Ошибка тестирования: {e}")
        import traceback
        traceback.print_exc()
        return False
    finally:
        service.close_database_connection()

if __name__ == "__main__":
    success = test_v4_api_integration()
    sys.exit(0 if success else 1)